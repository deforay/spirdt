<?php

declare(strict_types=1);

namespace App\Scoring;

/**
 * The percentage arithmetic, kept apart from the engine because it is the part
 * that decides a certification level and the part most easily got wrong.
 *
 * Everything here is INTEGER arithmetic. That is the whole point of the class.
 *
 * The obvious implementation is `round($score / $possible * 100, 2)`, and it
 * works — until you need the TypeScript build to agree with it. PHP's round()
 * applies a pre-rounding correction that JavaScript's Math.round does not, so
 * the two disagree on values that sit near a midpoint once binary floating
 * point has finished with them. Two implementations that disagree at exactly
 * the boundary, on a number that decides whether a site is certified, is the
 * defect this codebase can least afford.
 *
 * So the percentage is never a float until the moment it is presented. It is
 * carried as an integer scaled by 10^dp, computed by exact division with an
 * explicit round-half-up, and banded by comparing scaled integers. Every step
 * is reproducible in any language with 64-bit integers, which includes
 * JavaScript at these magnitudes (a score of 500 scales to 5,000,000 — six
 * orders of magnitude below Number.MAX_SAFE_INTEGER).
 */
final class Percentage
{
    /**
     * The percentage multiplied by 10^dp, rounded half-up.
     *
     * Null when there is nothing to divide by. A fully Not Applicable
     * assessment is a legitimate state, not an error, and it must not produce
     * a division by zero or a NaN that silently bands as Level 0.
     */
    public static function scaled(int $score, int $possible, int $dp): ?int
    {
        if ($possible <= 0) {
            return null;
        }

        $factor = 10 ** $dp;

        // score/possible * 100, scaled up by $factor, as an exact rational.
        // Both operands are non-negative, so intdiv truncates towards zero and
        // the remainder is non-negative — no sign handling needed.
        $numerator = $score * 100 * $factor;
        $quotient  = intdiv($numerator, $possible);
        $remainder = $numerator % $possible;

        // Round half UP: a value exactly on the midpoint goes to the higher
        // band. 89.995 becomes 90.00 and lands in Level 4. This is the rule
        // docs/scoring.md specifies, and comparing 2*remainder against the
        // divisor tests it without ever forming a fraction.
        if ($remainder * 2 >= $possible) {
            ++$quotient;
        }

        return $quotient;
    }

    /**
     * The scaled integer back to a float, for display and for the
     * DECIMAL(5,2) column. Only call this once the banding is settled.
     */
    public static function toFloat(int $scaled, int $dp): float
    {
        return $scaled / (10 ** $dp);
    }

    /**
     * The certification level for an already-rounded, already-scaled
     * percentage.
     *
     * Bands carry a lower bound only, so they cannot overlap or leave a gap —
     * the answer is simply the highest band the value reaches. Thresholds are
     * scaled to integers by the same factor before comparing, so a band at
     * 89.9 does not fail to match a score of exactly 89.9 because
     * 89.9 * 100 is 8989.999999999999 in binary.
     *
     * @param list<array{level:int,min_percent:int|float}> $bands
     */
    public static function level(?int $scaled, array $bands, int $dp): ?int
    {
        if ($scaled === null || $bands === []) {
            return null;
        }

        $factor = 10 ** $dp;
        $level  = null;
        $best   = null;

        foreach ($bands as $band) {
            $threshold = (int) round($band['min_percent'] * $factor);

            if ($scaled >= $threshold && ($best === null || $threshold >= $best)) {
                $best  = $threshold;
                $level = $band['level'];
            }
        }

        return $level;
    }
}
