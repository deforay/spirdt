<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Assessment;
use App\Models\Facility;
use App\Models\Template;
use App\Models\TestingSite;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;

/**
 * The country at a glance.
 *
 * This is the picture the predecessor's dashboard produced and this tool could
 * not: how many laboratories have been audited, how they are distributed across
 * the certification levels, whether that distribution is moving, and which
 * sections of the instrument are dragging the scores down.
 *
 * SUBMITTED VISITS ONLY, everywhere on this screen. A draft is a visit somebody
 * is part-way through; counting one would report a laboratory as scoring 12%
 * because eleven of fifty-nine questions have been answered so far. Drafts are
 * reported as a count on their own, because "nine visits started and not
 * finished" is a real thing to know — it is just not a score.
 *
 * EVERY FIGURE IS SCOPED TO THE ORGANISATION by the model scope, and the counts
 * that read across the registry — facilities covered — are bounded by the
 * assessments this organisation filed rather than by the registry itself, which
 * is shared across the programme.
 *
 * The section breakdown is read from `assessment_scores.breakdown`, which the
 * engine already writes. Recomputing it here would put a second scoring
 * implementation in the codebase, which is the one thing the shared fixtures
 * exist to prevent.
 */
final class DashboardService
{
    /** Levels 0 to 4, so a band nobody has reached still appears as zero. */
    private const LEVELS = [0, 1, 2, 3, 4];

    private const RECENT_DAYS = 30;

    private const MONTHS = 12;

    private const LATEST = 8;

    /**
     * @return array<string,mixed>
     */
    public function summary(string $locale = 'en'): array
    {
        return [
            'totals'   => $this->totals(),
            'levels'   => $this->levels(),
            'recent'   => $this->levels(since: gmdate('Y-m-d', strtotime('-' . self::RECENT_DAYS . ' days'))),
            'months'   => $this->byMonth(),
            'sections' => $this->sections($locale),
            'latest'   => $this->latest(),
            'bands'    => $this->bands($locale),
            // More than one means the counts pool visits judged by different
            // definitions of the same band. Reported so the screen can say so
            // rather than presenting a number that reads as comparable.
            'mixed_versions' => $this->versionsInScope() > 1,
        ];
    }

    /**
     * The headline counts.
     *
     * Facilities and sites are counted DISTINCT over assessments rather than
     * over the registry. "Twelve of three thousand facilities" is the honest
     * number and the registry alone cannot say it, because the registry belongs
     * to the programme and the assessments belong to one organisation in it.
     *
     * @return array<string,int>
     */
    private function totals(): array
    {
        $submitted = $this->submitted();

        return [
            'assessments' => (clone $submitted)->count(),
            'sites'       => (clone $submitted)->distinct()->count('assessments.testing_site_id'),
            'facilities'  => (clone $submitted)->distinct()->count('assessments.facility_id'),
            'drafts'      => Assessment::query()->where('assessments.status', 'draft')->count(),
            // What the registry holds, for the denominator. Programme-wide, so
            // it is the same number every organisation in the country sees.
            'known_sites' => TestingSite::query()->where('is_active', 1)->count(),
        ];
    }

