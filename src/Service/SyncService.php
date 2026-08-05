<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentPathogen;
use App\Models\AssessmentScore;
use App\Models\Template;
use App\Scoring\ScoringEngine;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
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

        return Capsule::connection()->transaction(function () use (
            $payload,
            $organizationId,
            $assessmentId,
            $template,
        ): array {
            $assessment = $this->upsertAssessment($payload, $organizationId, $assessmentId, (int) $template->id);
            $pathogenIds = $this->upsertPathogens($payload, $organizationId, $assessmentId);
            $accepted = $this->upsertAnswers($payload, $organizationId, $assessmentId, $pathogenIds);

            $score = $this->snapshotScore($assessment, $template, $organizationId);

            return [
                'assessment_id' => $assessmentId,
                'accepted'      => $accepted,
                'score'         => $score,
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
                $existing->id = $id ?? $this->uuidv7();
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
            'violations'     => $result->violations,
        ];
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

    /** Server-side UUIDv7, for rows the device did not name. */
    private function uuidv7(): string
    {
        $bytes = random_bytes(16);
        $millis = (int) (microtime(true) * 1000);

        for ($i = 5; $i >= 0; --$i) {
            $bytes[$i] = chr($millis & 0xff);
            $millis >>= 8;
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return BinaryUuid::toString($bytes);
    }
}
