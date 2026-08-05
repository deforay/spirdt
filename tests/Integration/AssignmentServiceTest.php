<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\SiteAssignment;
use App\Service\AssignmentService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Support\MakesTenants;

/**
 * Who is supposed to visit which site.
 *
 * The table is three independent axes and the interesting part is the rule
 * that collapses them into an answer, because every case below is one somebody
 * will hit in a real planning round and none of them fails loudly — a wrong
 * answer here means an assessor drives to the wrong place, or nobody drives
 * anywhere.
 */
final class AssignmentServiceTest extends TestCase
{
    use MakesTenants;

    private int $orgA;
    private int $orgB;
    private int $joseph;
    private int $mary;
    private int $round3;
    private int $round4;
    private string $kitwe;
    private string $ndola;
    private AssignmentService $service;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['site_assignments', 'campaign_sites', 'campaigns', 'assessments', 'testing_sites',
                    'facilities', 'templates', 'users', 'roles', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = $this->makeTenant('asg-a', 'Org A');
        $this->orgB = $this->makeTenant('asg-b', 'Org B');
        $this->shareProgramme($this->orgB, $this->orgA);

        $this->joseph = $this->makeUser($this->orgA, 'joseph@example.org');
        $this->mary = $this->makeUser($this->orgA, 'mary@example.org');

        $this->useTenant($this->orgA);

        $this->kitwe = $this->makeSite($this->orgA, 'aa', 'Kitwe TB clinic');
        $this->ndola = $this->makeSite($this->orgA, 'bb', 'Ndola ART corner');

        $this->round3 = $this->makeCampaign($this->orgA, 'Round 3');
        $this->round4 = $this->makeCampaign($this->orgA, 'Round 4');

        $this->service = new AssignmentService();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── the organisation axis ───

    public function testASiteAssignedToTheOrganisationIsEveryonesInIt(): void
    {
        $this->service->assign($this->kitwe, $this->orgA);

        self::assertTrue($this->service->forUser($this->orgA, $this->joseph)[$this->kitwe]['mine']);
        self::assertTrue($this->service->forUser($this->orgA, $this->mary)[$this->kitwe]['mine']);
    }

    public function testAnotherOrganisationsAssignmentIsNotMine(): void
    {
        $this->service->assign($this->kitwe, $this->orgB);

        self::assertArrayNotHasKey($this->kitwe, $this->service->forUser($this->orgA, $this->joseph));
    }

    /** The independent-audit case: both cover it, each sees their own. */
    public function testTwoOrganisationsCanBothBeAssignedOneSite(): void
    {
        $this->service->assign($this->kitwe, $this->orgA);
        $this->service->assign($this->kitwe, $this->orgB);

        self::assertTrue($this->service->forUser($this->orgA, $this->joseph)[$this->kitwe]['mine']);
        self::assertTrue($this->service->forUser($this->orgB, null)[$this->kitwe]['mine']);
    }

    // ─── the assessor axis ───

    public function testANamedAssessorsSiteIsNotAColleaguesToo(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, $this->joseph);

        $forJoseph = $this->service->forUser($this->orgA, $this->joseph);
        $forMary = $this->service->forUser($this->orgA, $this->mary);

        self::assertTrue($forJoseph[$this->kitwe]['mine']);

        // Mary can see it exists and is her organisation's, which is what lets
        // her cover for him — but it is not on her list.
        self::assertTrue($forMary[$this->kitwe]['organisation']);
        self::assertFalse($forMary[$this->kitwe]['mine']);
    }

    // ─── the round axis, and the rule that matters ───

    public function testARoundAssignmentBeatsTheStandingOneForThatSite(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, $this->joseph);
        $this->service->assign($this->kitwe, $this->orgA, $this->mary, $this->round3);

        $forJoseph = $this->service->forUser($this->orgA, $this->joseph, $this->round3);
        $forMary = $this->service->forUser($this->orgA, $this->mary, $this->round3);

