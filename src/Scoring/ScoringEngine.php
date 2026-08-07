<?php

declare(strict_types=1);

namespace App\Scoring;

/**
 * Turns a template plus a set of answers into a certification level.
 *
 * The engine holds no knowledge of the SPI-RDT instrument. Point values, which
 * questions may be marked Not Applicable, which section repeats per pathogen,
 * where the band boundaries fall — all of it is read from the template, because
 * the User's Guide states countries adjust these during customisation and a
 * customisation must not require a deploy. What is left in code is summation
 * with exclusions, and that is intentionally small: this class is written twice,
 * once here and once in TypeScript for the offline app, and the less there is
 * of it the less there is to drift.
 *
 * The rules, in full:
 *
 *   - Y, P and N score their template point value and add the maximum point
 *     value to the possible total.
 *   - NA is excluded from BOTH numerator and denominator. It is not a zero.
 *     A section of ten questions with one NA is scored out of 18, not 20.
 *   - An UNANSWERED question is the opposite of NA: it scores zero and stays
 *     in the denominator, so a half-finished visit reads as half-finished
 *     rather than as a high score over a small sample. A finished assessment
 *     is unaffected, because with nothing missing the two agree exactly.
 *     This is also what stops silence being the cheap way to certify.
 *   - Section 4 repeats per pathogen, so the possible total scales with how
 *     many pathogens were assessed.
 *   - An optional section whose applicability field is false contributes
 *     nothing at all — no questions expected, no points possible.
 *   - The percentage is rounded before banding. See Percentage.
 *
 * Three things are reported rather than scored, and each is a hazard that
 * silently inflates a percentage if handled any other way:
 *
 *   missing     Expected but unanswered. Scored as zero and counted in the
 *               denominator, so the percentage does not flatter an unfinished
 *               visit — but still reported, because a zero from silence and a
 *               zero from a recorded No are different facts and only one of
 *               them is an assessment. ScoreResult::isComplete() is what stops
 *               the former being submitted.
 *   unexpected  An answer to a question the template does not expect here — a
 *               retired question code, a pathogen that was removed, a Section 5
 *               answer left behind when the site was marked as referring
 *               nothing. Ignored, never scored, always reported.
 *   violations  Something the template forbids, chiefly NA on a question whose
 *               na_allowed is false. Honoured as recorded (so the number shown
 *               matches the data) but flagged, and the submission is refused.
 */
final class ScoringEngine
{
    /**
     * Bumped whenever a change here could change the numbers produced.
     * Snapshotted into assessment_scores.scoring_version so a later correction
     * to the engine can be identified rather than silently rewriting history.
     */
    public const VERSION = '1.0.0';

    /**
     * The questions this assessment is expected to answer, in template order.
     *
     * Public because the form renders from this same list. Deriving what to
     * show and what to score from one function is what keeps a question from
     * being asked but not counted, or counted but never asked.
     *
     * @param  array<string,mixed>       $template
     * @param  array<string,mixed>       $context   Part A answers, keyed by field code
     * @param  list<string>              $pathogens Pathogen keys, in sequence order
     * @return list<ExpectedQuestion>
     */
    public function expectedQuestions(array $template, array $context = [], array $pathogens = []): array
    {
        $expected = [];

        foreach (self::listOf($template['sections'] ?? null) as $section) {
            if (!$this->sectionApplies($section, $context)) {
                continue;
            }

            $number = self::intOf($section['number'] ?? 0);
            $code   = self::stringOf($section['code'] ?? '');
            $scope  = self::stringOf($section['scope'] ?? 'assessment');

            // A pathogen-scoped section with no pathogens yields nothing. That
            // is the correct reading of "assessed no pathogens", not an error:
            // Section 4 simply contributes zero to both sides of the fraction.
            $instances = $scope === 'pathogen' ? $pathogens : [null];

            foreach ($instances as $pathogen) {
                foreach (self::listOf($section['questions'] ?? null) as $question) {
                    $expected[] = new ExpectedQuestion(
                        sectionNumber: $number,
                        sectionCode: $code,
                        scope: $scope,
                        questionCode: self::stringOf($question['code'] ?? ''),
                        pathogen: $pathogen,
                        naAllowed: (bool) ($question['na_allowed'] ?? false),
                        commentRequiredFor: array_values(array_filter(
                            (array) ($question['comment_required_for'] ?? []),
                            'is_string',
                        )),
                    );
                }
            }
        }

        return $expected;
    }

