<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

/**
 * Managing the people in an organisation.
 *
 * This is what makes bin/recover-access break-glass rather than routine: with
 * these endpoints an administrator adds an assessor, promotes a colleague or
 * resets a forgotten password without anyone needing a shell.
 *
 * ONE GUARD MATTERS MORE THAN THE REST: you cannot demote or deactivate
 * YOURSELF. Changing your own role while tidying up is the commonest way to
 * leave an organisation with nobody able to administer it, which is exactly
 * the situation bin/recover-access exists to dig out of.
 *
 * That guard is sufficient on its own, and the reasoning is worth writing down
 * because it is not obvious. Reaching these routes at all requires an active
 * administrative role, and the guard forbids acting on yourself — so whoever
 * is demoting somebody is themselves an administrator who remains one
 * afterwards. There is no sequence of calls that empties the role. A separate
 * "not the last administrator" check was written and then removed: it could
 * not be reached, and could not be tested, which is the same thing said twice.
 *
 * THAT REASONING DEPENDS ON THE ACTOR BEING AN ADMINISTRATOR OF THIS
 * ORGANISATION. If a platform-level or cross-organisation path to these
 * methods is ever added, the last-administrator check has to come back with
 * it.
 *
 * Only a superadmin may create another superadmin. An administrator who could
 * mint one holds the role in all but name, and the distinction stops meaning
 * anything.
 *
 * AND NOBODY MAY ACT ON SOMEBODY WHO OUTRANKS THEM. Guarding which role may be
 * handed out looked like the whole of that rule and is only half of it: the
 * three ways an administrator could have taken a superadmin's place hand out
 * no role at all. Resetting their password takes the account directly, since
 * the new one comes back in the response. Demoting or deactivating them takes
 * the organisation by removing the only person above the actor — the same end
 * reached from the other side. So the guard is on the TARGET, and it covers
 * every mutation rather than only the ones that name a role.
 */
final class UserAdminService
{
    /** Assignable by an administrator. Superadmin is deliberately absent. */
    private const ADMIN_ASSIGNABLE = ['admin', 'assessor', 'viewer', 'site_user'];

    private const SUPERADMIN = 'superadmin';

    /** The roles that can administer an organisation, for the last-one-out guard. */
    private const ADMINISTRATIVE = ['admin', 'superadmin'];

    /**
     * Who may act on whom. Higher acts on equal or lower, never on higher.
     *
     * Equal ranks administer each other on purpose: two administrators
     * covering for one another over a weekend is the ordinary case, and a rule
     * that stopped it would send them to bin/recover-access instead.
     *
     * A role missing from this map ranks below everything, which is the safe
     * direction — a role added later and forgotten here can be administered
     * but cannot administer.
     */
    private const RANK = ['superadmin' => 2, 'admin' => 1];

    /** @var array{byId: array<int,string>, byKey: array<string,int>}|null */
    private ?array $roleCache = null;