        self::assertFalse($forJoseph[$this->kitwe]['mine'], 'the round overrides the standing plan');
        self::assertTrue($forMary[$this->kitwe]['mine']);
    }

    /**
     * The override is per site. A rule that switched wholesale would mean one
     * exception silently unassigns everything else in the round.
     */
    public function testOverridingOneSiteLeavesTheRestOfTheStandingPlanAlone(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, $this->joseph);
        $this->service->assign($this->ndola, $this->orgA, $this->joseph);
        $this->service->assign($this->kitwe, $this->orgA, $this->mary, $this->round3);

        $forJoseph = $this->service->forUser($this->orgA, $this->joseph, $this->round3);

        self::assertFalse($forJoseph[$this->kitwe]['mine']);
        self::assertTrue($forJoseph[$this->ndola]['mine'], 'Ndola was never overridden');
    }

    public function testTheStandingPlanAppliesWhenNoRoundIsAsked(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, $this->joseph);
        $this->service->assign($this->kitwe, $this->orgA, $this->mary, $this->round3);

        self::assertTrue($this->service->forUser($this->orgA, $this->joseph)[$this->kitwe]['mine']);
    }

    /** Last year's plan is not this year's default. */
    public function testAnotherRoundsAssignmentDoesNotLeakIntoThisOne(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, $this->mary, $this->round3);

        self::assertArrayNotHasKey(
            $this->kitwe,
            $this->service->forUser($this->orgA, $this->mary, $this->round4),
        );
    }

    public function testARoundWithNoOverrideFallsBackToTheStandingPlan(): void
    {
        $this->service->assign($this->ndola, $this->orgA, $this->joseph);

        self::assertTrue(
            $this->service->forUser($this->orgA, $this->joseph, $this->round3)[$this->ndola]['mine'],
        );
    }

    // ─── idempotency ───

    public function testAssigningTwiceIsANoOp(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, $this->joseph, $this->round3);
        $this->service->assign($this->kitwe, $this->orgA, $this->joseph, $this->round3);

        self::assertSame(1, SiteAssignment::query()->count());
    }

    /** Nulls in a unique key are distinct in MySQL — the generated columns are what stop this. */
    public function testAssigningAStandingAssignmentTwiceIsAlsoANoOp(): void
    {
        $this->service->assign($this->kitwe, $this->orgA);
        $this->service->assign($this->kitwe, $this->orgA);

        self::assertSame(1, SiteAssignment::query()->count());
    }

    public function testReassigningUpdatesTheDueDateRatherThanAdding(): void
    {
        $this->service->assign($this->kitwe, $this->orgA, null, null, '2026-09-30');
        $this->service->assign($this->kitwe, $this->orgA, null, null, '2026-10-31');

        self::assertSame(1, SiteAssignment::query()->count());
        self::assertSame(
            '2026-10-31',
            SiteAssignment::query()->first()?->due_on?->format('Y-m-d'),
        );
    }

    public function testAnInactiveAssignmentIsIgnored(): void
    {
        $assignment = $this->service->assign($this->kitwe, $this->orgA);
        $assignment->is_active = false;
        $assignment->save();

        self::assertArrayNotHasKey($this->kitwe, $this->service->forUser($this->orgA, $this->joseph));
    }

    // ─── fixtures ───

    private function makeUser(int $organizationId, string $email): int
    {
        $roleId = Capsule::table('roles')
            ->where('organization_id', $organizationId)
            ->where('key', 'assessor')
            ->value('id')
            ?? Capsule::table('roles')->insertGetId([
                'organization_id' => $organizationId,
                'key'             => 'assessor',
                'name'            => 'Assessor',
                'is_system'       => 1,
            ]);

        return (int) Capsule::table('users')->insertGetId([
            'organization_id' => $organizationId,
            'role_id'         => (int) $roleId,
            'email'           => $email,
            'password_hash'   => 'x',
            'full_name'       => $email,
            'is_active'       => 1,
        ]);
    }

    private function makeSite(int $organizationId, string $slot, string $name): string
    {
        $facilityId = '019fd400-0000-7000-8000-0000000000' . $slot;
        $siteId = '019fd400-0000-7000-8000-0000000001' . $slot;

        Capsule::table('facilities')->insert([
            'id'           => BinaryUuid::toBytes($facilityId),
            'programme_id' => $this->programmeFor($organizationId),
            'name'         => 'Facility ' . $slot,
            'source'       => 'registry',
        ]);

        Capsule::table('testing_sites')->insert([
            'id'           => BinaryUuid::toBytes($siteId),
            'programme_id' => $this->programmeFor($organizationId),
            'facility_id'  => BinaryUuid::toBytes($facilityId),
            'name'         => $name,
            'source'       => 'registry',
        ]);

        return $siteId;
    }

    private function makeCampaign(int $organizationId, string $name): int
    {
        $templateId = Capsule::table('templates')->where('code', 'spi-rdt')->value('id')
            ?? Capsule::table('templates')->insertGetId([
                'organization_id' => null,
                'code'            => 'spi-rdt',
                'version'         => '1.0.0',
                'title'           => 'SPI-RDT',
                'definition'      => json_encode(['sections' => []], JSON_THROW_ON_ERROR),
                'status'          => 'published',
            ]);

        return (int) Capsule::table('campaigns')->insertGetId([
            'organization_id' => $organizationId,
            'template_id'     => (int) $templateId,
            'name'            => $name,
            'status'          => 'active',
        ]);
    }
}
