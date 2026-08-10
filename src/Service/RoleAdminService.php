<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Auth\Permission;
use App\Auth\Roles;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

/**
 * Changing what a role may do.
 *
 * The companion to UserAdminService, and the division between them is the
 * point: that one decides WHO holds a role, this one decides what holding it
 * means. Both are needed and they are not the same trust.
 *
 * THIS IS THE ONE SCREEN THAT CAN BE USED TO OBTAIN EVERY OTHER PERMISSION, so
 * it carries three guards and they are load-bearing.
 *
 * NOBODY MAY GRANT WHAT THEY DO NOT HOLD. Without this the whole layer is
 * decorative: an administrator with roles.manage adds organizations.manage to
 * their own role and is a superadmin a moment later. With it, the set of
 * permissions in an organisation can only ever be redistributed, never
 * enlarged, by anyone below the person who already holds them.
 *
 * NOBODY MAY EDIT A ROLE THAT OUTRANKS THEIRS. The same rule UserAdminService
 * applies to people, applied to roles, and for the same reason: an
 * administrator who could edit the superadmin role could take the
 * organisation from whoever is above them without ever naming them.
 *
 * NOBODY MAY TAKE roles.manage OFF THEIR OWN ROLE. This is the lockout guard,
 * and it is the exact analogue of "you cannot remove your own admin role".
 * Removing any other permission is recoverable — you still hold the screen
 * that puts it back. Removing this one is the single change that cannot be
 * undone from inside the application, and it would send somebody to
 * bin/recover-access over a checkbox.
 *
 * Those three together mean there is no sequence of calls that leaves an
 * organisation unable to administer itself, and no sequence that gives anybody
 * more than the person who granted it to them had.
 *
 * System roles are editable. `is_system` marks them undeletable, which is a
 * different claim — an organisation reshaping what its viewers can see is the
 * ordinary use of this screen, and refusing it would leave the five defaults
 * as the whole of the model.
 */
final class RoleAdminService
{
    /**
     * Who may edit whose role. Mirrors UserAdminService::RANK exactly, and has
     * to: the two guards are halves of one rule, and a role that outranks you
     * for the purpose of editing its people but not its permissions would be
     * a way around the other.
     */
    private const RANK = ['superadmin' => 2, 'admin' => 1];

    /**
     * Every role in this organisation, with what it holds and who has it.
     *
     * The user count is here because it is the question anybody about to
     * change a grant asks first. Changing what Viewer means is a different
     * decision when eleven people are viewers than when nobody is.
     *
     * @return array<string,mixed>
     */
    public function list(string $actorRole): array
    {
        $roles = [];

        $query = Role::query();
        $query->getQuery()->orderBy('roles.key');

        foreach ($query->get() as $role) {
            $id = (int) $role->id;
            $key = (string) $role->key;

            $roles[] = [
                'id'          => $id,
                'key'         => $key,
                'name'        => (string) $role->name,
                'is_system'   => (bool) $role->is_system,
                'permissions' => Roles::permissionsOf($id),
                'user_count'  => $this->countUsers($id),
                // Said by the server rather than worked out by the client. The
                // API refuses these anyway, and a checkbox that can be ticked
                // and then rejected is worse than one that is plainly locked.
                'editable'    => $this->outranks($actorRole, $key),
            ];
        }

        return [
            'roles' => $roles,
            // The whole catalogue, so a permission added by a later version
            // appears on this screen without the management app being rebuilt.
            'catalogue' => Permission::all(),
            // What this actor may hand out: exactly what they hold. Anything
            // else is greyed out with the reason attached.
            'grantable' => $this->heldBy($actorRole),
        ];
    }

