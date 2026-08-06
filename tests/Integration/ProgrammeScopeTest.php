<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Assessment;
use App\Models\Facility;
use App\Models\TestingSite;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\MakesTenants;

/**
 * The one property the programme layer exists to create, and the one it must
 * not destroy.
 *
 * SHARED: two organisations in a programme see one registry, so when both
 * audit the same lab they reference the same row. Without that, comparing
 * their results means matching facility names and hoping.
 *
 * ISOLATED: they still cannot see each other's assessments. That is the entire
 * security boundary of this change, and it is the kind that fails silently —
 * nothing about a leaked row looks wrong in a response.
 */
final class ProgrammeScopeTest extends TestCase
{
    use MakesTenants;

    private int $orgA;
    private int $orgB;
    private int $orgElsewhere;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['assessment_scores', 'findings', 'answers', 'assessment_pathogens', 'assessments',
                    'templates', 'testing_sites', 'facilities', 'geo_units', 'organizations',
                    'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        // Two organisations auditing in one country, and one somewhere else.
        $this->orgA = $this->makeTenant('zm-lab', 'Reference Lab');
        $this->orgB = $this->makeTenant('zm-partner', 'Implementing Partner');
        $this->orgElsewhere = $this->makeTenant('ke-moh', 'Kenya MoH');

        $this->shareProgramme($this->orgB, $this->orgA);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── shared ───

    public function testBothOrganisationsSeeTheSameSite(): void
    {
        $this->useTenant($this->orgA);
        $siteId = $this->makeSite($this->orgA, 'aa');

        $this->useTenant($this->orgB);
        $found = TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($siteId))->first();

        self::assertNotNull($found, 'the partner must see the reference lab’s registry entry');
        self::assertSame('Kitwe TB clinic', $found->name);
    }

