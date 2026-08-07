<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The roles every organisation starts with, and what each one may do.
 *
 * THE DEFAULTS HERE ARE A STARTING POINT, NOT THE RULE. They are written to
 * `role_permissions` when a role is created, and from that moment the database
 * is what decides. An organisation that takes reports away from its viewers has
 * done something this file must not undo on the next deploy — which is why
 * nothing reads these grants at request time, and why there is no fallback to
 * them when a role has no rows.
 *
 * That last part is deliberate and it fails closed. A role with no permissions
 * can reach nothing, and the alternative — treating "no rows" as "use the
 * defaults" — would make revoking the last permission from a role restore all
 * of them.
 *
 * The role keys themselves stay fixed. They are what a token names and what
 * `users.role_id` points at, and the display names beside them are what an
 * organisation may translate. Never branch on the display name.
 */
final class Roles
{
    /** Seeded for every organisation. is_system marks them undeletable. */
    public const SYSTEM = [
        'superadmin' => 'Superadmin',
        'admin'      => 'Administrator',
        'assessor'   => 'Assessor',
        'viewer'     => 'Viewer',
        'site_user'  => 'Site user',
    ];

    /**
     * What each system role is created holding.
     *
     * An assessor gets one permission and it is not `registry.read`. The site
     * list their device needs comes from /sites, which is a different surface
     * with its own scoping, and which already returns every site in the
     * programme annotated with who is assigned to it. `registry.read` would
     * additionally open the places, the facilities behind those sites, and the
     * assignment plan for everybody else — none of which an assessor has ever
     * been able to reach.
     *
     * A site_user gets nothing yet. The role exists because the instrument has
     * a place for laboratory staff to sign, and the account they will
     * eventually use should not be invented in a hurry when that screen is
     * built. Nothing is worse than a role that quietly holds more than the
     * feature it was made for needs.
     *
     * @var array<string,list<string>>
     */
    public const GRANTS = [
        'superadmin' => [
            Permission::ASSESSMENTS_SUBMIT,
            Permission::REGISTRY_READ,
            Permission::REGISTRY_WRITE,
            Permission::ASSIGNMENTS_WRITE,
            Permission::REPORTS_READ,
            Permission::USERS_MANAGE,
            Permission::ORGANIZATIONS_MANAGE,
        ],
        'admin' => [
            Permission::ASSESSMENTS_SUBMIT,
            Permission::REGISTRY_READ,
            Permission::REGISTRY_WRITE,
            Permission::ASSIGNMENTS_WRITE,
            Permission::REPORTS_READ,
            Permission::USERS_MANAGE,
        ],
        'assessor' => [
            Permission::ASSESSMENTS_SUBMIT,
        ],
        'viewer' => [
            Permission::REGISTRY_READ,
            Permission::REPORTS_READ,
        ],
        'site_user' => [],
    ];

    /** @return list<string> */
    public static function grantsFor(string $roleKey): array
    {
        return self::GRANTS[$roleKey] ?? [];
    }

    /**
     * Give a newly created role the permissions its key implies.
     *
     * Idempotent, and safe to call on a role that already has rows: it inserts
     * what is missing and touches nothing else. Calling it on a role an
     * administrator has since edited restores the defaults it removed, so it
     * belongs at creation and in repair tooling, not on a schedule.
     */
    public static function seed(int $roleId, string $roleKey): void
    {
        $grants = self::grantsFor($roleKey);

        if ($grants === []) {
            return;
        }

        Capsule::table('role_permissions')->insertOrIgnore(array_map(
            static fn (string $key): array => ['role_id' => $roleId, 'permission_key' => $key],
            $grants,
        ));
    }

    /**
     * What this role may do, as the database has it.
     *
     * Read live on every authenticated request, for the same reason the role
     * and the active flag are: a permission taken away has to be gone now, not
     * when the access token expires.
     *
     * @return list<string>
     */
    public static function permissionsOf(int $roleId): array
    {
        return array_values(array_map(
            strval(...),
            Capsule::table('role_permissions')
                ->where('role_id', $roleId)
                ->pluck('permission_key')
                ->all(),
        ));
    }
}
