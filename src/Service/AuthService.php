<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\AuthException;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Signing in, and staying signed in.
 *
 * Two things shape this class more than anything else.
 *
 * The first is that a device goes offline for days. The access token is short
 * and the refresh token is long, and NOTHING here ever deletes local work — an
 * expired session is a reason to ask for a password, never a reason to clear a
 * draft. The client enforces its half of that; this class simply makes the
 * refresh path always available.
 *
 * The second is that email is unique per organisation, not globally, because
 * the same person may legitimately hold accounts in two organisations on a
 * shared installation. So a sign-in without an organisation code may match more
 * than one account. Candidates are resolved by verifying the password against
 * each one rather than by asking the caller which organisation they meant:
 * asking first would confirm that an address exists here, which is exactly what
 * an attacker without a password wants to know.
 */
final class AuthService
{
    /**
     * Verified against when no account matches, so a missing address costs the
     * same time as a wrong password. Without it, sign-in latency alone reports
     * who holds an account.
     */
    private const DUMMY_HASH = '$2y$12$h1c4C9R1f99A6nA1G6dMh.mSD22jZKqxkFz3IhewBG2dp0EADx20m';

    private const MAX_FAILURES = 10;

    private const THROTTLE_WINDOW_SECONDS = 900;

    /**
     * A password check costs ~100ms, so the candidate list is capped: without a
     * bound, one address registered across many organisations would turn every
     * sign-in attempt into a slow request that someone else pays for.
     */
    private const MAX_CANDIDATES = 5;

    private readonly int $refreshTtlSeconds;

    public function __construct(
        private readonly TokenService $tokens = new TokenService(),
        ?int $refreshTtlSeconds = null,
    ) {
        $this->refreshTtlSeconds = $refreshTtlSeconds ?? max(60, (int) env('JWT_REFRESH_TTL', 2592000));
    }

    /**
     * @return array<string,mixed>
     *
     * @throws AuthException
     */
    public function login(
        string $email,
        string $password,
        ?string $organizationCode,
        ?string $deviceId,
        string $ip,
        ?string $userAgent = null,
    ): array {
        $email = mb_strtolower(trim($email));

        $this->guardThrottle($email, $ip);

        $candidates = $this->candidates($email, $organizationCode);
        $matched = [];

        foreach ($candidates as $candidate) {
            if (password_verify($password, (string) $candidate->password_hash)) {
                $matched[] = $candidate;
            }
        }

        if ($matched === []) {
            // Burn the same time as a real check before failing.
            password_verify($password, self::DUMMY_HASH);
            $this->record($email, $ip, false, null);

            throw AuthException::invalidCredentials();
        }

        if (count($matched) > 1) {
            $this->record($email, $ip, false, null);

            throw AuthException::organizationRequired();
        }

        $user = $matched[0];
        $organizationId = (int) $user->organization_id;

        if (!$user->is_active) {
            $this->record($email, $ip, false, $organizationId);

            throw AuthException::inactive();
        }

        $this->rehashIfNeeded($user, $password);
        $this->record($email, $ip, true, $organizationId);

        return $this->issue($user, $deviceId, $userAgent);
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * The old token is revoked as the new one is issued. A token presented
     * twice therefore arrives already revoked, which is treated as theft rather
     * than as a mistake: every session for that user is revoked, because the
     * one thing worse than signing someone out is leaving a copied token live.
     *
     * @return array<string,mixed>
     *
     * @throws AuthException
     */
    public function refresh(string $refreshToken, ?string $deviceId = null, ?string $userAgent = null): array
    {
        $hash = hash('sha256', $refreshToken);

        $row = Capsule::table('refresh_tokens')->where('token_hash', $hash)->first();

        if ($row === null || $row->user_id === null) {
            throw AuthException::refreshRejected();
        }

        if ($row->revoked_at !== null) {
            $this->revokeAllFor((int) $row->user_id);

            throw AuthException::refreshRejected();
        }

        if (strtotime((string) $row->expires_at) <= time()) {
            throw AuthException::refreshRejected();
        }

        $user = User::acrossOrganizations()->where('users.id', (int) $row->user_id)->first();

        if ($user === null || !$user->is_active) {
            throw AuthException::refreshRejected();
        }

        Capsule::table('refresh_tokens')
            ->where('id', $row->id)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);

        // The session carries across the rotation. A refresh mints a new
        // token and revokes the old one every fifteen minutes; a session
        // identifier derived from the token would change with it, which is
        // exactly the continuity it exists to provide.
        return $this->issue(
            $user,
            $deviceId ?? $row->device_id,
            $userAgent,
            $row->session_hash === null ? null : (string) $row->session_hash,
        );
    }