    /**
     * Replace a role's permissions with the set given.
     *
     * A whole set rather than add-one/remove-one, because the screen is a list
     * of checkboxes and that is what it knows. Sending the difference would
     * mean two clients editing one role could each apply a change that was
     * correct against a state neither of them ended up in.
     *
     * @param  array<mixed>         $permissions
     * @return array<string,mixed>
     */
    public function updatePermissions(
        int $roleId,
        array $permissions,
        int $actorUserId,
        string $actorRole,
    ): array {
        $role = Role::query()->where('roles.id', $roleId)->first();

        if (!$role instanceof Role) {
            throw new InvalidArgumentException('No such role in this organisation.');
        }

        $roleKey = (string) $role->key;

        if (!$this->outranks($actorRole, $roleKey)) {
            throw new InvalidArgumentException(
                'You cannot change what the ' . $roleKey . ' role may do.',
            );
        }

        $wanted = $this->clean($permissions);
        $current = Roles::permissionsOf($roleId);

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        if ($added === [] && $removed === []) {
            return $this->one($role, $actorRole);
        }

        $held = $this->heldBy($actorRole);
        $beyond = array_values(array_diff($added, $held));

        if ($beyond !== []) {
            throw new InvalidArgumentException(
                'You cannot grant what you do not have yourself: ' . implode(', ', $beyond) . '.',
            );
        }

        // Own role, own lock. Read from the actor's account rather than from
        // the role key they arrived with, because the key is only as good as
        // the token and this is the guard that stops a one-way door.
        if ($roleId === $this->roleIdOf($actorUserId) && in_array(Permission::ROLES_MANAGE, $removed, true)) {
            throw new InvalidArgumentException(
                'You cannot remove this permission from your own role. Nobody could put it back.',
            );
        }

        Capsule::connection()->transaction(function () use ($roleId, $added, $removed): void {
            if ($removed !== []) {
                Capsule::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->whereIn('permission_key', $removed)
                    ->delete();
            }

            if ($added !== []) {
                Capsule::table('role_permissions')->insertOrIgnore(array_map(
                    static fn (string $key): array => ['role_id' => $roleId, 'permission_key' => $key],
                    $added,
                ));
            }
        });

        // The difference rather than the resulting set. Somebody reading this
        // months later wants to know what changed, and a list of seven
        // permissions does not say which of them is new.
        AuditLog::record(AuditAction::ROLE_PERMISSIONS_CHANGED, 'role', $roleId, [
            'role'    => $roleKey,
            'granted' => $added,
            'revoked' => $removed,
        ]);

        return $this->one($role, $actorRole);
    }

    /**
     * Known keys only, de-duplicated.
     *
     * A key outside the catalogue is a typo or a leftover from a version that
     * has been deployed over. Storing it would produce a grant that reads as
     * meaningful and opens nothing, which is the kind of row somebody finds a
     * year later and cannot explain.
     *
     * @param  array<mixed>  $permissions
     * @return list<string>
     */
    private function clean(array $permissions): array
    {
        $cleaned = [];

        foreach ($permissions as $permission) {
            if (!is_string($permission) || !Permission::exists($permission)) {
                throw new InvalidArgumentException(
                    'There is no permission called ' . (is_string($permission) ? $permission : 'that') . '.',
                );
            }

            $cleaned[$permission] = true;
        }

        return array_keys($cleaned);
    }

    /**
     * What the actor holds, which is what they may hand out.
     *
     * Read from the role's rows rather than from Roles::GRANTS: the actor's
     * own role may itself have been edited, and the defaults would describe an
     * organisation that no longer exists.
     *
     * @return list<string>
     */
    private function heldBy(string $actorRole): array
    {
        $roleId = Role::query()->where('roles.key', $actorRole)->value('id');

        return $roleId === null ? [] : Roles::permissionsOf((int) $roleId);
    }

    /** Higher acts on equal or lower, never on higher. */
    private function outranks(string $actorRole, string $targetRole): bool
    {
        return (self::RANK[$actorRole] ?? 0) >= (self::RANK[$targetRole] ?? 0);
    }

    private function roleIdOf(int $userId): int
    {
        return (int) User::query()->where('users.id', $userId)->value('role_id');
    }

    private function countUsers(int $roleId): int
    {
        return User::query()->where('users.role_id', $roleId)->count();
    }

    /** @return array<string,mixed> */
    private function one(Role $role, string $actorRole): array
    {
        $id = (int) $role->id;

        return [
            'id'          => $id,
            'key'         => (string) $role->key,
            'name'        => (string) $role->name,
            'is_system'   => (bool) $role->is_system,
            'permissions' => Roles::permissionsOf($id),
            'user_count'  => $this->countUsers($id),
            'editable'    => $this->outranks($actorRole, (string) $role->key),
        ];
    }
}
