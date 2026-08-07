import { level as bandLevel, scaled, toNumber } from './percentage'
import type {
    AnswerInput,
    Context,
    ExpectedQuestion,
    PathogenTally,
    ResponseCode,
    ScoreResult,
    Section,
    SectionTally,
    Template,
} from './types'

/**
 * Turns a template plus a set of answers into a certification level.
 *
 * This is the second implementation of the rules. src/Scoring/ScoringEngine.php
 * is the first and is authoritative; this one exists because the assessor has to
 * see a score on the device before leaving the site, and the User's Guide
 * requires debriefing the site team with the findings before the visit ends.
 *
 * Two implementations of the same rules drift. What stops it: the rules live in
 * the template rather than here, so what is duplicated is only summation with
 * exclusions; and both are tested against the same fixtures in
 * tests/fixtures/scoring/, so a disagreement fails the build.
 *
 * The rules, in full:
 *
 *   - Y, P and N score their template point value and add the maximum point
 *     value to the possible total.
 *   - NA is excluded from BOTH numerator and denominator. It is not a zero.
 *   - A pathogen-scoped section repeats per pathogen, so the possible total
 *     scales with how many pathogens were assessed.
 *   - An optional section whose applicability field is false contributes
 *     nothing at all.
 *   - The percentage is rounded before banding. See ./percentage.
 */

/** Bumped when a change here could change the numbers produced. */
export const SCORING_VERSION = '1.0.0'

const NO_PATHOGEN: string | null = null

/** The natural key of an answer, mirroring the unique constraint on answers. */
export function questionKey(code: string, pathogen: string | null | undefined): string {
    return `${code}|${pathogen ?? ''}`
}

/**
 * Whether an optional section applies to this assessment.
 *
 * The applicability field is a Part A answer, and Part A stores select_one
 * values by option key — refers_specimens arrives as the string 'yes' or 'no',
 * not a boolean. So this accepts the several shapes the same fact arrives in:
 * an option key from the form, a boolean from the API, a number from a column.
 */
function sectionApplies(section: Section, context: Context): boolean {
    if (section.optional !== true) {
        return true
    }

    const field = section.applicability_field

    // Optional with nothing naming what decides it: nothing can turn it on, so
    // treat it as applying rather than silently dropping its questions.
    if (typeof field !== 'string' || field === '') {
        return true
    }

    const value = context[field]

    if (typeof value === 'boolean') {
        return value
    }

    if (typeof value === 'number') {
        return value === 1
    }

    if (typeof value === 'string') {
        return ['yes', 'y', 'true', '1'].includes(value.toLowerCase())
    }

    return false
}

/**
 * The questions this assessment is expected to answer, in template order.
 *
 * Exported because the form renders from this same list. Deriving what to show
 * and what to score separately is what lets a question be asked but not
 * counted, or counted but never asked.
 */
export function expectedQuestions(
    template: Template,
    context: Context = {},
    pathogens: string[] = [],
): ExpectedQuestion[] {
    const expected: ExpectedQuestion[] = []

    for (const section of template.sections ?? []) {
        if (!sectionApplies(section, context)) {
            continue
        }

        // A pathogen-scoped section with no pathogens yields nothing. That is
        // the correct reading of "assessed no pathogens", not an error.
        const instances = section.scope === 'pathogen' ? pathogens : [NO_PATHOGEN]

        for (const pathogen of instances) {
            for (const question of section.questions ?? []) {
                expected.push({
                    sectionNumber: section.number,
                    sectionCode: section.code,
                    scope: section.scope,
                    questionCode: question.code,
                    pathogen,
                    naAllowed: question.na_allowed === true,
                    commentRequiredFor: question.comment_required_for ?? [],
                    key: questionKey(question.code, pathogen),
                })
            }
        }
    }

    return expected
}

/** Point value per response, excluding those removed from the denominator. */
function responsePoints(template: Template): Record<string, number> {
    const points: Record<string, number> = {}

    for (const [code, definition] of Object.entries(template.scoring?.responses ?? {})) {
        if (definition.excluded === true) {
            continue
        }

        points[code] = typeof definition.points === 'number' ? definition.points : 0
    }

    return points
}

function excludedResponses(template: Template): string[] {
    return Object.entries(template.scoring?.responses ?? {})
        .filter(([, definition]) => definition.excluded === true)
        .map(([code]) => code)
}

/**
 * Answers keyed by question and pathogen.
 *
 * A response the template does not define, and a second answer to a question
 * already answered, are both reported rather than resolved. Choosing between
 * two conflicting answers is not a decision this has any basis to make.
 */
function indexAnswers(
    answers: AnswerInput[],
    known: Set<string>,
): { byKey: Map<string, string>; comments: Map<string, string>; problems: string[] } {
    const byKey = new Map<string, string>()
    // Kept so the engine can tell an explained gap from an unexplained one.
    const comments = new Map<string, string>()
    const problems: string[] = []

    for (const answer of answers) {
        const key = questionKey(answer.question_code, answer.pathogen)

        if (!known.has(answer.response)) {
            problems.push(`${key}: unrecognised response ${answer.response}`)
            continue
        }

        if (byKey.has(key)) {
            problems.push(`${key}: answered more than once`)
            continue
        }

        byKey.set(key, answer.response)
        comments.set(key, (answer.comment ?? '').trim())
    }

    return { byKey, comments, problems }
}