    /**
     * How many visits landed in each certification level.
     *
     * The centrepiece. A country's SPI-RDT position is this distribution, and
     * the whole point of the instrument is that Level 3 means the same thing
     * everywhere — so this is a count per band and never an average of them.
     *
     * @return list<array{level:int,count:int}>
     */
    private function levels(?string $since = null): array
    {
        $query = $this->scored();

        if ($since !== null) {
            $query->where('assessments.assessed_on', '>=', $since);
        }

        $counts = [];

        foreach ($query->get(['assessment_scores.level']) as $row) {
            if ($row->level === null) {
                continue;
            }

            $level = (int) $row->level;
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        $rows = [];

        foreach (self::LEVELS as $level) {
            $rows[] = ['level' => $level, 'count' => $counts[$level] ?? 0];
        }

        return $rows;
    }

    /**
     * Visits and their mean score, by month.
     *
     * Twelve months including the empty ones, because a gap is the finding. A
     * chart drawn only from months that have data compresses a six-month pause
     * into a single tick and reads as steady work.
     *
     * @return list<array{month:string,count:int,mean:float|null}>
     */
    private function byMonth(): array
    {
        $from = gmdate('Y-m-01', strtotime('-' . (self::MONTHS - 1) . ' months'));

        $totals = [];

        foreach (
            $this->scored()
                ->where('assessments.assessed_on', '>=', $from)
                ->get(['assessments.assessed_on', 'assessment_scores.percentage']) as $row
        ) {
            // A date object, not a string — see namesFor() for the same trap.
            $month = $row->assessed_on?->format('Y-m');

            if ($month === null) {
                continue;
            }

            $totals[$month] ??= ['count' => 0, 'sum' => 0.0];
            $totals[$month]['count']++;
            $totals[$month]['sum'] += (float) $row->percentage;
        }

        $rows = [];

        for ($back = self::MONTHS - 1; $back >= 0; --$back) {
            $month = gmdate('Y-m', strtotime('-' . $back . ' months'));
            $bucket = $totals[$month] ?? null;

            $rows[] = [
                'month' => $month,
                'count' => $bucket['count'] ?? 0,
                'mean'  => $bucket === null ? null : round($bucket['sum'] / $bucket['count'], 1),
            ];
        }

        return $rows;
    }

    /**
     * Mean score per section of the instrument, weakest first.
     *
     * The predecessor called this "Poor Performance" and it is the most
     * actionable panel on the screen: it says which part of the standard a
     * country is failing, which is what a training programme is planned
     * against.
     *
     * Sections with nothing possible are skipped rather than shown as zero. A
     * section every visit marked not-applicable has no score, and printing 0%
     * beside it would name it as the worst problem in the country.
     *
     * @return list<array{code:string,name:string,mean:float,assessments:int}>
     */
    private function sections(string $locale): array
    {
        $names = $this->sectionNames($locale);
        $totals = [];

        foreach ($this->scored()->get(['assessment_scores.breakdown']) as $row) {
            $breakdown = json_decode((string) $row->breakdown, true);

            if (!is_array($breakdown) || !is_array($breakdown['sections'] ?? null)) {
                continue;
            }

            foreach ($breakdown['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $possible = (int) ($section['possible'] ?? 0);

                if ($possible <= 0 || ($section['applicable'] ?? true) === false) {
                    continue;
                }

                $code = (string) ($section['code'] ?? '');

                $totals[$code] ??= ['score' => 0, 'possible' => 0, 'count' => 0];
                $totals[$code]['score'] += (int) ($section['score'] ?? 0);
                $totals[$code]['possible'] += $possible;
                $totals[$code]['count']++;
            }
        }

        $rows = [];

        foreach ($totals as $code => $total) {
            $rows[] = [
                // Cast back: a section code of "1" becomes an INTEGER array
                // key on the way in, and would leave here as a number where
                // every other surface treats a code as a string.
                'code'        => (string) $code,
                'name'        => $names[$code] ?? $code,
                // Pooled rather than an average of per-visit percentages: a
                // visit with four applicable questions should not weigh the
                // same as one with twenty.
                'mean'        => round($total['score'] * 100 / $total['possible'], 1),
                'assessments' => $total['count'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['mean'] <=> $b['mean']);

        return $rows;
    }

    /**
     * The most recent submitted visits, named.
     *
     * Enough to recognise one and open it. The full, filterable list is the
     * reports screen; this is the "what has just come in" glance.
     *
     * @return list<array<string,mixed>>
     */
    private function latest(): array
    {
        $query = $this->scored();

        // Ordering is applied to the underlying query: orderBy reaches Eloquent
        // through __call and degrades the builder's type. The query is still
        // executed through Eloquent below, so the tenant scope still applies.
        $query->getQuery()->orderByDesc('assessments.assessed_on')->orderByDesc('assessments.id');

        $visits = $query->limit(self::LATEST)->get([
            'assessments.id',
            'assessments.facility_id',
            'assessments.testing_site_id',
            'assessments.assessed_on',
            'assessment_scores.percentage',
            'assessment_scores.level',
        ]);

        // The ids arrive as UUID STRINGS, not bytes: BinaryUuidCast converts
        // them on the way out of the model. Passing one straight into a where()
        // compares a 36-character string against a BINARY(16) column, matches
        // nothing, and produced a screen of unnamed rows.
        $names = $this->namesFor(
            array_filter($visits->pluck('facility_id')->all()),
            array_filter($visits->pluck('testing_site_id')->all()),
        );

        $rows = [];

        foreach ($visits as $visit) {
            $facilityId = $visit->facility_id === null ? null : (string) $visit->facility_id;
            $siteId = $visit->testing_site_id === null ? null : (string) $visit->testing_site_id;

            $rows[] = [
                'id'          => (string) $visit->id,
                'facility'    => $facilityId === null ? null : ($names['facilities'][$facilityId] ?? null),
                'site'        => $siteId === null ? null : ($names['sites'][$siteId] ?? null),
                // Cast to a date, so it arrives as a date object rather than a
                // string. Formatted rather than stringified, which would carry
                // a midnight time nobody recorded.
                'assessed_on' => $visit->assessed_on?->format('Y-m-d') ?? '',
                'percentage'  => $visit->percentage === null ? null : (float) $visit->percentage,
                'level'       => $visit->level === null ? null : (int) $visit->level,
            ];
        }

        return $rows;
    }

    /**
     * Facility and testing-site names for a page of visits, in two queries.
     *
     * Batched rather than looked up per row: eight visits would otherwise be
     * sixteen queries, and the panel exists to be glanced at.
     *
     * Both sides are scoped, so an id belonging to another programme resolves
     * to nothing and the row simply reads without a name.
     *
     * @param  list<string> $facilityIds
     * @param  list<string> $siteIds
     * @return array{facilities: array<string,string>, sites: array<string,string>}
     */
    private function namesFor(array $facilityIds, array $siteIds): array
    {
        $facilities = [];
        $sites = [];

        if ($facilityIds !== []) {
            foreach (
                Facility::query()
                    ->whereIn('facilities.id', array_map(BinaryUuid::toBytes(...), $facilityIds))
                    ->get(['facilities.id', 'facilities.name']) as $facility
            ) {
                $facilities[(string) $facility->id] = (string) $facility->name;
            }
        }

        if ($siteIds !== []) {
            foreach (
                TestingSite::query()
                    ->whereIn('testing_sites.id', array_map(BinaryUuid::toBytes(...), $siteIds))
                    ->get(['testing_sites.id', 'testing_sites.name']) as $site
            ) {
                $sites[(string) $site->id] = (string) $site->name;
            }
        }

        return ['facilities' => $facilities, 'sites' => $sites];
    }

    /**
     * The instrument these figures were actually answered against.
     *
     * NOT SIMPLY THE PUBLISHED ONE. An assessment pins the template version it
     * answered, and the bands are part of that version: if a programme is ever
     * allowed to raise Level 4 from 90% to 95%, a visit that scored 91% stays
     * stored as Level 4, and labelling its count with the new threshold would
     * report it against a rule it was never judged by.
     *
     * Taken from the most recent scored visit in scope, falling back to the
     * published template when there are none — a dashboard with no data still
     * has to name its bands.
     *
     * A caveat this cannot fix, and which is why `mixed_versions` is reported:
     * once two versions with different bands are both in scope, the counts
     * themselves are ill-defined, because "how many at Level 4" is being asked
     * of two different definitions of Level 4. That is the open question in
     * docs/todo.md about whether band thresholds may be customised at all. This
     * says so rather than quietly picking one.
     *
     * @return array<string,mixed>|null
     */
    private function instrument(): ?array
    {
        $query = $this->scored();
        $query->getQuery()->orderByDesc('assessments.assessed_on')->orderByDesc('assessments.id');

        $pinned = $query->limit(1)->value('assessments.template_id');

        $definition = $pinned === null
            ? Template::published(TenantContext::requireOrganizationId())?->definition
            : Template::query()->where('templates.id', (int) $pinned)->first()?->definition;

        return is_array($definition) ? $definition : null;
    }

    /** How many distinct instrument versions the figures above pool together. */
    private function versionsInScope(): int
    {
        return $this->scored()->distinct()->count('assessments.template_id');
    }

    /**
     * The band labels, from the instrument the visits answered.
     *
     * Read rather than hard-coded, for the same reason the registry's option
     * lists are: the thresholds are the instrument's own and a country may be
     * allowed to move them. A copy here would be one revision away from
     * labelling a score against a band that no longer exists.
     *
     * @return list<array{level:int,label:string,min_percent:float}>
     */
    private function bands(string $locale): array
    {
        // `definition` is cast to an array on the model, so it arrives
        // decoded. Decoding it again turns a 96 KB structure into null.
        $definition = $this->instrument();
        $bands = $definition === null ? null : ($definition['scoring']['bands'] ?? null);

        if (!is_array($bands)) {
            return [];
        }

        $rows = [];

        foreach ($bands as $band) {
            if (!is_array($band)) {
                continue;
            }

            $label = $band['label'] ?? [];

            $rows[] = [
                'level'       => (int) ($band['level'] ?? 0),
                'label'       => is_array($label)
                    ? (string) ($label[$locale] ?? $label['en'] ?? ('Level ' . ($band['level'] ?? 0)))
                    : (string) $label,
                'min_percent' => (float) ($band['min_percent'] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        return $rows;
    }

    /** @return array<string,string> section code => its name in this locale */
    private function sectionNames(string $locale): array
    {
        $definition = $this->instrument();
        $sections = $definition === null ? null : ($definition['sections'] ?? null);

        if (!is_array($sections)) {
            return [];
        }

        $names = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = $section['title'] ?? [];

            $names[(string) ($section['code'] ?? '')] = is_array($title)
                ? (string) ($title[$locale] ?? $title['en'] ?? '')
                : (string) $title;
        }

        return $names;
    }

    /**
     * Submitted visits in this organisation. The basis of every figure here.
     *
     * ELOQUENT ALL THE WAY DOWN, and never `->getQuery()`. The tenant scope is
     * a global scope, applied when an ELOQUENT builder executes. Reaching
     * through to the underlying query builder and running it there drops the
     * scope silently and returns every organisation's rows — which is exactly
     * what happened here, and what the cross-tenant test caught. `->getQuery()`
     * is safe only for shaping a query that is still executed through Eloquent,
     * which is how the ordering below uses it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Assessment>
     */
    private function submitted(): \Illuminate\Database\Eloquent\Builder
    {
        return Assessment::query()->where('assessments.status', 'submitted');
    }

    /**
     * The same, joined to their scores.
     *
     * An inner join on purpose: a submitted visit with no score row is one the
     * engine could not score, and including it would put a null in every
     * average on the screen.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Assessment>
     */
    private function scored(): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->submitted();

        // Joined on the underlying query and returned as the Eloquent builder.
        // join() reaches Eloquent through __call, which hands back the Eloquent
        // builder at run time but is typed as the query builder — the same
        // degradation orderBy has. Written this way so the type says what
        // actually comes back, because the difference between the two here is
        // whether the tenant scope applies.
        $query->getQuery()->join(
            'assessment_scores',
            'assessment_scores.assessment_id',
            '=',
            'assessments.id',
        );

        return $query;
    }
}
