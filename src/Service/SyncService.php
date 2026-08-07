<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentPathogen;
use App\Models\AssessmentScore;
use App\Models\Facility;
use App\Models\Finding;
use App\Models\Template;
use App\Models\TestingSite;
use App\Scoring\ScoringEngine;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use App\Validation\ContextValidator;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Takes an assessment off a device and puts it in the database.
 *
 * The whole thing is built to be run twice. A device on a weak connection
 * cannot tell a request that failed from a response that was lost, so it
 * retries, and a retry must not produce a second copy of a site visit. Every
 * write here is an upsert on a natural key:
 *
 *   assessments           by the device-minted id
 *   assessment_pathogens  by (assessment, sequence)
 *   answers               by (assessment, question code, pathogen)
 *   findings              by the device-minted id
 *   assessment_scores     by assessment
 *
 * All of it in one transaction. A half-synced assessment scores against
 * whatever arrived, and a partial score is worse than none — it looks like a
 * result.
 */
final class SyncService
{
    public function __construct(private readonly ScoringEngine $engine = new ScoringEngine())
    {
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed> the acknowledgement the device needs to mark rows clean
     */
    public function accept(array $payload): array
    {
        $organizationId = TenantContext::requireOrganizationId();

        $assessmentId = $this->requireUuid($payload, 'id');
        $templateCode = $this->requireString($payload, 'template_code');
        $templateVersion = $this->requireString($payload, 'template_version');

        $template = Template::resolve($organizationId, $templateCode, $templateVersion);

        if ($template === null) {
            throw new InvalidArgumentException(
                "No published template {$templateCode} {$templateVersion} for this organisation.",
            );
        }

        // Read before the transaction so a cross-tenant id is refused rather
        // than rolled back. The scope means an assessment belonging to another
        // organisation resolves to null here, and the insert below then fails
        // on the primary key rather than silently taking it over.
        $existing = Assessment::findByUuid($assessmentId);

        if ($existing === null && $this->existsInAnotherOrganization($assessmentId)) {
            throw new RuntimeException('That assessment belongs to another organisation.');
        }

        $this->requireOwnSite($payload);
        $this->requireValidContext($payload, $template);

        return Capsule::connection()->transaction(function () use (
            $payload,
            $organizationId,
            $assessmentId,
            $template,
        ): array {
            $assessment = $this->upsertAssessment($payload, $organizationId, $assessmentId, (int) $template->id);
            $pathogenIds = $this->upsertPathogens($payload, $organizationId, $assessmentId);
            $accepted = $this->upsertAnswers($payload, $organizationId, $assessmentId, $pathogenIds);
            $acceptedFindings = $this->upsertFindings($payload, $organizationId, $assessmentId, $pathogenIds);

            $score = $this->snapshotScore($assessment, $template, $organizationId);

            $this->requireSubmittable($assessment, $score, $payload);

            return [
                'assessment_id'     => $assessmentId,
                'accepted'          => $accepted,
                'accepted_findings' => $acceptedFindings,
                'score'             => $score,
            ];
        });
    }

    /**
     * @param  array<string,mixed> $payload
     */
    private function upsertAssessment(
        array $payload,
        int $organizationId,
        string $assessmentId,
        int $templateId,
    ): Assessment {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        $assessment = Assessment::findByUuid($assessmentId);
        $status = $this->status($payload, $assessment);

        $attributes = [
            'organization_id'  => $organizationId,
            'template_id'      => $templateId,
            'testing_site_id'  => $this->requireUuid($payload, 'testing_site_id'),
            'facility_id'      => $this->requireUuid($payload, 'facility_id'),
            'assessed_on'      => $this->requireString($payload, 'assessed_on'),
            'status'           => $status,
            'context'          => $context,
            'refers_specimens' => $this->refersSpecimens($context),
            'started_at'       => $payload['started_at'] ?? null,
            'ended_at'         => $payload['ended_at'] ?? null,
            'device_id'        => $payload['device_id'] ?? null,
            'app_version'      => $payload['app_version'] ?? null,
        ];

        $attributes += $this->locationOf($payload, $assessment);

        // Stamped once, when the visit is actually submitted. Re-stamping on
        // every retry would make the audit trail say the visit was submitted
        // whenever the device last had a signal.
        if ($status === 'submitted' && ($assessment === null || $assessment->submitted_at === null)) {
            $attributes['submitted_at'] = gmdate('Y-m-d H:i:s');
        }

        if ($assessment === null) {
            $assessment = new Assessment();
            $assessment->id = $assessmentId;
        }

        $assessment->fill($attributes);
        $assessment->save();

        return $assessment;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string> pathogen key from the device => stored UUID
     */
    private function upsertPathogens(array $payload, int $organizationId, string $assessmentId): array
    {
        $rows = is_array($payload['pathogens'] ?? null) ? $payload['pathogens'] : [];
        $ids = [];
        $sequence = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            ++$sequence;

            $key = (string) ($row['key'] ?? $row['id'] ?? $sequence);
            $id = isset($row['id']) && is_string($row['id']) && BinaryUuid::isValid($row['id'])
                ? $row['id']
                : null;

            $existing = AssessmentPathogen::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->where('sequence', $sequence)
                ->first();

            if ($existing === null) {
                $existing = new AssessmentPathogen();
                $existing->id = $id ?? BinaryUuid::v7();
                $existing->assessment_id = $assessmentId;
                $existing->sequence = $sequence;
            }

            $existing->organization_id = $organizationId;
            $existing->pathogen_name = (string) ($row['name'] ?? $key);
            $existing->tests_description = $row['tests'] ?? null;
            $existing->save();

            $ids[$key] = (string) $existing->id;
        }

        return $ids;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,string> $pathogenIds
     * @return list<string>         natural keys the device may now mark clean
     */
    private function upsertAnswers(
        array $payload,
        int $organizationId,
        string $assessmentId,
        array $pathogenIds,
    ): array {
        $rows = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $accepted = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $questionCode = (string) ($row['question_code'] ?? '');
            $response = (string) ($row['response'] ?? '');

            if ($questionCode === '' || !in_array($response, ['Y', 'P', 'N', 'NA'], true)) {
                continue;
            }

            $pathogenKey = $row['pathogen'] ?? null;
            $pathogenId = is_string($pathogenKey) && $pathogenKey !== ''
                ? ($pathogenIds[$pathogenKey] ?? null)
                : null;

            // A pathogen the payload never declared. Skipping rather than
            // storing it unattached: an answer with no pathogen would be
            // counted as an assessment-scoped one and quietly change the
            // denominator.
            if (is_string($pathogenKey) && $pathogenKey !== '' && $pathogenId === null) {
                continue;
            }

            $existing = Answer::query()
                ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
                ->where('question_code', $questionCode)
                ->where(
                    'pathogen_key',
                    $pathogenId === null ? str_repeat("\0", 16) : BinaryUuid::toBytes($pathogenId),
                )
                ->first();

            if ($existing === null) {
                $existing = new Answer();
                $existing->assessment_id = $assessmentId;
                $existing->question_code = $questionCode;
                $existing->pathogen_id = $pathogenId;
            }

            $existing->organization_id = $organizationId;
            $existing->response = $response;
            $existing->comment = $row['comment'] ?? null;
            $existing->answered_at = $row['answered_at'] ?? gmdate('Y-m-d H:i:s');
            $existing->save();

            $accepted[] = $questionCode . '|' . ($pathogenKey ?? '');
        }

        return $accepted;
    }

    /**
     * Gaps recorded during the visit.
     *
     * ONE QUESTION MAY CARRY SEVERAL. A single No can hide more than one
     * problem — no SOP, and the staff untrained on the one that is missing —
     * and each needs its own recommendation, owner and date. Collapsing them
     * into one text box produces an action list nobody can work from.
     *
     * So the upsert keys on the finding's own device-minted id rather than on
     * the answer's natural key. That is what makes a retry correct rather than
     * duplicate now that the natural key is no longer unique — and duplicates
     * matter more here than anywhere else, because findings become a site's
     * action list and the same gap three times is three things to chase.
     *
     * An id the server has never seen is not always a new finding. A device
     * that synced before the key changed is holding rows it has since re-keyed
     * locally, and it sends the key it used to use with them so those match
     * back to what is already stored instead of arriving twice.
     *
     * Only a Partial or a No may carry one. Anything else is dropped rather
     * than stored, because a finding against a Yes has nothing to describe and
     * would sit in the action list with no shortfall behind it.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,string> $pathogenIds
     * @return list<string>         finding ids the device may now mark clean
     */
    private function upsertFindings(
        array $payload,
        int $organizationId,
        string $assessmentId,
        array $pathogenIds,
    ): array {
        $rows = is_array($payload['findings'] ?? null) ? $payload['findings'] : [];
        $accepted = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $findingId = (string) ($row['id'] ?? '');
            $questionCode = (string) ($row['question_code'] ?? '');
            $response = (string) ($row['response'] ?? '');
            $gap = trim((string) ($row['gap'] ?? ''));

            if ($questionCode === '' || $gap === '' || !in_array($response, ['P', 'N'], true)) {
                continue;
            }

            // The id is the identity now, so a payload without one cannot be
            // upserted — accepting it would insert a fresh row on every retry.
            // Dropped rather than refused: the assessment is worth storing even
            // when one finding comes from a build that predates this.
            if (!BinaryUuid::isValid($findingId)) {
                continue;
            }

            $pathogenKey = $row['pathogen'] ?? null;
            $pathogenId = is_string($pathogenKey) && $pathogenKey !== ''
                ? ($pathogenIds[$pathogenKey] ?? null)
                : null;

            if (is_string($pathogenKey) && $pathogenKey !== '' && $pathogenId === null) {
                continue;
            }

            $level = (string) ($row['responsibility_level'] ?? 'site');

            if (!in_array($level, ['site', 'facility', 'district', 'regional', 'national'], true)) {
                $level = 'site';
            }

            $urgency = $row['urgency'] ?? null;

            // Blank means nobody said, which is different from follow-up.
            // Defaulting would invent a judgement the assessor never made.
            if (!in_array($urgency, ['immediate', 'follow_up'], true)) {
                $urgency = null;
            }

            $existing = Finding::query()
                ->where('findings.id', BinaryUuid::toBytes($findingId))
                ->first();

            // An id the server has never seen is usually a new finding. It is
            // the same finding under a new name only when the device says so.
            // See adoptServerKeyedFinding.
            if ($existing === null && $this->declaresPreviousKey($row, $assessmentId, $questionCode, $pathogenKey)) {
                $existing = $this->adoptServerKeyedFinding($assessmentId, $questionCode, $pathogenId);

                if ($existing !== null) {
                    $existing->id = $findingId;
                }
            }

            if ($existing === null) {
                $existing = new Finding();
                $existing->id = $findingId;
                $existing->assessment_id = $assessmentId;
            } elseif ((string) $existing->assessment_id !== $assessmentId) {
                // The id belongs to another visit. Moving a finding between
                // assessments would detach it from the answer that justifies
                // it, and the scope above already means it cannot belong to
                // another organisation — so this is a malformed payload.
                continue;
            }

            $existing->organization_id = $organizationId;
            // Set on every path, so that a row adopted above stops being
            // adoptable the moment it is claimed — including by the next
            // finding in this same payload.
            $existing->id_origin = 'device';
            $existing->question_code = $questionCode;
            $existing->pathogen_id = $pathogenId;
            $existing->response = $response;
            $existing->gap = $gap;
            $existing->recommendation = $row['recommendation'] ?? null;
            $existing->responsibility_level = $level;
            $existing->urgency = $urgency;
            $existing->responsible_person = $row['responsible_person'] ?? null;
            $existing->due_date = $row['due_date'] ?? null;

            // status and closure are NOT taken from the payload. A device holds
            // a stale copy of a finding an administrator may have closed since,
            // and letting a sync carry it would reopen closed work.
            $existing->save();

            $accepted[] = $findingId;
        }

        return $accepted;
    }