export function score(
    template: Template,
    answers: AnswerInput[],
    context: Context = {},
    pathogens: string[] = [],
): ScoreResult {
    const points = responsePoints(template)
    const excluded = excludedResponses(template)
    const known = new Set<string>([...Object.keys(points), ...excluded])
    const values = Object.values(points)
    const maxPoints = values.length === 0 ? 0 : Math.max(...values)
    const roundDp = template.scoring?.round_dp ?? 2

    const { byKey, comments, problems } = indexAnswers(answers, known)
    const violations = [...problems]
    const consumed = new Set<string>()
    const missing: string[] = []
    const missingNotes: string[] = []

    // Every section gets a tally up front. A section absent from a report reads
    // as an oversight; a section present with zeroes reads as a finding.
    const sections = new Map<string, SectionTally>()
    for (const section of template.sections ?? []) {
        sections.set(section.code, {
            number: section.number,
            code: section.code,
            scope: section.scope,
            applicable: sectionApplies(section, context),
            score: 0,
            possible: 0,
            answered: 0,
            excluded: 0,
        })
    }

    const byPathogen = new Map<string, PathogenTally>()

    for (const question of expectedQuestions(template, context, pathogens)) {
        const response = byKey.get(question.key)

        const tally = sections.get(question.sectionCode)
        if (tally === undefined) {
            if (response === undefined) {
                missing.push(question.key)
            }

            continue
        }

        if (question.pathogen !== null && !byPathogen.has(question.pathogen)) {
            byPathogen.set(question.pathogen, {
                key: question.pathogen,
                score: 0,
                possible: 0,
                answered: 0,
                excluded: 0,
            })
        }

        const pathogenTally = question.pathogen === null ? undefined : byPathogen.get(question.pathogen)

        /**
         * An expected question with no answer scores nothing and still counts.
         *
         * The denominator is every question the visit is expected to answer,
         * so a half-finished assessment reads as half-finished rather than as
         * a high score over a small sample. It climbs from zero towards the
         * real figure as the visit proceeds, and the number an assessor
         * watches is one they can act on.
         *
         * The finished score is unaffected: once nothing is missing, the two
         * ways of counting agree exactly. This changes what an INCOMPLETE
         * assessment reads as, and nothing else.
         *
         * Not applicable is still excluded from both sides, because the
         * assessor has said the question does not apply here. Silence is not
         * that statement.
         */
        if (response === undefined) {
            missing.push(question.key)

            tally.possible += maxPoints
            if (pathogenTally) {
                pathogenTally.possible += maxPoints
            }

            continue
        }

        consumed.add(question.key)

        /**
         * A Partial, a No or a Not applicable is a claim about the site, and
         * the template says which of them have to be explained. An unexplained
         * one is not a smaller answer than the others — it is a finding nobody
         * can act on six months later, which is the whole reason the visit is
         * made.
         */
        if (
            question.commentRequiredFor.includes(response as ResponseCode) &&
            (comments.get(question.key) ?? '') === ''
        ) {
            missingNotes.push(question.key)
        }

        if (excluded.includes(response)) {
            if (!question.naAllowed) {
                violations.push(`${question.key}: response ${response} is not permitted on this question`)
            }

            tally.excluded += 1
            if (pathogenTally) {
                pathogenTally.excluded += 1
            }

            continue
        }

        const earned = points[response] ?? 0

        tally.score += earned
        tally.possible += maxPoints
        tally.answered += 1

        if (pathogenTally) {
            pathogenTally.score += earned
            pathogenTally.possible += maxPoints
            pathogenTally.answered += 1
        }
    }

    const unexpected = [...byKey.keys()].filter((key) => !consumed.has(key))

    let totalScore = 0
    let totalPossible = 0
    for (const tally of sections.values()) {
        totalScore += tally.score
        totalPossible += tally.possible
    }

    /**
     * Nothing answered is not the same as everything wrong.
     *
     * Unanswered questions are in the denominator now, so a visit nobody has
     * touched would otherwise divide zero by the whole instrument and report
     * 0% — and in a list of sites that reads as a catastrophic result rather
     * than a form somebody has not started. A score needs at least one answer
     * behind it before it means anything.
     */
    const responded = [...sections.values()].some(
        (tally) => tally.answered > 0 || tally.excluded > 0,
    )

    const percentageScaled = responded ? scaled(totalScore, totalPossible, roundDp) : null

    return {
        totalScore,
        totalPossible,
        percentageScaled,
        percentage: percentageScaled === null ? null : toNumber(percentageScaled, roundDp),
        level: bandLevel(percentageScaled, template.scoring?.bands ?? [], roundDp),
        roundDp,
        pathogenCount: pathogens.length,
        sections: [...sections.values()],
        pathogens: [...byPathogen.values()],
        missing,
        missingNotes,
        unexpected,
        violations,
        scoringVersion: SCORING_VERSION,
        // Tied to the percentage, not the denominator: an unanswered question
        // counts against the visit, so a form nobody has opened has a
        // denominator and no score.
        isScorable: percentageScaled !== null,
        isComplete: missing.length === 0,
        isValid: violations.length === 0,
    }
}

/** Whether a response obliges a comment. The checklist requires one for P, N and NA. */
export function commentRequired(
    response: ResponseCode | null,
    commentRequiredFor: ResponseCode[] = ['P', 'N', 'NA'],
): boolean {
    return response !== null && commentRequiredFor.includes(response)
}
