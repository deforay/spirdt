<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\DashboardService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Support\MakesTenants;

/**
 * The country at a glance, and the ways that glance can lie.
 *
 * Written against `assessments` and `assessment_scores` directly rather than
 * through a sync, because what is under test is the aggregation and not the
 * engine that produced the scores. The engine has its own suite and its own
 * shared fixtures.
 *
 * Three failures would each be quiet and serious. Counting drafts would report
 * laboratories as scoring 12% because eleven of fifty-nine questions have been
 * answered so far. Dropping empty bands would hide that nobody in the country
 * has reached Level 4. And leaking across organisations would put a partner's
 * results into a ministry's headline figure, which is the one number anybody
 * repeats out loud.
 */
final class DashboardServiceTest extends TestCase
{
    use MakesTenants;

    private int $orgId;
    private int $otherOrgId;
    private int $templateId;

    /** @var array<int, array{0: string, 1: string}> organisation id => facility, testing site */
    private array $registry = [];

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['assessment_scores', 'findings', 'answers', 'assessment_pathogens', 'assessments',
                    'templates', 'testing_sites', 'facilities', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('dash-org');
        $this->otherOrgId = $this->makeTenant('dash-other');

        $this->templateId = (int) Capsule::table('templates')->insertGetId([
            'organization_id' => null,
            'code'            => 'spi-rdt',
            'version'         => '1.0.0',
            'status'          => 'published',
            'definition'      => (string) file_get_contents(
                dirname(__DIR__, 2) . '/resources/templates/spi-rdt-1.0.0.json',
            ),
        ]);

        foreach ([$this->orgId, $this->otherOrgId] as $organizationId) {
            $this->registry[$organizationId] = $this->makeRegistry($organizationId);
        }

        $this->useTenant($this->orgId);
    }