    /**
     * Whether this finding claims to be one the server already has.
     *
     * The device sends `previous_key` only on a row its version 5 upgrade
     * re-keyed, and that key is the one the old server itself keyed on:
     * assessment, question code, pathogen, joined by pipes. So it is
     * reconstructible here, and it is checked rather than trusted — a value
     * that does not match the finding it arrived with describes some other
     * row, and adopting on it would move a gap onto the wrong question.
     *
     * A mismatch means no adoption, so the finding is stored as new. That is
     * the safe direction: a duplicate is visible and can be closed, whereas a
     * finding written over another one is gone.
     *
     * @param array<string,mixed> $row
     */
    private function declaresPreviousKey(
        array $row,
        string $assessmentId,
        string $questionCode,
        mixed $pathogenKey,
    ): bool {
        $declared = $row['previous_key'] ?? null;

        if (!is_string($declared) || $declared === '') {
            return false;
        }

        $pathogen = is_string($pathogenKey) ? $pathogenKey : '';

        return $declared === "{$assessmentId}|{$questionCode}|{$pathogen}";
    }

    /**
     * The same finding under a name the server already knows it by.
     *
     * Findings used to be identified by (assessment, question, pathogen) and
     * given a server-minted id. They are identified by the device's own id
     * now, and the device's version 5 upgrade re-keys everything it holds —
     * it has to, because the old composite key is not a UUID and is refused.
     * It cannot reuse the server's id, having never been told it. So the first
     * sync after that upgrade presents a finding the server already stored,
     * under an id it has never seen.
     *
     * Left alone that inserts a second copy, and findings are the one place
     * where a duplicate really hurts: they become the site's action list, and
     * the same gap twice is two things to chase and two people chasing them.
     *
     * Two things keep this from being a guess. The caller has already checked
     * that the device declared the old key, so a finding raised after the
     * upgrade never reaches here — which is what stops the second gap on a
     * question from landing on top of the first, the case findings v2 exists
     * to allow. And `id_origin` means only a row the old server keyed can be
     * taken, so a replayed payload cannot reach a row a device has since
     * claimed. The old key was unique, so there is at most one candidate.
     *
     * Unordered because of that: with at most one row to find there is nothing
     * to order, and orderBy reaches Eloquent through __call, which degrades the
     * builder's type and leaves the return unverifiable.
     *
     * Delete this once no device can still be on version 4. It is a bridge,
     * and a bridge that outlives its crossing is just a way in.
     */
    private function adoptServerKeyedFinding(
        string $assessmentId,
        string $questionCode,
        ?string $pathogenId,
    ): ?Finding {
        $query = Finding::query()
            ->where('findings.assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('findings.question_code', $questionCode)
            ->where('findings.id_origin', 'server');

        if ($pathogenId === null) {
            $query->whereNull('findings.pathogen_id');
        } else {
            $query->where('findings.pathogen_id', BinaryUuid::toBytes($pathogenId));
        }

        return $query->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotScore(Assessment $assessment, Template $template, int $organizationId): array
    {
        $assessmentId = (string) $assessment->id;

        $pathogens = AssessmentPathogen::query()
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->orderBy('sequence')
            ->get();

        $pathogenNames = [];
        $byId = [];
        foreach ($pathogens as $pathogen) {
            $name = (string) $pathogen->pathogen_name;
            $pathogenNames[] = $name;
            $byId[(string) $pathogen->id] = $name;
        }

        $answers = [];
        foreach (
            Answer::query()->where('assessment_id', BinaryUuid::toBytes($assessmentId))->get() as $answer
        ) {
            $pathogenId = $answer->pathogen_id === null ? null : (string) $answer->pathogen_id;

            $answers[] = [
                'question_code' => (string) $answer->question_code,
                'pathogen'      => $pathogenId === null ? null : ($byId[$pathogenId] ?? null),
                'response'      => (string) $answer->response,
                // Carried so the engine can tell an explained gap from an
                // unexplained one. The score is identical either way; what it
                // changes is whether the visit may be submitted.
                'comment'       => $answer->comment,
            ];
        }

        $definition = is_array($template->definition) ? $template->definition : [];
        $context = is_array($assessment->context) ? $assessment->context : [];

        $result = $this->engine->score($definition, $answers, $context, $pathogenNames);

        AssessmentScore::query()->updateOrInsert(
            ['assessment_id' => BinaryUuid::toBytes($assessmentId)],
            [
                'organization_id' => $organizationId,
                'template_id'     => (int) $template->id,
                'pathogen_count'  => $result->pathogenCount,
                'total_score'     => $result->totalScore,
                'total_possible'  => $result->totalPossible,
                'percentage'      => $result->percentage,
                'level'           => $result->level,
                'breakdown'       => json_encode($result->toBreakdown(), JSON_THROW_ON_ERROR),
                'scoring_version' => $result->scoringVersion,
                'scored_at'       => gmdate('Y-m-d H:i:s'),
            ],
        );

        return [
            'total_score'    => $result->totalScore,
            'total_possible' => $result->totalPossible,
            'percentage'     => $result->percentage,
            'level'          => $result->level,
            'is_complete'    => $result->isComplete(),
            'is_valid'       => $result->isValid(),
            'missing'        => $result->missing,
            // Answered, but with a gap the template obliges the assessor to
            // explain and nothing written against it. The device shows these
            // so they can be filled in; requireSubmittable refuses on them.
            'missing_notes'  => $result->missingNotes,
            'violations'     => $result->violations,
        ];
    }

    /**
     * Where the visit happened, and which of two answers that is.
     *
     * A reading from the device is what the predecessor collected and what a
     * map wants: it says where the assessor was standing, which is evidence of
     * the visit. It is often impossible — indoors, in a basement, on a laptop,
     * or where permission was refused — and a map with holes in it is less
     * useful than one falling back to the registry.
     *
     * So the facility's own coordinates are the fallback, and location_source
     * records which was used. What the fallback must never do is pass as the
     * other: an analysis of where assessors actually went has to be able to
     * exclude the inherited ones.
     *
     * A payload with no reading does not clear one already stored. A visit
     * syncs repeatedly and the device may get a fix on only one of those
     * attempts; the later silence is absence of news rather than news.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function locationOf(array $payload, ?Assessment $existing): array
    {
        $latitude = $this->coordinate($payload, 'latitude', 90);
        $longitude = $this->coordinate($payload, 'longitude', 180);

        if ($latitude !== null && $longitude !== null) {
            $accuracy = $payload['accuracy_m'] ?? null;
            $takenAt = $payload['located_at'] ?? null;

            return [
                'latitude'        => $latitude,
                'longitude'       => $longitude,
                'accuracy_m'      => is_numeric($accuracy) ? (int) $accuracy : null,
                'location_source' => 'device',
                'located_at'      => is_string($takenAt) && strtotime($takenAt) !== false
                    ? gmdate('Y-m-d H:i:s', (int) strtotime($takenAt))
                    : null,
            ];
        }

        // Already positioned by an earlier sync of the same visit. Leave it.
        if ($existing !== null && $existing->latitude !== null) {
            return [];
        }

        $facility = Facility::query()
            ->where('facilities.id', BinaryUuid::toBytes($this->requireUuid($payload, 'facility_id')))
            ->first();

        if ($facility === null || $facility->latitude === null || $facility->longitude === null) {
            return [];
        }

        return [
            'latitude'        => (float) $facility->latitude,
            'longitude'       => (float) $facility->longitude,
            // The registry has no accuracy to report, and inventing one would
            // make an inherited pin indistinguishable from a measured fix.
            'accuracy_m'      => null,
            'location_source' => 'facility',
            'located_at'      => null,
        ];
    }

    /**
     * A coordinate the device sent, or null when absent or out of range.
     *
     * @param array<string,mixed> $payload
     */
    private function coordinate(array $payload, string $key, float $limit): ?float
    {
        $value = $payload[$key] ?? null;

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        // Out of range is a broken client rather than a place. Dropped rather
        // than refused: a visit is worth storing without its position.
        return abs($number) > $limit ? null : $number;
    }

    /**
     * A SUBMITTED visit must actually be finished.
     *
     * docs/scoring.md has said for some time that the submission endpoint is
     * what refuses, on is_complete and is_valid. It did not. The device
     * disabled its own button and the server accepted whatever arrived, which
     * makes the rule a property of one build of one client rather than of the
     * record.
     *
     * A DRAFT is accepted incomplete, deliberately and importantly. Syncing
     * mid-visit is how an assessor gets work off a tablet before losing it, and
     * refusing that would make the safest thing they can do the thing that
     * fails. Only the claim that the visit is finished is checked.
     *
     * Three ways it can be unfinished, and they are different failures:
     * questions never answered, gaps recorded with no explanation, and answers
     * the template forbids. Each is named, because "the assessment is
     * incomplete" sends somebody back through fifty-nine questions.
     *
     * @param  array<string,mixed>      $score
     * @param  array<string,mixed>      $payload
     * @throws InvalidArgumentException
     */
    private function requireSubmittable(Assessment $assessment, array $score, array $payload): void
    {
        // What THIS payload claims, not what the assessment already is. A
        // draft arriving after the submission it precedes — a retry from a
        // device that was offline — must not be re-judged against rules the
        // visit already satisfied when it was submitted. And it cannot be used
        // to slip past them either: a payload saying draft leaves the status
        // where it is, so becoming submitted still means saying so and being
        // checked for it.
        if (($payload['status'] ?? null) !== 'submitted') {
            return;
        }

        $missing = is_array($score['missing'] ?? null) ? $score['missing'] : [];
        $notes = is_array($score['missing_notes'] ?? null) ? $score['missing_notes'] : [];
        $violations = is_array($score['violations'] ?? null) ? $score['violations'] : [];

        $problems = [];

        if ($missing !== []) {
            $problems[] = count($missing) . ' unanswered: ' . $this->firstFew($missing);
        }

        if ($notes !== []) {
            $problems[] = count($notes) . ' without the required note: ' . $this->firstFew($notes);
        }

        if ($violations !== []) {
            $problems[] = count($violations) . ' not permitted: ' . $this->firstFew($violations);
        }

        $unaddressed = $this->gapsWithNoAction($payload);

        if ($unaddressed !== []) {
            $problems[] = count($unaddressed)
                . ' with no corrective action: ' . $this->firstFew($unaddressed);
        }

        if ($problems === []) {
            return;
        }

        throw new InvalidArgumentException(
            'This visit cannot be submitted yet — ' . implode('; ', $problems) . '.',
        );
    }

    /**
     * Partials and Nos that nobody has said what to do about.
     *
     * A comment is optional on every question — an observation against a Yes
     * is as worth recording as one against a No — so what a gap obliges is not
     * words but an ACTION: something described, owned and dated, which is what
     * the site is left with when the assessor drives away. A visit that
     * records twenty shortfalls and no corrective actions has measured a site
     * without helping it, and the whole point of the instrument is the second
     * part.
     *
     * Read from the payload rather than from the database because a device
     * sends only what is dirty: the findings already stored are not
     * necessarily in this request, so this checks what has arrived against
     * what has arrived. The device applies the same rule before enabling its
     * button, and the two agree because both read the answers beside the
     * findings.
     *
     * @param  array<string,mixed> $payload
     * @return list<string>        natural keys, for the message
     */
    private function gapsWithNoAction(array $payload): array
    {
        $described = [];

        foreach (is_array($payload['findings'] ?? null) ? $payload['findings'] : [] as $row) {
            if (!is_array($row) || trim((string) ($row['gap'] ?? '')) === '') {
                continue;
            }

            $key = (string) ($row['question_code'] ?? '') . '|' . (string) ($row['pathogen'] ?? '');
            $described[$key] = true;
        }

        $unaddressed = [];

        foreach (is_array($payload['answers'] ?? null) ? $payload['answers'] : [] as $row) {
            if (!is_array($row) || !in_array($row['response'] ?? '', ['P', 'N'], true)) {
                continue;
            }

            $key = (string) ($row['question_code'] ?? '') . '|' . (string) ($row['pathogen'] ?? '');

            if (!isset($described[$key])) {
                $unaddressed[] = $key;
            }
        }

        return $unaddressed;
    }

    /**
     * Enough to act on, not the whole list.
     *
     * Fifty-nine question codes in an error message is a wall of text the
     * device shows to somebody standing in a laboratory. The count says how
     * much is left and the first few say where to start.
     *
     * @param list<mixed> $items
     */
    private function firstFew(array $items): string
    {
        $shown = array_slice(array_map('strval', $items), 0, 3);

        return implode(', ', $shown) . (count($items) > 3 ? '…' : '');
    }

    /**
     * Part A must satisfy the limits the template declares.
     *
     * The device checks the same rules from the same template as the assessor
     * types, so this should never fire in practice. That is the point of
     * having it: it is what makes the device's check a convenience rather than
     * the only thing standing between a typing slip and the record. A build
     * from before a constraint existed, a payload replayed by hand, a client
     * somebody wrote themselves — none of them are stopped by a form.
     *
     * Refused whole rather than stored with the bad field dropped. A visit
     * missing the date of the previous assessment reads as a site that has
     * never been assessed, and question 1.8 — have gaps from the last
     * assessment been addressed — is then answered against nothing. The device
     * marks the assessment blocked and shows the assessor which field, which
     * is a thing they can act on.
     *
     * @param  array<string,mixed>      $payload
     * @throws InvalidArgumentException
     */
    private function requireValidContext(array $payload, Template $template): void
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $definition = is_array($template->definition) ? $template->definition : [];

        $problems = (new ContextValidator())->validate($definition, $context);

        if ($problems === []) {
            return;
        }

        // Named, not counted. "Two fields are invalid" sends somebody hunting
        // through Part A; naming them is the difference between a message and
        // an instruction.
        $fields = implode(', ', array_map(static fn ($problem): string => $problem->field, $problems));

        throw new InvalidArgumentException(
            "Part A has answers the instrument does not allow: {$fields}.",
        );
    }

    /**
     * The site and facility must belong to the caller's programme.
     *
     * The foreign keys only require that the rows exist SOMEWHERE, and the
     * tenant comes from the token rather than the body — so without this a
     * payload naming another programme's site is stored happily. Nothing
     * leaks, because the assessment still lands in the caller's own tenant and
     * the site itself stays unreadable, but the visit ends up attached to a
     * site the caller cannot resolve: it reads as blank in every report, and
     * anyone who learns a site id can plant references into it.
     *
     * Two organisations inside ONE programme naming the same site is not an
     * error — it is the point of the programme layer, and the case that makes
     * comparing their results possible.
     *
     * The pairing is checked too. A site and a facility can each be visible to
     * the caller and still not belong together, which would file the visit
     * under the wrong facility.
     *
     * @param  array<string,mixed>      $payload
     * @throws InvalidArgumentException
     */
    private function requireOwnSite(array $payload): void
    {
        $siteId = $this->requireUuid($payload, 'testing_site_id');
        $facilityId = $this->requireUuid($payload, 'facility_id');

        // Scoped queries: a row from another PROGRAMME resolves to null here.
        // Deliberately not another organisation — the registry is shared
        // within a programme, so that two organisations auditing the same lab
        // reference one row rather than two similar ones.
        $site = TestingSite::query()
            ->where('testing_sites.id', BinaryUuid::toBytes($siteId))
            ->first();

        if ($site === null) {
            throw new InvalidArgumentException('That testing site is not in this programme.');
        }

        $facility = Facility::query()
            ->where('facilities.id', BinaryUuid::toBytes($facilityId))
            ->first();

        if ($facility === null) {
            throw new InvalidArgumentException('That facility is not in this programme.');
        }

        if ((string) $site->facility_id !== $facilityId) {
            throw new InvalidArgumentException('That testing site does not belong to that facility.');
        }
    }

    /**
     * What the visit's status becomes.
     *
     * A device syncs mid-visit to get work off the tablet, so a payload may
     * legitimately say `draft`. Two rules keep that from rewriting history:
     * anything other than draft or submitted is ignored rather than trusted,
     * and a visit that has moved past draft never goes back. A retry can
     * arrive after the submission it precedes, and without the second rule a
     * lost response from an hour ago would reopen a finalised assessment.
     *
     * @param array<string,mixed> $payload
     */
    private function status(array $payload, ?Assessment $existing): string
    {
        if ($existing !== null && $existing->status !== 'draft') {
            return (string) $existing->status;
        }

        $requested = $payload['status'] ?? null;

        return is_string($requested) && in_array($requested, ['draft', 'submitted'], true)
            ? $requested
            : 'submitted';
    }

    /** @param array<string,mixed> $context */
    private function refersSpecimens(array $context): ?bool
    {
        $value = $context['refers_specimens'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['yes', 'y', 'true', '1'], true);
        }

        return null;
    }

    private function existsInAnotherOrganization(string $assessmentId): bool
    {
        return TenantContext::withoutScope(
            static fn (): bool => Assessment::acrossOrganizations()
                ->where('assessments.id', BinaryUuid::toBytes($assessmentId))
                ->exists(),
        );
    }

    /** @param array<string,mixed> $payload */
    private function requireUuid(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || !BinaryUuid::isValid($value)) {
            throw new InvalidArgumentException("{$key} must be a UUID.");
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$key} is required.");
        }

        return $value;
    }
}
