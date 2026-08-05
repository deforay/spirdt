<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentPathogen;
use App\Models\AssessmentScore;
use App\Service\SyncService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The sync, against a real database.
 *
 * Two properties matter more than the rest, because both fail silently:
 * running the same payload twice must not produce a second site visit, and one
 * organisation must never be able to touch another's data.
 */
final class SyncServiceTest extends TestCase
{
    private int $orgA;
    private int $orgB;
    private string $siteId;
    private string $facilityId;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();

        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['assessment_scores', 'answers', 'assessment_pathogens', 'assessments',
                    'templates', 'testing_sites', 'facilities', 'organizations'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = $this->makeOrganization('org-a');
        $this->orgB = $this->makeOrganization('org-b');

        $definition = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/resources/templates/spi-rdt-1.0.0.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        Capsule::table('templates')->insert([
            'organization_id' => null,
            'code'            => 'spi-rdt',
            'version'         => '1.0.0',
            'title'           => 'SPI-RDT',
            'definition'      => json_encode($definition, JSON_THROW_ON_ERROR),
            'status'          => 'published',
        ]);

        $this->facilityId = $this->makeFacility($this->orgA, 'a1');
        $this->siteId = $this->makeSite($this->orgA, $this->facilityId, 'a2');

        TenantContext::set($this->orgA, null);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    public function testAcceptsAnAssessmentAndSnapshotsTheScore(): void
    {
        $result = (new SyncService())->accept($this->payload());

        self::assertSame(3, count($result['accepted']));

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertSame('submitted', $stored->status);

        $score = AssessmentScore::query()->first();
        self::assertNotNull($score);
        self::assertSame(2, (int) $score->pathogen_count);
        // 3.1 Yes and 3.2 No, plus 4.1 Yes for one pathogen: 4 of 6.
        self::assertSame(4, (int) $score->total_score);
        self::assertSame(6, (int) $score->total_possible);
        self::assertFalse($result['score']['is_complete'], 'a partial visit is not complete');
    }

    public function testRunningTheSamePayloadTwiceChangesNothing(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();

        $first = $sync->accept($payload);
        $second = $sync->accept($payload);

        self::assertSame($first['assessment_id'], $second['assessment_id']);
        self::assertSame($first['score'], $second['score']);

        self::assertSame(1, Assessment::query()->count(), 'one site visit, not two');
        self::assertSame(3, Answer::query()->count(), 'answers upsert on their natural key');
        self::assertSame(2, AssessmentPathogen::query()->count());
        self::assertSame(1, AssessmentScore::query()->count());
    }

    public function testAChangedAnswerReplacesTheOldOneAndRescores(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();

        $sync->accept($payload);

        // The assessor corrected 3.2 from No to Yes before syncing again.
        $payload['answers'][1]['response'] = 'Y';
        $second = $sync->accept($payload);

        self::assertSame(3, Answer::query()->count(), 'corrected, not duplicated');
        self::assertSame(6, $second['score']['total_score']);
        self::assertSame(6, $second['score']['total_possible']);
    }

    public function testNotApplicableLeavesTheDenominator(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();

        // 3.9 permits Not applicable; it must not be scored as a zero.
        $payload['answers'][] = ['question_code' => '3.9', 'response' => 'NA', 'comment' => 'No requirement.'];
        $result = $sync->accept($payload);

        self::assertSame(4, $result['score']['total_score']);
        self::assertSame(6, $result['score']['total_possible'], 'NA adds nothing to either side');
    }

    public function testAnotherOrganizationCannotSeeTheAssessment(): void
    {
        $result = (new SyncService())->accept($this->payload());

        TenantContext::set($this->orgB, null);

        self::assertNull(Assessment::findByUuid($result['assessment_id']));
        self::assertSame(0, Assessment::query()->count());
        self::assertSame(0, Answer::query()->count());
        self::assertSame(0, AssessmentScore::query()->count());
    }

    public function testAnotherOrganizationCannotOverwriteTheAssessment(): void
    {
        $result = (new SyncService())->accept($this->payload());

        $facilityB = $this->makeFacility($this->orgB, 'b1');
        $siteB = $this->makeSite($this->orgB, $facilityB, 'b2');

        TenantContext::set($this->orgB, null);

        $payload = $this->payload();
        $payload['id'] = $result['assessment_id'];
        $payload['facility_id'] = $facilityB;
        $payload['testing_site_id'] = $siteB;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to another organisation');

        (new SyncService())->accept($payload);
    }

    public function testAScopedQueryOutsideARequestRefusesToRun(): void
    {
        TenantContext::forget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tenant context');

        Assessment::query()->count();
    }

    public function testAnAnswerNamingAnUndeclaredPathogenIsDropped(): void
    {
        $payload = $this->payload();
        $payload['answers'][] = ['question_code' => '4.2', 'pathogen' => 'ebola', 'response' => 'Y'];

        $result = (new SyncService())->accept($payload);

        self::assertSame(3, Answer::query()->count(), 'the undeclared pathogen answer is not stored');
        self::assertSame(6, $result['score']['total_possible']);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'id'               => '019fd200-0000-7000-8000-000000000001',
            'testing_site_id'  => $this->siteId,
            'facility_id'      => $this->facilityId,
            'template_code'    => 'spi-rdt',
            'template_version' => '1.0.0',
            'assessed_on'      => '2026-08-05',
            'context'          => ['refers_specimens' => 'no'],
            'device_id'        => 'test-device',
            'pathogens'        => [
                ['key' => 'hiv', 'name' => 'HIV'],
                ['key' => 'syphilis', 'name' => 'Syphilis'],
            ],
            'answers'          => [
                ['question_code' => '3.1', 'response' => 'Y'],
                ['question_code' => '3.2', 'response' => 'N', 'comment' => 'No SOP on site.'],
                ['question_code' => '4.1', 'pathogen' => 'hiv', 'response' => 'Y'],
            ],
        ];
    }

    private function makeOrganization(string $code): int
    {
        return (int) Capsule::table('organizations')->insertGetId([
            'code' => $code,
            'name' => strtoupper($code),
        ]);
    }

    /**
     * $slot is two hex characters chosen by the caller, NOT derived from the
     * organisation id. Organisation ids come from an auto-increment that keeps
     * climbing between runs because deleting rows does not reset it, and once
     * it reached three digits the concatenated string stopped being a UUID —
     * after several runs had already passed.
     */
    private function makeFacility(int $organizationId, string $slot): string
    {
        $id = '019fd200-0000-7000-8000-0000000000' . $slot;

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($id),
            'organization_id' => $organizationId,
            'name'            => 'Facility ' . $organizationId,
            'source'          => 'registry',
        ]);

        return $id;
    }

    private function makeSite(int $organizationId, string $facilityId, string $slot): string
    {
        $id = '019fd200-0000-7000-8000-0000000001' . $slot;

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($id),
            'organization_id' => $organizationId,
            'facility_id'     => BinaryUuid::toBytes($facilityId),
            'name'            => 'Site ' . $organizationId,
            'source'          => 'registry',
        ]);

        return $id;
    }
}