    /**
     * Everyone in the organisation, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $roles = $this->rolesByIdAndKey();
        $users = [];

        $query = User::query();
        $query->getQuery()->orderBy('full_name');

        foreach ($query->get() as $user) {
            $users[] = [
                'id'                   => (int) $user->id,
                'email'                => (string) $user->email,
                'full_name'            => (string) $user->full_name,
                'title'                => $user->title,
                'phone'                => $user->phone,
                'role'                 => $roles['byId'][(int) $user->role_id] ?? '',
                'is_active'            => (bool) $user->is_active,
                'must_change_password' => (bool) $user->must_change_password,
                'last_login_at'        => $user->last_login_at?->format('c'),
            ];
        }

        return $users;
    }

    /**
     * Add somebody, with a password they will have to replace.
     *
     * @param  array<string,mixed> $input
     * @return array{user: array<string,mixed>, password: string}
     */
    public function create(array $input, string $actorRole): array
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['full_name'] ?? ''));
        $roleKey = trim((string) ($input['role'] ?? ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('That does not look like an email address.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('A full name is required.');
        }

        $roleId = $this->resolveAssignableRole($roleKey, $actorRole);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            throw new InvalidArgumentException('Somebody with that address is already in this organisation.');
        }

        // Base64url of 18 bytes: 24 characters, nothing ambiguous to read out.
        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

        $user = new User();
        $user->fill([
            'organization_id'      => TenantContext::requireOrganizationId(),
            'role_id'              => $roleId,
            'email'                => $email,
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'full_name'            => $name,
            'title'                => $this->optional($input, 'title'),
            'phone'                => $this->optional($input, 'phone'),
            'is_active'            => 1,
            // Somebody else chose it and has seen it, so it is a shared secret
            // until replaced — and now there is a screen to replace it on.
            'must_change_password' => 1,
        ]);
        $user->save();

        return ['user' => $this->one((int) $user->id), 'password' => $password];
    }

    /**
     * Change a role, a name, or whether the account works.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(int $userId, array $input, int $actorId, string $actorRole): array
    {
        $user = User::query()->where('users.id', $userId)->first();

        if (!$user instanceof User) {
            throw new InvalidArgumentException('No such user in this organisation.');
        }

        $this->requireOutranks($actorRole, $user);

        $attributes = [];

        if (array_key_exists('full_name', $input)) {
            $name = trim((string) $input['full_name']);

            if ($name === '') {
                throw new InvalidArgumentException('A full name is required.');
            }

            $attributes['full_name'] = $name;
        }

        foreach (['title', 'phone'] as $field) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $this->optional($input, $field);
            }
        }

        if (array_key_exists('role', $input)) {
            $attributes['role_id'] = $this->resolveAssignableRole((string) $input['role'], $actorRole);
        }

        if (array_key_exists('is_active', $input)) {
            $attributes['is_active'] = (bool) $input['is_active'] ? 1 : 0;
        }

        $this->guardAdministrativeCover($user, $attributes, $actorId);

        if ($attributes !== []) {
            User::query()->where('users.id', $userId)->update($attributes);
        }

        return $this->one($userId);
    }

    /**
     * Give somebody a new password because they cannot sign in.
     *
     * Revokes their sessions as it goes, for the same reason changing your own
     * does: if the reason for the reset was that somebody else had the old
     * password, leaving their session alive makes the reset decorative.
     *
     * @return array{user: array<string,mixed>, password: string}
     */
    public function resetPassword(int $userId, string $actorRole): array
    {
        $user = User::query()->where('users.id', $userId)->first();

        if (!$user instanceof User) {
            throw new InvalidArgumentException('No such user in this organisation.');
        }

        $this->requireOutranks($actorRole, $user);

        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

        Capsule::connection()->transaction(function () use ($userId, $password): void {
            User::query()->where('users.id', $userId)->update([
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password' => 1,
            ]);

            Capsule::table('refresh_tokens')
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
        });

        return ['user' => $this->one($userId), 'password' => $password];
    }

    /**
     * Refuse the change that locks somebody out of their own organisation.
     *
     * Only ever about the actor themselves — see the note on the class for why
     * that is enough, and for the condition under which it stops being enough.
     *
     * @param array<string,mixed> $attributes
     */
    private function guardAdministrativeCover(User $user, array $attributes, int $actorId): void
    {
        if ((int) $user->id !== $actorId) {
            return;
        }

        $losingRole = array_key_exists('role_id', $attributes)
            && !$this->isAdministrative((int) $attributes['role_id']);
        $beingSwitchedOff = array_key_exists('is_active', $attributes) && $attributes['is_active'] === 0;

        if ($losingRole || $beingSwitchedOff) {
            throw new InvalidArgumentException(
                'You cannot remove your own access. Ask another administrator.',
            );
        }
    }

    private function isAdministrative(int $roleId): bool
    {
        return in_array(
            $this->rolesByIdAndKey()['byId'][$roleId] ?? '',
            self::ADMINISTRATIVE,
            true,
        );
    }

    /** @throws InvalidArgumentException */
    /**
     * The actor has to stand at least as high as the person they are changing.
     *
     * Read off the TARGET'S CURRENT role, never off what the request asks for.
     * A demotion asks for a lower role, so checking the requested one would
     * wave through exactly the call this exists to stop.
     *
     * @throws InvalidArgumentException
     */
    private function requireOutranks(string $actorRole, User $target): void
    {
        $targetRole = $this->rolesByIdAndKey()['byId'][(int) $target->role_id] ?? '';

        if ((self::RANK[$actorRole] ?? 0) < (self::RANK[$targetRole] ?? 0)) {
            throw new InvalidArgumentException(
                'Only a ' . $targetRole . ' can change another ' . $targetRole . "'s account.",
            );
        }
    }

    private function resolveAssignableRole(string $roleKey, string $actorRole): int
    {
        if ($roleKey === self::SUPERADMIN && $actorRole !== self::SUPERADMIN) {
            throw new InvalidArgumentException('Only a superadmin can create another superadmin.');
        }

        $allowed = $actorRole === self::SUPERADMIN
            ? array_merge(self::ADMIN_ASSIGNABLE, [self::SUPERADMIN])
            : self::ADMIN_ASSIGNABLE;

        if (!in_array($roleKey, $allowed, true)) {
            throw new InvalidArgumentException('Choose one of: ' . implode(', ', $allowed) . '.');
        }

        $roleId = $this->rolesByIdAndKey()['byKey'][$roleKey] ?? null;

        if ($roleId === null) {
            throw new InvalidArgumentException(
                'This organisation has no ' . $roleKey . ' role. It was not provisioned by bin/provision-org.',
            );
        }

        return $roleId;
    }

    /**
     * Roles both ways round, read once per instance.
     *
     * Per instance and not static: a static would outlive the request and,
     * worse, outlive the tenant — a second organisation handled by the same
     * process would read the first one's role ids, and the symptom is somebody
     * being given a role that belongs to another organisation.
     *
     * @return array{byId: array<int,string>, byKey: array<string,int>}
     */
    private function rolesByIdAndKey(): array
    {
        if ($this->roleCache !== null) {
            return $this->roleCache;
        }

        $byId = [];
        $byKey = [];

        foreach (Role::query()->get() as $role) {
            $byId[(int) $role->id] = (string) $role->key;
            $byKey[(string) $role->key] = (int) $role->id;
        }

        return $this->roleCache = ['byId' => $byId, 'byKey' => $byKey];
    }

    /** @return array<string,mixed> */
    private function one(int $userId): array
    {
        foreach ($this->list() as $row) {
            if ($row['id'] === $userId) {
                return $row;
            }
        }

        throw new InvalidArgumentException('No such user in this organisation.');
    }

    /** @param array<string,mixed> $input */
    private function optional(array $input, string $key): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