    /**
     * @param array<string,mixed>                                                  $template
     * @param list<array{question_code:string,response:string,pathogen?:?string,comment?:?string}> $answers
     * @param array<string,mixed>                                                  $context
     * @param list<string>                                                         $pathogens
     */
    public function score(array $template, array $answers, array $context = [], array $pathogens = []): ScoreResult
    {
        $scoring   = self::mapOf($template['scoring'] ?? null);
        $points    = $this->responsePoints($scoring);
        $excluded  = $this->excludedResponses($scoring);
        $maxPoints = $points === [] ? 0 : max($points);
        $roundDp   = self::intOf($scoring['round_dp'] ?? 2);

        $index      = $this->indexAnswers($answers);
        $violations = $index['duplicates'];
        $byKey      = $index['answers'];
        $comments   = $index['comments'];
        $consumed   = [];

        $sections  = $this->emptySectionTallies($template, $context);
        $byPathogen  = [];
        $missing     = [];
        $missingNotes = [];

        foreach ($this->expectedQuestions($template, $context, $pathogens) as $question) {
            $key = $question->key();

            // Held as a local: the narrowing survives the early exits below,
            // where a property access is re-widened at each one.
            $pathogen = $question->pathogen;

            if (!isset($sections[$question->sectionCode])) {
                if (!isset($byKey[$key])) {
                    $missing[] = $key;
                }

                continue;
            }

            if ($pathogen !== null) {
                // ??= rather than a guarded assignment: it states in one place
                // that the tally exists from here on, which is what lets the
                // increments below be read without re-proving it at each early
                // exit.
                $byPathogen[$pathogen] ??= ['key' => $pathogen, 'score' => 0, 'possible' => 0, 'answered' => 0, 'excluded' => 0];
            }

            // An expected question with no answer scores nothing and still
            // counts. The denominator is every question the visit is expected
            // to answer, so a half-finished assessment reads as half-finished
            // rather than as a high score over a small sample.
            //
            // The finished score is unaffected: with nothing missing, the two
            // ways of counting agree exactly. This changes what an INCOMPLETE
            // assessment reads as, and nothing else.
            //
            // Not applicable is still excluded from both sides. That is the
            // assessor saying the question does not apply here, and silence is
            // not that statement.
            if (!isset($byKey[$key])) {
                $missing[] = $key;

                $sections[$question->sectionCode]['possible'] += $maxPoints;

                if ($pathogen !== null) {
                    $byPathogen[$pathogen]['possible'] += $maxPoints;
                }

                continue;
            }

            $consumed[$key] = true;
            $response       = $byKey[$key];

            // A Partial, a No or a Not applicable is a claim about the site,
            // and the template says which of them have to be explained. An
            // unexplained one is not a smaller answer than the others — it is
            // a finding nobody can act on six months later, which is the whole
            // reason the visit is made.
            if (
                in_array($response->value, $question->commentRequiredFor, true)
                && ($comments[$key] ?? '') === ''
            ) {
                $missingNotes[] = $key;
            }

            if (in_array($response->value, $excluded, true)) {
                if (!$question->naAllowed) {
                    $violations[] = sprintf(
                        '%s: response %s is not permitted on this question',
                        $key,
                        $response->value,
                    );
                }

                ++$sections[$question->sectionCode]['excluded'];

                if ($pathogen !== null) {
                    ++$byPathogen[$pathogen]['excluded'];
                }

                continue;
            }

            $earned = $points[$response->value] ?? 0;

            $sections[$question->sectionCode]['score']    += $earned;
            $sections[$question->sectionCode]['possible'] += $maxPoints;
            ++$sections[$question->sectionCode]['answered'];

            if ($pathogen !== null) {
                $byPathogen[$pathogen]['score']    += $earned;
                $byPathogen[$pathogen]['possible'] += $maxPoints;
                ++$byPathogen[$pathogen]['answered'];
            }
        }

        $unexpected = [];
        foreach (array_keys($byKey) as $key) {
            if (!isset($consumed[$key])) {
                $unexpected[] = $key;
            }
        }

        $totalScore    = 0;
        $totalPossible = 0;
        foreach ($sections as $tally) {
            $totalScore    += $tally['score'];
            $totalPossible += $tally['possible'];
        }

        // Nothing answered is not the same as everything wrong. Unanswered
        // questions are in the denominator now, so a visit nobody has touched
        // would otherwise divide zero by the whole instrument and report 0% —
        // which in a list of sites reads as a catastrophic result rather than
        // a form somebody has not started.
        $responded = false;
        foreach ($sections as $tally) {
            if ($tally['answered'] > 0 || $tally['excluded'] > 0) {
                $responded = true;
                break;
            }
        }

        $scaled = $responded ? Percentage::scaled($totalScore, $totalPossible, $roundDp) : null;

        return new ScoreResult(
            totalScore: $totalScore,
            totalPossible: $totalPossible,
            percentageScaled: $scaled,
            percentage: $scaled === null ? null : Percentage::toFloat($scaled, $roundDp),
            level: Percentage::level($scaled, $this->bands($scoring), $roundDp),
            roundDp: $roundDp,
            pathogenCount: count($pathogens),
            sections: array_values($sections),
            pathogens: array_values($byPathogen),
            missing: $missing,
            missingNotes: $missingNotes,
            unexpected: $unexpected,
            violations: $violations,
            scoringVersion: self::VERSION,
        );
    }

