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

        return $this->issue($user, $deviceId ?? $row->device_id, $userAgent);
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
     * @return array<string,mixed>
     */
    private function issue(User $user, ?string $deviceId, ?string $userAgent): array
    {
        $organizationId = (int) $user->organization_id;

        $role = Role::acrossOrganizations()->where('roles.id', (int) $user->role_id)->first();
        $roleKey = $role === null ? '' : (string) $role->key;

        $accessToken = $this->tokens->issue((int) $user->id, $organizationId, $roleKey);

        $refreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Capsule::table('refresh_tokens')->insert([
            'user_id'    => (int) $user->id,
            'token_hash' => hash('sha256', $refreshToken),
            'device_id'  => $deviceId === null ? null : mb_substr($deviceId, 0, 100),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->refreshTtlSeconds),
        ]);

        User::acrossOrganizations()
            ->where('users.id', (int) $user->id)
            ->update(['last_login_at' => gmdate('Y-m-d H:i:s')]);

        $organization = Organization::query()->where('id', $organizationId)->first();

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