    /** Signing out is best effort: an unknown token is already not usable. */
    public function logout(string $refreshToken): void
    {
        Capsule::table('refresh_tokens')
            ->where('token_hash', hash('sha256', $refreshToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return list<User>
     */
    private function candidates(string $email, ?string $organizationCode): array
    {
        $query = User::acrossOrganizations()->where('users.email', $email);

        if ($organizationCode !== null && $organizationCode !== '') {
            $organization = Organization::query()->where('code', $organizationCode)->first();

            if ($organization === null) {
                return [];
            }

            $query->where('users.organization_id', (int) $organization->id);
        }

        // Applied to the underlying query rather than chained: limit() reaches
        // Eloquent through __call, which degrades the builder to a query
        // builder and leaves what get() returns unverifiable.
        $query->getQuery()->limit(self::MAX_CANDIDATES);

        return array_values($query->get()->all());
    }

    /**
     * The floor, matching what bin/provision-org and bin/dev/create-user insist
     * on. A rule enforced in one of three places is not a rule.
     */
    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * Change your own password.
     *
     * The current password is required even though the caller already holds a
     * valid token. A token can be a borrowed tablet left signed in on a bench;
     * knowing the password is the thing that says this is the account holder,
     * and without it an unattended device is a permanent account takeover.
     *
     * EVERY SESSION IS REVOKED, including this one, and a fresh pair issued in
     * return. If the reason for the change was that somebody else had the old
     * password, leaving their session alive makes the change decorative — which
     * is the one thing a password change must never be.
     *
     * @return array<string,mixed> a new token pair; the caller stays signed in here
     *
     * @throws AuthException
     */
    public function changePassword(
        int $userId,
        string $current,
        string $next,
        ?string $deviceId = null,
        ?string $userAgent = null,
    ): array {
        $user = User::acrossOrganizations()->where('users.id', $userId)->first();

        if (!$user instanceof User || !$user->is_active) {
            throw AuthException::invalidCredentials();
        }

        if (!password_verify($current, (string) $user->password_hash)) {
            throw AuthException::invalidCredentials();
        }

        $this->guardNewPassword($current, $next);

        Capsule::connection()->transaction(function () use ($user, $next): void {
            User::acrossOrganizations()
                ->where('users.id', (int) $user->id)
                ->update([
                    'password_hash'        => password_hash($next, PASSWORD_DEFAULT),
                    'must_change_password' => 0,
                ]);

            $this->revokeAllFor((int) $user->id);
        });

        // Re-read so the pair below is minted from the stored state rather than
        // from the in-memory copy, which still says the password must change.
        $updated = User::acrossOrganizations()->where('users.id', $userId)->first();

        return $this->issue($updated instanceof User ? $updated : $user, $deviceId, $userAgent);
    }

    /** @throws AuthException */
    private function guardNewPassword(string $current, string $next): void
    {
        if (mb_strlen($next) < self::MIN_PASSWORD_LENGTH) {
            throw AuthException::passwordUnacceptable(
                sprintf('Use at least %d characters.', self::MIN_PASSWORD_LENGTH),
            );
        }

        // Measured in bytes: bcrypt truncates silently at 72, so anything past
        // that is not part of the password however long it looks.
        if (strlen($next) > 72) {
            throw AuthException::passwordUnacceptable('Use 72 characters or fewer.');
        }

        if (trim($next) === '') {
            throw AuthException::passwordUnacceptable('Use something other than spaces.');
        }

        if ($next === $current) {
            throw AuthException::passwordUnacceptable('Choose a password you have not just used.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(
        User $user,
        ?string $deviceId,
        ?string $userAgent,
        ?string $sessionHash = null,
    ): array {
        /**
         * One value per sign-in, random rather than derived.
         *
         * Deriving it from the refresh token would make a log line enough to
         * reconstruct the token it came from, which turns a debugging aid into
         * a credential. This is a name for a session and nothing else: it
         * cannot be used to become one.
         */
        $sessionHash ??= bin2hex(random_bytes(32));

        $organizationId = (int) $user->organization_id;

        $role = Role::acrossOrganizations()->where('roles.id', (int) $user->role_id)->first();
        $roleKey = $role === null ? '' : (string) $role->key;

        $organization = Organization::query()->where('id', $organizationId)->first();

        $accessToken = $this->tokens->issue(
            (int) $user->id,
            $organizationId,
            $roleKey,
            false,
            (bool) $user->must_change_password,
            $organization === null ? null : (int) $organization->programme_id,
            $sessionHash,
        );

        $refreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Capsule::table('refresh_tokens')->insert([
            'user_id'    => (int) $user->id,
            'token_hash' => hash('sha256', $refreshToken),
            'session_hash' => $sessionHash,
            'device_id'  => $deviceId === null ? null : mb_substr($deviceId, 0, 100),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->refreshTtlSeconds),
        ]);

        User::acrossOrganizations()
            ->where('users.id', (int) $user->id)
            ->update(['last_login_at' => gmdate('Y-m-d H:i:s')]);

        return [
            'access_token'  => $accessToken,
            'expires_in'    => $this->tokens->ttlSeconds(),
            'refresh_token' => $refreshToken,
            'user'          => [
                'id'                   => (int) $user->id,
                'email'                => (string) $user->email,
                'full_name'            => (string) $user->full_name,
                'role'                 => $roleKey,
                'organization_id'      => $organizationId,
                'organization'         => $organization === null ? null : (string) $organization->name,
                'must_change_password' => (bool) $user->must_change_password,
            ],
        ];
    }

    /**
     * Move a password to the current hashing cost on the one occasion the plain
     * text is available. Failing quietly is correct: the sign-in succeeded, and
     * an unwritable rehash is not a reason to refuse it.
     */
    private function rehashIfNeeded(User $user, string $password): void
    {
        if (!password_needs_rehash((string) $user->password_hash, PASSWORD_DEFAULT)) {
            return;
        }

        User::acrossOrganizations()
            ->where('users.id', (int) $user->id)
            ->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    }

    /**
     * @throws AuthException
     */
    private function guardThrottle(string $email, string $ip): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::THROTTLE_WINDOW_SECONDS);

        // Counted per address AND per source, so one person locking themselves
        // out cannot lock out an office sharing an address, and a spray across
        // many addresses from one source still trips.
        $failures = Capsule::table('login_attempts')
            ->where('succeeded', 0)
            ->where('attempted_at', '>=', $since)
            ->where(function ($query) use ($email, $ip): void {
                $query->where('email', $email)->orWhere('ip_address', $ip);
            })
            ->count();

        if ($failures < self::MAX_FAILURES) {
            return;
        }

        $oldest = Capsule::table('login_attempts')
            ->where('succeeded', 0)
            ->where('attempted_at', '>=', $since)
            ->where(function ($query) use ($email, $ip): void {
                $query->where('email', $email)->orWhere('ip_address', $ip);
            })
            ->min('attempted_at');

        $retryAfter = $oldest === null
            ? self::THROTTLE_WINDOW_SECONDS
            : max(1, strtotime((string) $oldest) + self::THROTTLE_WINDOW_SECONDS - time());

        throw AuthException::throttled($retryAfter);
    }

    private function record(string $email, string $ip, bool $succeeded, ?int $organizationId): void
    {
        Capsule::table('login_attempts')->insert([
            'organization_id' => $organizationId,
            'email'           => mb_substr($email, 0, 255),
            'ip_address'      => mb_substr($ip, 0, 45),
            'succeeded'       => $succeeded ? 1 : 0,
        ]);
    }

    private function revokeAllFor(int $userId): void
    {
        Capsule::table('refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }
}