    /**
     * Whether an optional section applies to this assessment.
     *
     * The applicability field is a Part A answer, and Part A stores select_one
     * values by option key — refers_specimens comes through as the string
     * 'yes' or 'no', not a boolean. So the check accepts the several shapes the
     * same fact arrives in: a boolean from the API, an option key from the
     * form, an integer from a column.
     *
     * Absent or unrecognised means the section does NOT apply. That is the
     * conservative direction only for the score's integrity — it also removes
     * the section's questions from the expected list, so an assessment that
     * should have answered them is reported as having unexpected answers
     * rather than quietly scoring them.
     *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $context
     */
    private function sectionApplies(array $section, array $context): bool
    {
        if (($section['optional'] ?? false) !== true) {
            return true;
        }

        $field = $section['applicability_field'] ?? null;

        // Optional with no field naming what decides it: nothing can turn it
        // on, so treat it as applying rather than silently dropping questions.
        if (!is_string($field) || $field === '') {
            return true;
        }

        $value = $context[$field] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['yes', 'y', 'true', '1'], true);
        }

        return false;
    }

    /**
     * One tally per section, created up front so a section that scored nothing
     * still appears in the breakdown. A section missing from the report reads
     * as an oversight; a section present with zeroes reads as a finding.
     *
     * @param  array<string,mixed> $template
     * @param  array<string,mixed> $context
     * @return array<string,array{number:int,code:string,scope:string,applicable:bool,score:int,possible:int,answered:int,excluded:int}>
     */
    private function emptySectionTallies(array $template, array $context): array
    {
        $tallies = [];

        foreach (self::listOf($template['sections'] ?? null) as $section) {
            $code = self::stringOf($section['code'] ?? '');

            $tallies[$code] = [
                'number'     => self::intOf($section['number'] ?? 0),
                'code'       => $code,
                'scope'      => self::stringOf($section['scope'] ?? 'assessment'),
                'applicable' => $this->sectionApplies($section, $context),
                'score'      => 0,
                'possible'   => 0,
                'answered'   => 0,
                'excluded'   => 0,
            ];
        }

        return $tallies;
    }

    /**
     * Answers keyed by (question code, pathogen), matching the natural key on
     * the answers table.
     *
     * A response the enum does not recognise, and a second answer to a question
     * already answered, are both reported rather than resolved. Picking one of
     * two conflicting answers is a decision the engine has no basis to make,
     * and the database's unique constraint means it should not be reachable
     * from stored data in the first place — only from a malformed sync payload.
     *
     * @param  list<array{question_code:string,response:string,pathogen?:?string,comment?:?string}> $answers
     * @return array{answers:array<string,Response>,duplicates:list<string>,comments:array<string,string>}
     */
    private function indexAnswers(array $answers): array
    {
        $byKey      = [];
        $duplicates = [];
        $comments   = [];

        foreach ($answers as $answer) {
            $pathogen = $answer['pathogen'] ?? null;
            $key      = $answer['question_code'] . '|' . ($pathogen ?? '');
            $response = Response::tryFrom($answer['response']);

            if ($response === null) {
                $duplicates[] = sprintf('%s: unrecognised response %s', $key, $answer['response']);
                continue;
            }

            if (isset($byKey[$key])) {
                $duplicates[] = sprintf('%s: answered more than once', $key);
                continue;
            }

            $byKey[$key] = $response;

            // Kept so the engine can tell an explained gap from an unexplained
            // one. Absent for a caller that has no comments to give — a score
            // recomputed from responses alone still works, and simply reports
            // no missing notes.
            $comments[$key] = trim((string) ($answer['comment'] ?? ''));
        }

        return ['answers' => $byKey, 'duplicates' => $duplicates, 'comments' => $comments];
    }

    /**
     * Point value per scoring response, excluding those removed from the
     * denominator — NA has no point value and must never be read as zero.
     *
     * @param  array<string,mixed>  $scoring
     * @return array<string,int>
     */
    private function responsePoints(array $scoring): array
    {
        $points = [];

        foreach (self::mapOf($scoring['responses'] ?? null) as $code => $definition) {
            $definition = self::mapOf($definition);

            if (($definition['excluded'] ?? false) === true) {
                continue;
            }

            $points[(string) $code] = self::intOf($definition['points'] ?? 0);
        }

        return $points;
    }

    /**
     * @param  array<string,mixed> $scoring
     * @return list<string>
     */
    private function excludedResponses(array $scoring): array
    {
        $excluded = [];

        foreach (self::mapOf($scoring['responses'] ?? null) as $code => $definition) {
            if ((self::mapOf($definition)['excluded'] ?? false) === true) {
                $excluded[] = (string) $code;
            }
        }

        return $excluded;
    }

    /**
     * @param  array<string,mixed>                          $scoring
     * @return list<array{level:int,min_percent:int|float}>
     */
    private function bands(array $scoring): array
    {
        $bands = [];

        foreach (self::listOf($scoring['bands'] ?? null) as $band) {
            $minimum = $band['min_percent'] ?? 0;

            $bands[] = [
                'level'       => self::intOf($band['level'] ?? 0),
                'min_percent' => is_int($minimum) || is_float($minimum) ? $minimum : 0,
            ];
        }

        return $bands;
    }

    // Templates are operator-editable data reaching this class as a decoded
    // JSON document, so every read below is a read of something that may not
    // be the shape it should be. These four narrow it once, at the point of
    // use, instead of scattering is_array() through the arithmetic.

    /**
     * @return list<array<string,mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private static function mapOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