    /**
     * One facility with one testing site inside it.
     *
     * Real rows rather than invented ids: `assessments` has foreign keys to
     * both, and a fixture that fabricates them tests an insert the application
     * could never perform.
     *
     * @return array{0: string, 1: string} facility bytes, testing site bytes
     */
    private function makeRegistry(int $organizationId): array
    {
        $programmeId = $this->programmeFor($organizationId);
        $facility = BinaryUuid::toBytes(BinaryUuid::v7());
        $site = BinaryUuid::toBytes(BinaryUuid::v7());

        TenantContext::withoutScope(static function () use ($programmeId, $organizationId, $facility, $site): void {
            Capsule::table('facilities')->insert([
                'id'              => $facility,
                'programme_id'    => $programmeId,
                'organization_id' => $organizationId,
                'name'            => 'Kitwe Hospital',
                'source'          => 'registry',
            ]);

            Capsule::table('testing_sites')->insert([
                'id'              => $site,
                'programme_id'    => $programmeId,
                'organization_id' => $organizationId,
                'facility_id'     => $facility,
                'name'            => 'Kitwe Laboratory',
                'source'          => 'registry',
            ]);
        });

        return [$facility, $site];
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    public function testDraftsAreCountedButNeverScored(): void
    {
        $this->submitted('2026-08-01', percentage: 85.0, level: 3);
        $this->draft('2026-08-02');
        $this->draft('2026-08-03');

        $summary = (new DashboardService())->summary();

        self::assertSame(1, $summary['totals']['assessments'], 'only the submitted one counts');
        self::assertSame(2, $summary['totals']['drafts']);

        // And nothing a draft could contribute reaches a score panel.
        self::assertSame(1, array_sum(array_column($summary['levels'], 'count')));
        self::assertCount(1, $summary['latest']);

        // The names resolve. Ids leave the model as UUID strings rather than
        // bytes, and passing one into a where() against a BINARY(16) column
        // matches nothing — which showed as a list of unnamed rows.
        self::assertSame('Kitwe Laboratory', $summary['latest'][0]['site']);
        self::assertSame('Kitwe Hospital', $summary['latest'][0]['facility']);
        self::assertSame('2026-08-01', $summary['latest'][0]['assessed_on']);
    }

    /**
     * A band nobody has reached still appears, at zero.
     *
     * Dropping it would make a country with everything at Level 1 look like a
     * country with one band, and the empty top of the scale is the finding.
     */
    public function testEveryBandIsPresentEvenWhenEmpty(): void
    {
        $this->submitted('2026-08-01', percentage: 45.0, level: 1);

        $summary = (new DashboardService())->summary();

        self::assertSame([0, 1, 2, 3, 4], array_column($summary['levels'], 'level'));
        self::assertSame([0, 1, 0, 0, 0], array_column($summary['levels'], 'count'));
    }

    public function testAnotherOrganisationsVisitsAreInvisible(): void
    {
        $this->submitted('2026-08-01', percentage: 85.0, level: 3);
        $this->submitted('2026-08-01', percentage: 20.0, level: 0, organizationId: $this->otherOrgId);

        $summary = (new DashboardService())->summary();

        self::assertSame(1, $summary['totals']['assessments']);
        self::assertSame(0, $summary['levels'][0]['count'], "the partner's Level 0 is not ours");
        self::assertSame(1, $summary['levels'][3]['count']);
    }

    /** Twelve months, including the ones nothing happened in. */
    public function testTheMonthlySeriesHasNoGaps(): void
    {
        $this->submitted(gmdate('Y-m-15'), percentage: 70.0, level: 2);

        $months = (new DashboardService())->summary()['months'];

        self::assertCount(12, $months);
        self::assertSame(gmdate('Y-m'), $months[11]['month'], 'this month is last');
        self::assertSame(1, $months[11]['count']);
        self::assertNull($months[0]['mean'], 'a month with nothing has no mean, not zero');
    }

    /**
     * Sections are pooled across visits, not averaged over their percentages.
     *
     * A visit with two applicable questions must not weigh the same as one
     * with twenty. Here section 1 scores 1/2 on one visit and 9/10 on another:
     * pooled that is 10/12, or 83.3%. Averaging the two percentages would say
     * 70%, which is a number no laboratory earned.
     */
    public function testSectionScoresArePooledRatherThanAveraged(): void
    {
        $this->submitted('2026-08-01', percentage: 50.0, level: 1, sections: [
            ['code' => '1', 'score' => 1, 'possible' => 2, 'applicable' => true],
        ]);
        $this->submitted('2026-08-02', percentage: 90.0, level: 4, sections: [
            ['code' => '1', 'score' => 9, 'possible' => 10, 'applicable' => true],
        ]);

        $sections = (new DashboardService())->summary()['sections'];

        self::assertCount(1, $sections);
        self::assertSame(83.3, $sections[0]['mean']);
        self::assertSame(2, $sections[0]['assessments']);
    }

    /**
     * A section nothing was possible in is skipped, not shown as zero.
     *
     * Printing 0% beside a section every visit marked not-applicable would
     * name it as the worst problem in the country.
     */
    public function testASectionWithNothingPossibleIsNotReportedAsZero(): void
    {
        $this->submitted('2026-08-01', percentage: 80.0, level: 3, sections: [
            ['code' => '1', 'score' => 8, 'possible' => 10, 'applicable' => true],
            ['code' => '2', 'score' => 0, 'possible' => 0, 'applicable' => false],
        ]);

        $sections = (new DashboardService())->summary()['sections'];

        self::assertSame(['1'], array_column($sections, 'code'));
    }

    public function testSectionsAreOrderedWeakestFirst(): void
    {
        $this->submitted('2026-08-01', percentage: 60.0, level: 2, sections: [
            ['code' => '1', 'score' => 9, 'possible' => 10, 'applicable' => true],
            ['code' => '2', 'score' => 2, 'possible' => 10, 'applicable' => true],
            ['code' => '3', 'score' => 5, 'possible' => 10, 'applicable' => true],
        ]);

        $sections = (new DashboardService())->summary()['sections'];

        self::assertSame(['2', '3', '1'], array_column($sections, 'code'));
        // And named from the instrument rather than by their code.
        self::assertNotSame('1', $sections[2]['name']);
    }

    public function testTheBandLabelsComeFromTheInstrument(): void
    {
        $bands = (new DashboardService())->summary()['bands'];

        self::assertCount(5, $bands);
        self::assertSame([0, 1, 2, 3, 4], array_column($bands, 'level'));
        self::assertSame([0.0, 40.0, 60.0, 80.0, 90.0], array_column($bands, 'min_percent'));
    }

    /**
     * A date range narrows EVERY figure, not the charts alone.
     *
     * The failure this guards is quiet: a headline count that ignores the
     * filter sitting beside a chart that honours it. Both look authoritative
     * and only one answers what was asked, which is worse than having no
     * filter at all.
     */
    public function testADateRangeNarrowsEveryFigureOnTheScreen(): void
    {
        $this->submitted('2026-03-10', percentage: 90.0, level: 4, sections: [
            ['code' => '1', 'score' => 9, 'possible' => 10, 'applicable' => true],
        ]);
        $this->submitted('2026-08-10', percentage: 40.0, level: 1, sections: [
            ['code' => '1', 'score' => 4, 'possible' => 10, 'applicable' => true],
        ]);
        $this->draft('2026-03-11');
        $this->draft('2026-08-11');

        $summary = (new DashboardService())->summary('en', [
            'from' => '2026-08-01',
            'to'   => '2026-08-31',
        ]);

        self::assertSame(1, $summary['totals']['assessments'], 'headline count');
        self::assertSame(1, $summary['totals']['drafts'], 'drafts follow the same range');
        self::assertSame(1, $summary['totals']['sites'], 'distinct sites');
        self::assertSame(0, $summary['levels'][4]['count'], 'March is outside the range');
        self::assertSame(1, $summary['levels'][1]['count']);
        self::assertSame(40.0, $summary['sections'][0]['mean'], 'sections too, not just counts');
        self::assertCount(1, $summary['latest']);
    }

    public function testAnEmptyOrganisationReportsZeroesRatherThanFailing(): void
    {
        $summary = (new DashboardService())->summary();

        self::assertSame(0, $summary['totals']['assessments']);
        self::assertSame([], $summary['sections']);
        self::assertSame([], $summary['latest']);
        self::assertCount(12, $summary['months']);
    }

    // ─── fixtures ───

    /** @param list<array<string,mixed>> $sections */
    private function submitted(
        string $assessedOn,
        float $percentage,
        int $level,
        ?int $organizationId = null,
        array $sections = [],
    ): string {
        return $this->assessment($assessedOn, 'submitted', $organizationId, $percentage, $level, $sections);
    }

    private function draft(string $assessedOn): string
    {
        return $this->assessment($assessedOn, 'draft', null, null, null, []);
    }

    /** @param list<array<string,mixed>> $sections */
    private function assessment(
        string $assessedOn,
        string $status,
        ?int $organizationId,
        ?float $percentage,
        ?int $level,
        array $sections,
    ): string {
        $organizationId ??= $this->orgId;
        $uuid = BinaryUuid::v7();
        $bytes = BinaryUuid::toBytes($uuid);

        $templateId = $this->templateId;
        [$facility, $site] = $this->registry[$organizationId];

        TenantContext::withoutScope(function () use (
            $bytes,
            $templateId,
            $facility,
            $site,
            $organizationId,
            $assessedOn,
            $status,
            $percentage,
            $level,
            $sections,
        ): void {
            Capsule::table('assessments')->insert([
                'id'              => $bytes,
                'organization_id' => $organizationId,
                'facility_id'     => $facility,
                'testing_site_id' => $site,
                'template_id'     => $templateId,
                'assessed_on'     => $assessedOn,
                'status'          => $status,
                'submitted_at'    => $status === 'submitted' ? gmdate('Y-m-d H:i:s') : null,
            ]);

            if ($percentage === null) {
                return;
            }

            Capsule::table('assessment_scores')->insert([
                'assessment_id'   => $bytes,
                'organization_id' => $organizationId,
                'template_id'     => $templateId,
                'total_score'     => (int) round($percentage),
                'total_possible'  => 100,
                'percentage'      => $percentage,
                'level'           => $level,
                'breakdown'       => (string) json_encode(['sections' => $sections]),
            ]);
        });

        return $uuid;
    }
}
