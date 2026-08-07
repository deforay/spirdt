<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Everything a request can be allowed to do.
 *
 * These strings are stored in `role_permissions.permission_key`, so they are
 * data, not code. RENAMING ONE SILENTLY REVOKES IT: the rows in the database
 * still carry the old key, the gate now asks for the new one, and every
 * account holding it loses the capability at the moment of deploy. Add a new
 * key and migrate the rows instead.
 *
 * Named `noun.verb` and kept coarse. A permission per route is a permission
 * nobody can hold a mental model of, and the point of the layer is that an
 * administrator can look at a role and know what it does. The grain here is
 * "a thing you are responsible for", which is roughly one screen in the
 * management app.
 *
 * `registry.read` and `reports.read` are separate for a reason worth stating:
 * the registry is a list of laboratories and the reports are their scores.
 * Somebody may legitimately need to look up sites without being shown how each
 * one is performing.
 */
final class Permission
{
    /** File an assessment against a testing site. The assessor's whole job. */
    public const ASSESSMENTS_SUBMIT = 'assessments.submit';

    /** Look up places, facilities, testing sites and who covers them. */
    public const REGISTRY_READ = 'registry.read';

    /** Add and correct those records, including merging duplicates. */
    public const REGISTRY_WRITE = 'registry.write';

    /** Decide which assessor covers which place. */
    public const ASSIGNMENTS_WRITE = 'assignments.write';

    /** Read collected assessments and their scores. */
    public const REPORTS_READ = 'reports.read';

    /** Create accounts, change roles, reset passwords, deactivate. */
    public const USERS_MANAGE = 'users.manage';

    /**
     * Change what a role may do.
     *
     * Kept apart from `users.manage`, which is the right to decide who holds a
     * role. This is the right to decide what holding it means, and it is the
     * one permission that can be used to obtain the others.
     */
    public const ROLES_MANAGE = 'roles.manage';

    /** Add organisations to the programme. Reaches across tenants. */
    public const ORGANIZATIONS_MANAGE = 'organizations.manage';

    /**
     * The whole catalogue, for validating what an administrator asks for.
     *
     * A permission key that is not in here is a typo or a leftover from a
     * version that has been deployed over. Storing it would produce a grant
     * that reads as meaningful and opens nothing.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ASSESSMENTS_SUBMIT,
            self::REGISTRY_READ,
            self::REGISTRY_WRITE,
            self::ASSIGNMENTS_WRITE,
            self::REPORTS_READ,
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
            self::ORGANIZATIONS_MANAGE,
        ];
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
