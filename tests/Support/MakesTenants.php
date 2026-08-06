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
     */
    private function tokenFor(int $organizationId, string $role = 'assessor', int $userId = 1): string
    {
        return (new TokenService())->issue(
            $userId,
            $organizationId,
            $role,
            false,
            false,
            $this->programmeFor($organizationId),
        );
    }
}