    public function testAnOrganisationInAnotherProgrammeSeesNothing(): void
    {
        $this->useTenant($this->orgA);
        $siteId = $this->makeSite($this->orgA, 'aa');

        $this->useTenant($this->orgElsewhere);

        self::assertNull(
            TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($siteId))->first(),
        );
        self::assertSame(0, TestingSite::query()->count());
    }

    public function testAFacilityCreatedByOneIsVisibleToTheOther(): void
    {
        $this->useTenant($this->orgA);
        $this->makeSite($this->orgA, 'aa');

        $this->useTenant($this->orgB);

        self::assertSame(1, Facility::query()->count());
    }

    /**
     * The row records who entered it, and that is all organization_id does on
     * the registry now. It is provenance for whoever reconciles duplicates,
     * never the scope.
     */
    public function testTheRegistryRecordsWhichOrganisationOriginatedARow(): void
    {
        $this->useTenant($this->orgA);
        $siteId = $this->makeSite($this->orgA, 'aa');

        $this->useTenant($this->orgB);
        $found = TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($siteId))->first();

        self::assertNotNull($found);
        self::assertSame($this->orgA, (int) $found->organization_id);
    }

    // ─── isolated ───

    public function testAssessmentsAreStillInvisibleAcrossOrganisations(): void
    {
        $this->useTenant($this->orgA);
        $siteId = $this->makeSite($this->orgA, 'aa');
        $this->makeAssessment($this->orgA, $siteId, 'a1');

        self::assertSame(1, Assessment::query()->count());

        $this->useTenant($this->orgB);

        self::assertSame(
            0,
            Assessment::query()->count(),
            'sharing a registry must not share the audits taken against it',
        );
    }

    /**
     * Both organisations assessing the same bench is the point of the layer —
     * it is what makes their two results comparable — and each still sees only
     * its own.
     */
    public function testTwoOrganisationsCanIndependentlyAssessOneSite(): void
    {
        $this->useTenant($this->orgA);
        $siteId = $this->makeSite($this->orgA, 'aa');
        $this->makeAssessment($this->orgA, $siteId, 'a1');

        $this->useTenant($this->orgB);
        $this->makeAssessment($this->orgB, $siteId, 'b1');

        self::assertSame(1, Assessment::query()->count());

        $this->useTenant($this->orgA);
        self::assertSame(1, Assessment::query()->count());

        // Only a deliberately unscoped read sees both, which is what a
        // programme-level comparison will have to be.
        TenantContext::withoutScope(function () use ($siteId): void {
            self::assertSame(
                2,
                Assessment::query()->where('testing_site_id', BinaryUuid::toBytes($siteId))->count(),
            );
        });
    }

    // ─── deleting an organisation must not take the shared registry with it ───

    /**
     * organization_id on the registry became PROVENANCE when the programme
     * layer landed — it records who first entered a row, not who may see it.
     * The foreign key underneath still said ON DELETE CASCADE, so removing the
     * organisation that happened to type a facility in would delete it out
     * from under every other organisation in the programme.
     */
    public function testDeletingAnOrganisationLeavesTheSharedRegistryStanding(): void
    {
        $this->useTenant($this->orgA);
        $siteId = $this->makeSite($this->orgA, 'aa');

        // The partner is using it, which is the whole point of sharing.
        $this->useTenant($this->orgB);
        self::assertSame(1, TestingSite::query()->count());

        TenantContext::withoutScope(function (): void {
            Capsule::table('organizations')->where('id', $this->orgA)->delete();
        });

        $this->useTenant($this->orgB);

        $survivors = TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($siteId));

        self::assertSame(1, $survivors->count(), 'the partner still needs this site');
        self::assertNull(
            $survivors->value('organization_id'),
            'and the provenance is simply forgotten, not carried to a row that no longer exists',
        );
        self::assertSame(1, Facility::query()->count());
    }

    // ─── failing closed ───

    public function testARegistryQueryWithNoProgrammeThrows(): void
    {
        TenantContext::set($this->orgA);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No programme in the tenant context');

        TestingSite::query()->count();
    }

    // ─── fixtures ───

    private function makeSite(int $organizationId, string $slot): string
    {
        $facilityId = '019fd300-0000-7000-8000-0000000000' . $slot;
        $siteId = '019fd300-0000-7000-8000-0000000001' . $slot;

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($facilityId),
            'programme_id'    => $this->programmeFor($organizationId),
            'organization_id' => $organizationId,
            'name'            => 'Kitwe Central Hospital',
            'source'          => 'registry',
        ]);

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($siteId),
            'programme_id'    => $this->programmeFor($organizationId),
            'organization_id' => $organizationId,
            'facility_id'     => BinaryUuid::toBytes($facilityId),
            'name'            => 'Kitwe TB clinic',
            'source'          => 'registry',
        ]);

        return $siteId;
    }

    private function makeAssessment(int $organizationId, string $siteId, string $slot): string
    {
        $templateId = Capsule::table('templates')->where('code', 'spi-rdt')->value('id');

        if ($templateId === null) {
            $templateId = Capsule::table('templates')->insertGetId([
                'organization_id' => null,
                'code'            => 'spi-rdt',
                'version'         => '1.0.0',
                'title'           => 'SPI-RDT',
                'definition'      => json_encode(['sections' => []], JSON_THROW_ON_ERROR),
                'status'          => 'published',
            ]);
        }

        $facilityId = (string) Capsule::table('testing_sites')
            ->where('id', BinaryUuid::toBytes($siteId))
            ->value('facility_id');

        $assessmentId = '019fd300-0000-7000-8000-0000000002' . $slot;

        Capsule::table('assessments')->insert([
            'id'              => BinaryUuid::toBytes($assessmentId),
            'organization_id' => $organizationId,
            'template_id'     => (int) $templateId,
            'testing_site_id' => BinaryUuid::toBytes($siteId),
            'facility_id'     => $facilityId,
            'status'          => 'draft',
            'assessed_on'     => '2026-08-05',
        ]);

        return $assessmentId;
    }
}
