<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentPathogen;
use App\Models\Attachment;
use App\Models\Finding;
use App\Models\Template;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Reading back what was collected.
 *
 * Everything a visit produced has been in the database since sync landed, and
 * until now nothing could show it: an assessor finished a site, the score was
 * computed and stored, and there was no screen anywhere that displayed it.
 * This is that read side, in two shapes.
 *
 * THE LIST is management's, and is the surface a dashboard sits on: which sites
 * were visited, when, and how they scored. It has to page and filter, because a
 * country running two rounds a year across a few hundred sites will have
 * thousands of rows within a couple of years.
 *
 * THE REPORT is one visit in full — the scores per section, every answer with
 * the question it answers, the findings, and who signed. It is what gets handed
 * to the site, so it says what was actually recorded rather than a summary of
 * it: an assessment that scored badly should show which answers made it so.
 *
 * NOTHING HERE RECOMPUTES A SCORE. The stored percentage and level are what the
 * engine produced from the answers as they stood when the visit was submitted,
 * and they are the record. Recomputing on read would make a report change
 * because the scoring code changed, which is exactly the property a certificate
 * must not have. `scoring_version` travels with the row so a number that later
 * looks wrong can be traced to the rules that produced it.
 */
final class ReportService
{
    /**
     * @see RegistryService::PAGE_SIZE — the same reasoning, and deliberately
     *      the same number so the two lists behave identically.
     */
    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly RegistryService $registry = new RegistryService(),
    ) {
    }

    /**
     * Visits, newest first, narrowed by where and when.
     *
     * The joins are LEFT rather than INNER on purpose. An INNER join drops the
     * row when the thing joined is missing, which here would mean an assessment
     * silently vanishing from the list because its score has not been written
     * or its site was tidied up — the two cases somebody most needs to see.
     *
     * Tenancy comes from Assessment's organisation scope, which is on the
     * driving table. The scores table is joined on organisation as well as on
     * assessment: it is reached through a plain join, so no model scope applies
     * to it, and the pair is what the unique key is on anyway.
     *
     * @param  array{geo_unit_id?:int|null,facility_id?:string|null,testing_site_id?:string|null,campaign_id?:int|null,status?:string|null,from?:string|null,to?:string|null,level?:int|null,search?:string|null} $filters
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, per_page: int}
     */
    public function assessments(array $filters = [], int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $perPage = max(1, min($perPage, 200));
        $page = max(1, $page);

        $query = Assessment::query();

        // Applied as statements rather than chained. Eloquent's builder
        // forwards a join to the underlying query builder and hands back
        // itself, so this is the same call — but written as a chain the
        // expression reads as the query builder, and the model scope that
        // makes this whole method tenant-safe reads as if it were gone.
        $query->leftJoin('testing_sites', 'testing_sites.id', '=', 'assessments.testing_site_id');
        $query->leftJoin('facilities', 'facilities.id', '=', 'assessments.facility_id');
        $query->leftJoin('assessment_scores', function ($join): void {
            $join->on('assessment_scores.assessment_id', '=', 'assessments.id')
                ->on('assessment_scores.organization_id', '=', 'assessments.organization_id');
        });
        $query->leftJoin('campaigns', 'campaigns.id', '=', 'assessments.campaign_id');

        $this->applyFilters($query, $filters);

        // Counted through the ELOQUENT builder, never through getQuery(). The
        // organisation scope is applied by the model as the query is run, so a
        // count taken off the bare query builder underneath skips it and
        // reports every organisation's visits — while the rows beside it,
        // fetched properly, show only this one. A total that disagrees with
        // the page it labels is the quietest possible way to leak the fact
        // that other organisations' assessments exist.
        //
        // Counted on the assessment's own key, distinctly. The joins can only
        // match one row each today, but a later join that fans out would
        // otherwise inflate the total with nothing to show for it.
        $total = (int) (clone $query)->distinct()->count('assessments.id');

        $query->getQuery()
            ->select([
                'assessments.id',
                'assessments.status',
                'assessments.assessed_on',
                'assessments.audit_round',
                'assessments.submitted_at',
                'assessments.campaign_id',
                'campaigns.name as campaign_name',
                'testing_sites.name as site_name',
                'facilities.name as facility_name',
                'facilities.code as facility_code',
                'facilities.geo_unit_id',
                'assessment_scores.total_score',
                'assessment_scores.total_possible',
                'assessment_scores.percentage',
                'assessment_scores.level',
                'assessment_scores.pathogen_count',
            ])
            // Newest visit first, and the id last so a page boundary cannot
            // fall in the middle of a set of assessments sharing one date and
            // show the same row on both pages.
            ->orderByDesc('assessments.assessed_on')
            ->orderByDesc('assessments.id')
            ->forPage($page, $perPage);

        $places = $this->registry->placePaths();
        $rows = [];

        foreach ($query->get() as $row) {
            $geoUnitId = $row->geo_unit_id === null ? null : (int) $row->geo_unit_id;

            $rows[] = [
                'id'             => (string) $row->id,
                'status'         => (string) $row->status,
                'assessed_on'    => $this->asDate($row->assessed_on),
                'audit_round'    => $row->audit_round,
                'submitted_at'   => $this->asDateTime($row->submitted_at),
                'campaign_id'    => $row->campaign_id === null ? null : (int) $row->campaign_id,
                'campaign'       => $row->campaign_name,
                'site'           => $row->site_name,
                'facility'       => $row->facility_name,
                'facility_code'  => $row->facility_code,
                'place'          => $geoUnitId === null ? null : ($places[$geoUnitId] ?? null),
                'total_score'    => $row->total_score === null ? null : (int) $row->total_score,
                'total_possible' => $row->total_possible === null ? null : (int) $row->total_possible,
                'percentage'     => $row->percentage === null ? null : (float) $row->percentage,
                'level'          => $row->level === null ? null : (int) $row->level,
                'pathogens'      => $row->pathogen_count === null ? null : (int) $row->pathogen_count,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param Builder<Assessment>                                                                                                                                                                               $query
     * @param array{geo_unit_id?:int|null,facility_id?:string|null,testing_site_id?:string|null,campaign_id?:int|null,status?:string|null,from?:string|null,to?:string|null,level?:int|null,search?:string|null} $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // A place means everything under it. Choosing a province and being told
        // there are no assessments — because visits attach to facilities, which
        // attach to districts — is the same bug the registry list had.
        if (isset($filters['geo_unit_id'])) {
            $query->whereIn('facilities.geo_unit_id', $this->registry->subtree((int) $filters['geo_unit_id']));
        }

        if (isset($filters['facility_id'])) {
            $query->where('assessments.facility_id', BinaryUuid::toBytes((string) $filters['facility_id']));
        }

        if (isset($filters['testing_site_id'])) {
            $query->where(
                'assessments.testing_site_id',
                BinaryUuid::toBytes((string) $filters['testing_site_id']),
            );
        }

        if (isset($filters['campaign_id'])) {
            $query->where('assessments.campaign_id', (int) $filters['campaign_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('assessments.status', (string) $filters['status']);
        }

        // Inclusive at both ends, because a date range typed by a human means
        // the days they typed.
        if (isset($filters['from']) && $filters['from'] !== '') {
            $query->where('assessments.assessed_on', '>=', (string) $filters['from']);
        }

        if (isset($filters['to']) && $filters['to'] !== '') {
            $query->where('assessments.assessed_on', '<=', (string) $filters['to']);
        }

        if (isset($filters['level'])) {
            $query->where('assessment_scores.level', (int) $filters['level']);
        }

        $term = isset($filters['search']) ? trim((string) $filters['search']) : '';

        if ($term !== '') {
            $escaped = addcslashes($term, '%_\\');

            $query->where(function ($inner) use ($escaped): void {
                $inner->where('testing_sites.name', 'like', '%' . $escaped . '%')
                    ->orWhere('facilities.name', 'like', '%' . $escaped . '%')
                    ->orWhere('facilities.code', 'like', $escaped . '%');
            });
        }
    }

    /**
     * One visit, in full.
     *
     * Assembled in a fixed number of queries regardless of how many questions
     * or findings there are — the answers, findings, pathogens and attachments
     * each come back in one read and are matched up in memory. Fifty-nine
     * questions is not much, but the report is also the thing somebody will
     * eventually loop over to build a batch of PDFs.
     *
     * @throws InvalidArgumentException when there is no such assessment HERE —
     *                                  another organisation's id and one that
     *                                  never existed are the same answer, on
     *                                  purpose
     * @return array<string,mixed>
     */
    public function report(string $assessmentId, string $locale = 'en'): array
    {
        if (!BinaryUuid::isValid($assessmentId)) {
            throw new InvalidArgumentException('That is not an assessment id.');
        }

        $assessment = Assessment::findByUuid($assessmentId);

        if ($assessment === null) {
            throw new InvalidArgumentException('No such assessment.');
        }

        $definition = $this->definitionFor((int) $assessment->template_id);
        $score = $this->scoreFor($assessmentId);

        return [
            'assessment' => $this->header($assessment, $locale),
            'score'      => $this->scoreSection($score, $definition, $locale),
            'sections'   => $this->answerSections($assessmentId, $definition, $locale),
            'findings'   => $this->findings($assessmentId, $definition, $locale),
            'signatures' => $this->signatures($assessmentId),
        ];
    }

    /**
     * Who, where and when.
     *
     * The place path and facility come from the registry rather than from
     * anything copied onto the assessment, so a facility renamed or moved since
     * the visit reads correctly today. The date of the visit does not move, and
     * that is the one the report is about.
     *
     * @return array<string,mixed>
     */
    private function header(Assessment $assessment, string $locale): array
    {
        $siteId = (string) $assessment->testing_site_id;
        $facilityId = (string) $assessment->facility_id;

        $site = Capsule::table('testing_sites')
            ->where('id', BinaryUuid::toBytes($siteId))
            ->first(['name', 'location_description']);

        $facility = Capsule::table('facilities')
            ->where('id', BinaryUuid::toBytes($facilityId))
            ->first(['name', 'code', 'geo_unit_id', 'facility_type', 'level', 'address']);

        $geoUnitId = $facility?->geo_unit_id === null ? null : (int) $facility->geo_unit_id;
        $places = $this->registry->placePaths();

        $pathogens = [];

        foreach (
            AssessmentPathogen::query()
                ->where('assessment_id', BinaryUuid::toBytes((string) $assessment->id))
                ->orderBy('sequence')
                ->get() as $pathogen
        ) {
            $pathogens[] = [
                'sequence'          => (int) $pathogen->sequence,
                'name'              => (string) $pathogen->pathogen_name,
                'tests_description' => $pathogen->tests_description,
            ];
        }

        return [
            'id'                    => (string) $assessment->id,
            'status'                => (string) $assessment->status,
            'locale'                => $locale,
            'assessed_on'           => $this->asDate($assessment->assessed_on),
            'audit_round'           => $assessment->audit_round,
            'started_at'            => $this->asDateTime($assessment->started_at),
            'ended_at'              => $this->asDateTime($assessment->ended_at),
            'submitted_at'          => $this->asDateTime($assessment->submitted_at),
            'previous_assessed_on'  => $this->asDate($assessment->previous_assessed_on),
            'refers_specimens'      => $assessment->refers_specimens === null
                ? null
                : (bool) $assessment->refers_specimens,
            // Whatever the template asked for beyond the fixed fields.
            'context'               => $assessment->context ?? [],
            'site'                  => [
                'id'       => $siteId,
                'name'     => $site?->name,
                // Where in the building — "the bench by the window". Written
                // down because two testing sites in one hospital are otherwise
                // told apart only by whoever remembers.
                'location' => $site?->location_description,
            ],
            'facility'              => [
                'id'      => $facilityId,
                'name'    => $facility?->name,
                'code'    => $facility?->code,
                'type'    => $facility?->facility_type,
                'level'   => $facility?->level,
                'address' => $facility?->address,
                'place'   => $geoUnitId === null ? null : ($places[$geoUnitId] ?? null),
            ],
            'pathogens'             => $pathogens,
        ];
    }

    /**
     * The number, the band it falls in, and the per-section detail behind it.
     *
     * The band comes from the TEMPLATE, not from a constant here. A country
     * that moves its thresholds moves them in one place, and — more to the
     * point — a report printed under last year's thresholds keeps describing
     * the band it was actually awarded, because the template it was assessed
     * against is the one being read.
     *
     * @param  object|null          $score row from assessment_scores, absent while a visit is still a draft
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function scoreSection(?object $score, array $definition, string $locale): array
    {
        if ($score === null) {
            return ['scored' => false];
        }

        $breakdown = $this->decode($score->breakdown);
        $level = $score->level === null ? null : (int) $score->level;
        $band = $this->band($definition, $level, $locale);

        /** @var array<int,array<string,mixed>> $titles */
        $titles = [];

        foreach ($this->sectionsOf($definition) as $section) {
            $titles[(int) ($section['number'] ?? 0)] = $section;
        }

        $sections = [];

        /** @var list<array<string,mixed>> $rows */
        $rows = is_array($breakdown['sections'] ?? null) ? $breakdown['sections'] : [];

        foreach ($rows as $row) {
            $number = (int) ($row['number'] ?? 0);
            $possible = (int) ($row['possible'] ?? 0);
            $earned = (int) ($row['score'] ?? 0);

            $sections[] = [
                'number'     => $number,
                'code'       => (string) ($row['code'] ?? ''),
                'title'      => $this->text($titles[$number]['title'] ?? null, $locale),
                'applicable' => (bool) ($row['applicable'] ?? true),
                'score'      => $earned,
                'possible'   => $possible,
                // Reported alongside the tallies rather than instead of them.
                // A section can only be compared with another section as a
                // proportion, and can only be checked as a pair of counts.
                'percentage' => $possible === 0 ? null : round($earned * 100 / $possible, 2),
                'answered'   => (int) ($row['answered'] ?? 0),
                // Questions the template allowed to be Not Applicable, and that
                // were. Excluded from BOTH sides of the division, which is why
                // a section can be worth less here than on another visit.
                'excluded'   => (int) ($row['excluded'] ?? 0),
            ];
        }

        return [
            'scored'          => true,
            'total_score'     => (int) $score->total_score,
            'total_possible'  => (int) $score->total_possible,
            'percentage'      => $score->percentage === null ? null : (float) $score->percentage,
            'level'           => $level,
            'band'            => $band,
            'pathogen_count'  => (int) $score->pathogen_count,
            'scoring_version' => (string) $score->scoring_version,
            'scored_at'       => $this->asDateTime($score->scored_at),
            'sections'        => $sections,
            'pathogens'       => is_array($breakdown['pathogens'] ?? null) ? $breakdown['pathogens'] : [],
            // Kept, and shown. An assessment accepted despite an answer the
            // template did not expect should say so on the report rather than
            // only in the column nobody opens.
            'anomalies'       => [
                'missing'    => is_array($breakdown['missing'] ?? null) ? $breakdown['missing'] : [],
                'unexpected' => is_array($breakdown['unexpected'] ?? null) ? $breakdown['unexpected'] : [],
                'violations' => is_array($breakdown['violations'] ?? null) ? $breakdown['violations'] : [],
            ],
        ];
    }

    /**
     * Every question, with what was answered and what that answer means.
     *
     * Driven by the TEMPLATE rather than by the answers, so a question nobody
     * answered appears as unanswered instead of not appearing. A report that
     * quietly omits what was skipped is the one way this screen could mislead.
     *
     * @param  array<string,mixed>       $definition
     * @return list<array<string,mixed>>
     */
    private function answerSections(string $assessmentId, array $definition, string $locale): array
    {
        /** @var array<string,list<array<string,mixed>>> $byQuestion */
        $byQuestion = [];

        foreach (
            Answer::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->get() as $answer
        ) {
            $byQuestion[(string) $answer->question_code][] = [
                'response'    => (string) $answer->response,
                'comment'     => $answer->comment,
                'pathogen_id' => $answer->pathogen_id === null ? null : (string) $answer->pathogen_id,
            ];
        }

        $labels = $this->responseLabels($definition, $locale);
        $pathogenNames = $this->pathogenNames($assessmentId);
        $findingCounts = $this->findingCountsByQuestion($assessmentId);

        $sections = [];

        foreach ($this->sectionsOf($definition) as $section) {
            $questions = [];

            /** @var list<array<string,mixed>> $definedQuestions */
            $definedQuestions = is_array($section['questions'] ?? null) ? $section['questions'] : [];

            foreach ($definedQuestions as $question) {
                $code = (string) ($question['code'] ?? '');
                $answers = [];

                foreach ($byQuestion[$code] ?? [] as $answer) {
                    $answers[] = [
                        'response'    => $answer['response'],
                        'label'       => $labels[$answer['response']] ?? $answer['response'],
                        'points'      => $this->points($definition, (string) $answer['response']),
                        'comment'     => $answer['comment'],
                        'pathogen_id' => $answer['pathogen_id'],
                        // Section 4 is answered once per pathogen, so the
                        // response on its own does not say what it is about.
                        'pathogen'    => $answer['pathogen_id'] === null
                            ? null
                            : ($pathogenNames[$answer['pathogen_id']] ?? null),
                    ];
                }

                $questions[] = [
                    'code'       => $code,
                    'text'       => $this->text($question['text'] ?? null, $locale),
                    'guidance'   => $this->text($question['guidance'] ?? null, $locale),
                    'na_allowed' => (bool) ($question['na_allowed'] ?? false),
                    'answers'    => $answers,
                    'findings'   => $findingCounts[$code] ?? 0,
                ];
            }

            $sections[] = [
                'number'    => (int) ($section['number'] ?? 0),
                'code'      => (string) ($section['code'] ?? ''),
                'title'     => $this->text($section['title'] ?? null, $locale),
                'scope'     => (string) ($section['scope'] ?? 'assessment'),
                'questions' => $questions,
            ];
        }

        return $sections;
    }

    /**
     * The gaps and what to do about them.
     *
     * Ordered so that the immediate ones come first and the ones with no
     * urgency recorded come last. A corrective action plan read top to bottom
     * should start with what cannot wait.
     *
     * @param  array<string,mixed>       $definition
     * @return list<array<string,mixed>>
     */
    private function findings(string $assessmentId, array $definition, string $locale): array
    {
        $questionText = [];

        foreach ($this->sectionsOf($definition) as $section) {
            /** @var list<array<string,mixed>> $questions */
            $questions = is_array($section['questions'] ?? null) ? $section['questions'] : [];

            foreach ($questions as $question) {
                $questionText[(string) ($question['code'] ?? '')] = $this->text($question['text'] ?? null, $locale);
            }
        }

        $pathogenNames = $this->pathogenNames($assessmentId);
        $rows = [];

        foreach (
            Finding::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->orderBy('question_code')
                ->orderBy('created_at')
                ->get() as $finding
        ) {
            $pathogenId = $finding->pathogen_id === null ? null : (string) $finding->pathogen_id;

            $rows[] = [
                'id'                   => (string) $finding->id,
                'question_code'        => (string) $finding->question_code,
                'question'             => $questionText[(string) $finding->question_code] ?? null,
                'response'             => (string) $finding->response,
                'gap'                  => (string) $finding->gap,
                'recommendation'       => $finding->recommendation,
                'responsibility_level' => (string) $finding->responsibility_level,
                'urgency'              => $finding->urgency,
                'responsible_person'   => $finding->responsible_person,
                'due_date'             => $this->asDate($finding->due_date),
                'status'               => (string) $finding->status,
                'closed_on'            => $this->asDate($finding->closed_on),
                'closure_note'         => $finding->closure_note,
                'pathogen'             => $pathogenId === null ? null : ($pathogenNames[$pathogenId] ?? null),
            ];
        }

        // Sorted here rather than in SQL: urgency is an ENUM whose declaration
        // order happens to be the order wanted, and depending on that would
        // make adding a value between the two silently reorder every report.
        $rank = ['immediate' => 0, 'follow_up' => 1];

        usort($rows, static function (array $a, array $b) use ($rank): int {
            $byUrgency = ($rank[$a['urgency']] ?? 2) <=> ($rank[$b['urgency']] ?? 2);

            return $byUrgency !== 0
                ? $byUrgency
                : strnatcmp($a['question_code'], $b['question_code']);
        });

        return $rows;
    }

    /**
     * Who signed, and where their signature lives.
     *
     * The image itself is not inlined: it is served by /attachments/{id}, which
     * exists precisely because these files sit outside the document root and
     * the organisation scope is the only thing keeping one tenant's signatures
     * away from another's. Base64ing them into every report would put a few
     * hundred kilobytes into a response that is mostly text, and cache badly.
     *
     * @return list<array<string,mixed>>
     */
    private function signatures(string $assessmentId): array
    {
        $rows = [];

        foreach (
            Attachment::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->where('kind', 'signature')
                ->orderBy('role')
                ->get() as $attachment
        ) {
            $rows[] = [
                'id'          => (string) $attachment->id,
                'role'        => $attachment->role,
                'signed_name' => $attachment->signed_name,
                'uploaded_at' => $this->asDateTime($attachment->uploaded_at),
                'url'         => '/api/attachments/' . (string) $attachment->id,
            ];
        }

        return $rows;
    }

    /** @return array<string,int> question code => how many findings hang off it */
    private function findingCountsByQuestion(string $assessmentId): array
    {
        $counts = [];

        foreach (
            Finding::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->get() as $finding
        ) {
            $code = (string) $finding->question_code;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string,string> pathogen uuid => name */
    private function pathogenNames(string $assessmentId): array
    {
        $names = [];

        foreach (
            AssessmentPathogen::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->get() as $pathogen
        ) {
            $names[(string) $pathogen->id] = (string) $pathogen->pathogen_name;
        }

        return $names;
    }

    /**
     * The scores row, read unscoped by model but bounded by organisation here.
     *
     * AssessmentScore has no single-column key, so it is read through the query
     * builder; the organisation predicate is therefore explicit rather than
     * inherited. The assessment itself has already been resolved through a
     * scoped query, so this can only narrow.
     */
    private function scoreFor(string $assessmentId): ?object
    {
        return Capsule::table('assessment_scores')
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('organization_id', TenantContext::requireOrganizationId())
            ->first();
    }

    /**
     * The template the visit was assessed against — by id, not the currently
     * published one.
     *
     * A report has to describe the questions that were actually asked. Reading
     * the published template instead would silently relabel every historic
     * report the day a new version is published, and drop the answers to any
     * question that version removed.
     *
     * @return array<string,mixed>
     */
    private function definitionFor(int $templateId): array
    {
        $template = Template::query()->find($templateId);

        if ($template === null) {
            return [];
        }

        $definition = $template->definition;

        return is_array($definition) ? $definition : [];
    }

    /**
     * @param  array<string,mixed>       $definition
     * @return list<array<string,mixed>>
     */
    private function sectionsOf(array $definition): array
    {
        /** @var list<array<string,mixed>> $sections */
        $sections = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];

        return $sections;
    }

    /**
     * @param  array<string,mixed>      $definition
     * @return array<string,mixed>|null
     */
    private function band(array $definition, ?int $level, string $locale): ?array
    {
        if ($level === null) {
            return null;
        }

        /** @var list<array<string,mixed>> $bands */
        $bands = is_array($definition['scoring']['bands'] ?? null) ? $definition['scoring']['bands'] : [];

        foreach ($bands as $band) {
            if ((int) ($band['level'] ?? -1) === $level) {
                return [
                    'level'       => $level,
                    'label'       => $this->text($band['label'] ?? null, $locale),
                    'description' => $this->text($band['description'] ?? null, $locale),
                    'min_percent' => (int) ($band['min_percent'] ?? 0),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,string>
     */
    private function responseLabels(array $definition, string $locale): array
    {
        /** @var array<string,mixed> $responses */
        $responses = is_array($definition['scoring']['responses'] ?? null)
            ? $definition['scoring']['responses']
            : [];

        $labels = [];

        foreach ($responses as $code => $response) {
            $labels[(string) $code] = $this->text(
                is_array($response) ? ($response['label'] ?? null) : null,
                $locale,
            ) ?? (string) $code;
        }

        return $labels;
    }

    /** @param array<string,mixed> $definition */
    private function points(array $definition, string $response): ?int
    {
        $points = $definition['scoring']['responses'][$response]['points'] ?? null;

        return $points === null ? null : (int) $points;
    }

    /**
     * One string out of a translated bundle, falling back rather than blanking.
     *
     * A report in a locale the template was never translated into should read
     * in English, not read as a page of empty headings.
     */
    private function text(mixed $bundle, string $locale): ?string
    {
        if (is_string($bundle)) {
            return $bundle;
        }

        if (!is_array($bundle)) {
            return null;
        }

        foreach ([$locale, 'en'] as $candidate) {
            if (isset($bundle[$candidate]) && is_string($bundle[$candidate])) {
                return $bundle[$candidate];
            }
        }

        $first = reset($bundle);

        return is_string($first) ? $first : null;
    }

    /** @return array<string,mixed> */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Dates cross the wire as plain ISO days, never as a timestamp in some zone. */
    private function asDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    private function asDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return (string) $value;
    }
}
