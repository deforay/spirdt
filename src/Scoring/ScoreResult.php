<?php

declare(strict_types=1);

namespace App\Scoring;

/**
 * What the engine produces. Maps onto assessment_scores: the top-level totals
 * are the columns dashboards aggregate on, and toBreakdown() is the JSON
 * column holding the per-section and per-pathogen detail the report needs.
 *
 * Three separate notions of "is this any good", because they fail differently:
 *
 *   isScorable()  — there was something to divide by. False for an assessment
 *                   where every applicable question came back Not Applicable.
 *   isComplete()  — every expected question has an answer. False mid-visit,
 *                   which is normal: the assessor watches a running score while
 *                   working. Required before anything is snapshotted.
 *   isValid()     — nothing was recorded that the template forbids, such as
 *                   Not Applicable on a question where it is not allowed.
 *
 * A running score on the device is legitimately incomplete. A submission is
 * not, and the submission endpoint rejects on isComplete() and isValid()
 * rather than the engine throwing — an exception mid-assessment would take
 * away the running total the assessor needs to debrief the site.
 */
final readonly class ScoreResult
{
    /**
     * @param list<array{number:int,code:string,scope:string,applicable:bool,score:int,possible:int,answered:int,excluded:int}> $sections
     * @param list<array{key:string,score:int,possible:int,answered:int,excluded:int}>                                          $pathogens
     * @param list<string>                                                                                                      $missing
     * @param list<string>                                                                                                      $missingNotes
     * @param list<string>                                                                                                      $unexpected
     * @param list<string>                                                                                                      $violations
     */
    public function __construct(
        public int $totalScore,
        public int $totalPossible,
        public ?int $percentageScaled,
        public ?float $percentage,
        public ?int $level,
        public int $roundDp,
        public int $pathogenCount,
        public array $sections,
        public array $pathogens,
        public array $missing,
        /**
         * Answered, but with a response the template obliges the assessor to
         * explain and no words against it. Reported rather than scored: the
         * points are earned, and what is absent is the thing the site is meant
         * to act on.
         */
        public array $missingNotes,
        public array $unexpected,
        public array $violations,
        public string $scoringVersion,
    ) {
    }

    public function isScorable(): bool
    {
        // Tied to the percentage rather than to the denominator. Since an
        // unanswered question counts against the visit, a form nobody has
        // opened has a denominator and no score, and those two must not
        // disagree about whether it is scorable.
        return $this->percentageScaled !== null;
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * The assessment_scores.breakdown payload.
     *
     * Carries the diagnostics as well as the tallies. An assessment that was
     * accepted despite unexpected answers — a stale Section 5 answer from
     * before the site was marked as referring nothing — should say so where
     * someone auditing the score a year later will find it.
     *
     * @return array<string,mixed>
     */
    public function toBreakdown(): array
    {
        return [
            'sections'   => $this->sections,
            'pathogens'  => $this->pathogens,
            'missing'      => $this->missing,
            'missing_notes' => $this->missingNotes,
            'unexpected' => $this->unexpected,
            'violations' => $this->violations,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'pathogen_count'  => $this->pathogenCount,
            'total_score'     => $this->totalScore,
            'total_possible'  => $this->totalPossible,
            'percentage'      => $this->percentage,
            'level'           => $this->level,
            'breakdown'       => $this->toBreakdown(),
            'scoring_version' => $this->scoringVersion,
        ];
    }
}
