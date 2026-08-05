import type { Band } from './types'

/**
 * The percentage arithmetic. Everything here is integer arithmetic, and that
 * is the whole point of the module.
 *
 * The obvious implementation is `Math.round((score / possible) * 100 * 100) / 100`,
 * and it works — until it has to agree with the PHP engine. PHP's round()
 * applies a pre-rounding correction that Math.round does not, so the two
 * disagree on values sitting near a midpoint once binary floating point has
 * finished with them. Two implementations disagreeing at exactly the boundary,
 * on the number that decides whether a site is certified, is the defect this
 * system can least afford.
 *
 * So the percentage is never a float until it is displayed. It is carried as
 * an integer scaled by 10^dp, computed by exact division with an explicit
 * round-half-up. A score of 500 scales to 5,000,000 — six orders of magnitude
 * below Number.MAX_SAFE_INTEGER, so nothing here is near the edge.
 *
 * src/Scoring/Percentage.php is the same code. Change one, change both, and
 * the fixtures in tests/fixtures/scoring/ will tell you if you did not.
 */

/**
 * The percentage multiplied by 10^dp, rounded half up.
 *
 * Null when there is nothing to divide by. An assessment where every
 * applicable question came back Not applicable is a legitimate state, not an
 * error, and it must not produce a NaN that then bands as Level 0.
 */
export function scaled(score: number, possible: number, dp: number): number | null {
    if (possible <= 0) {
        return null
    }

    const factor = 10 ** dp

    // score/possible * 100, scaled by factor, as an exact rational. Both
    // operands are non-negative, so there is no sign handling to get wrong.
    const numerator = score * 100 * factor
    let quotient = Math.floor(numerator / possible)
    const remainder = numerator % possible

    // Round half UP: a value exactly on the midpoint goes to the higher band.
    // 89.995 becomes 90.00 and lands in Level 4. Comparing twice the remainder
    // against the divisor tests that without ever forming a fraction.
    if (remainder * 2 >= possible) {
        quotient += 1
    }

    return quotient
}

/** The scaled integer back to a number, for display. Call once banding is settled. */
export function toNumber(value: number, dp: number): number {
    return value / 10 ** dp
}

/**
 * The certification level for an already rounded, already scaled percentage.
 *
 * Bands carry a lower bound only, so the answer is the highest band the value
 * reaches. Thresholds are scaled by the same factor before comparing, so a
 * band at 89.9 does not fail to match a score of exactly 89.9 because
 * 89.9 * 100 is 8989.999999999999 in binary.
 */
export function level(value: number | null, bands: Band[], dp: number): number | null {
    if (value === null || bands.length === 0) {
        return null
    }

    const factor = 10 ** dp
    let best: number | null = null
    let found: number | null = null

    for (const band of bands) {
        const threshold = Math.round(band.min_percent * factor)

        if (value >= threshold && (best === null || threshold >= best)) {
            best = threshold
            found = band.level
        }
    }

    return found
}
