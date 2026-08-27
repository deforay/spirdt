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

    /**
     * Email a report out of the system, to an address outside it.
     *
     * Separate from reading one, because it is not reading. A viewer whose
     * whole job is these screens can be trusted with what is in them without
     * being able to send a laboratory's score to any address they can type —
     * and once a message has left, no permission can call it back.
     */
    public const REPORTS_SEND = 'reports.send';

    /** Create accounts, change roles, reset passwords, deactivate. */
    public const USERS_MANAGE = 'users.manage';

    /**
     * Read the audit trail: who did what, and when.
     *
     * Separate from the actions it records. Somebody who may reset passwords
     * is not automatically somebody who may read the history of everybody
     * else's, and an organisation may want the reverse — a compliance reader
     * who changes nothing and sees everything.
     */
    public const AUDIT_READ = 'audit.read';

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
     * Change how the installation itself is configured.
     *
     * Its name, who to contact about it, and where its mail goes out. Those
     * live in `system_config`, which is shared rather than tenant-scoped, so
     * this is the second permission after `organizations.manage` whose effect
     * is felt by organisations other than the holder's own.
     */
    public const SETTINGS_MANAGE = 'settings.manage';

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
            self::REPORTS_SEND,
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
            self::AUDIT_READ,
            self::ORGANIZATIONS_MANAGE,
            self::SETTINGS_MANAGE,
        ];
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
