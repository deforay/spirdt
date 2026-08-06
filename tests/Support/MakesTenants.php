<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\TokenService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Organisations, and the programmes above them.
 *
 * Every organisation gets its own programme by default, which preserves the
 * isolation the tests were written against — an organisation cannot see
 * another's registry unless a test deliberately puts them in one programme via
 * shareProgramme(). That is the same default the migration takes, and for the
 * same reason: sharing a site list should be something somebody chose.
 */
trait MakesTenants
{
    /** @var array<int,int> organisation id => programme id */
    private array $programmeOf = [];

    /** An organisation, with a programme of its own. */
    private function makeTenant(string $code, ?string $name = null): int
    {
        // Reused when one with this code is already there, rather than
        // inserted blindly. Most suites clear `organizations` and leave
        // `programmes` alone — deleting programmes would orphan whatever
        // registry rows another suite is holding. The code identifies it, so
        // reusing one is the same programme, not a stale one.
        $programmeId = (int) (Capsule::table('programmes')->where('code', $code)->value('id')
            ?? Capsule::table('programmes')->insertGetId([
                'code' => $code,
                'name' => $name ?? strtoupper($code),
            ]));

        $organizationId = (int) Capsule::table('organizations')->insertGetId([
            'code'         => $code,
            'name'         => $name ?? strtoupper($code),
            'programme_id' => $programmeId,
        ]);

        $this->programmeOf[$organizationId] = $programmeId;

        return $organizationId;
    }

    private function programmeFor(int $organizationId): int
    {
        return $this->programmeOf[$organizationId]
            ?? (int) Capsule::table('organizations')->where('id', $organizationId)->value('programme_id');
    }

    /**
     * Move one organisation into another's programme.
     *
     * The case the whole layer exists for: two organisations auditing in the
     * same country, sharing one site registry, keeping their own assessments.
     */
    private function shareProgramme(int $organizationId, int $withOrganizationId): void
    {
        $programmeId = $this->programmeFor($withOrganizationId);

        Capsule::table('organizations')
            ->where('id', $organizationId)
            ->update(['programme_id' => $programmeId]);

        $this->programmeOf[$organizationId] = $programmeId;
    }

    /** Become this organisation, with its programme, for the queries that follow. */
    private function useTenant(int $organizationId, ?int $userId = null): void
    {
        TenantContext::set($organizationId, $userId, false, $this->programmeFor($organizationId));
    }

    /**
     * A token that carries the programme as well as the organisation.
     *
     * Minting one without the programme is not a shortcut — the registry scope
     * throws on it, which is the correct behaviour and a confusing way for a
     * test to fail. Real tokens always carry it, because AuthService reads it
     * from the organisation at sign-in.
     *
     * The account is created to match, for the same reason. AuthMiddleware
     * reads the role and the active flag from the row on every request rather
     * than believing the token, so a token naming a user who does not exist is
     * refused — correctly, and as a 401 that tells a test nothing about what it
     * was actually checking. A token in a test now stands for an account in the
     * same way one in the wild does.
     */
    private function tokenFor(int $organizationId, string $role = 'assessor', int $userId = 1): string
    {
        $this->makeAccount($organizationId, $userId, $role);

        return (new TokenService())->issue(
            $userId,
            $organizationId,
            $role,
            in_array($role, ['admin', 'superadmin'], true),
            false,
            $this->programmeFor($organizationId),
        );
    }

    /** The user a token names, with the role it claims. Idempotent on the id. */
    private function makeAccount(int $organizationId, int $userId, string $role): void
    {
        $roleId = Capsule::table('roles')
            ->where('organization_id', $organizationId)
            ->where('key', $role)
            ->value('id');

        if ($roleId === null) {
            $roleId = Capsule::table('roles')->insertGetId([
                'organization_id' => $organizationId,
                'key'             => $role,
                'name'            => ucfirst($role),
                'is_system'       => 1,
            ]);
        }

        $attributes = [
            'organization_id'      => $organizationId,
            'role_id'              => (int) $roleId,
            'full_name'            => 'Fixture ' . $userId,
            'is_active'            => 1,
            'must_change_password' => 0,
        ];

        $existing = Capsule::table('users')->where('id', $userId)->first();

        if ($existing !== null) {
            Capsule::table('users')->where('id', $userId)->update($attributes);

            return;
        }

        Capsule::table('users')->insert($attributes + [
            'id'            => $userId,
            'email'         => 'fixture-' . $userId . '@example.test',
            'password_hash' => password_hash('fixture-password', PASSWORD_DEFAULT),
        ]);
    }
}
