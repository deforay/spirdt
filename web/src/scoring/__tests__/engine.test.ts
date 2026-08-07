import { describe, expect, it } from 'vitest'

import { SCORING_VERSION, score } from '../engine'
import { answersFor, cases, template } from './fixtures'
import type { Context } from '../types'

/**
 * Whole assessments against tests/fixtures/scoring/cases/.
 *
 * The PHP suite reads the same files. Adding a case is adding a file, which is
 * what stops the two suites from covering different sets.
 */

const EXPECT_KEYS = [
    'total_score', 'total_possible', 'percentage', 'level', 'pathogen_count',
    'is_scorable', 'is_complete', 'is_valid',
    'missing_count', 'missing_notes_count', 'unexpected_count', 'violation_count',
    'sections', 'pathogens',
]

const SECTION_KEYS = ['score', 'possible', 'answered', 'excluded', 'applicable']
const PATHOGEN_KEYS = ['score', 'possible', 'answered', 'excluded']

describe('scoring engine', () => {
    it.each(cases().map((fixture) => [fixture.name ?? fixture.template, fixture] as const))(
        '%s',
        (_name, fixture) => {
            expect(typeof fixture.why, 'every case states what it exists to prove').toBe('string')

            const context: Context = fixture.context ?? {}
            const pathogens = fixture.pathogens ?? []
            const tpl = template(fixture.template)
            const result = score(tpl, answersFor(fixture, tpl, context, pathogens), context, pathogens)

            const expected = fixture.expect ?? {}

            expect(
                Object.keys(expected).filter((key) => !EXPECT_KEYS.includes(key)),
                'unrecognised expect keys',
            ).toEqual([])

            const actual: Record<string, unknown> = {
                total_score: result.totalScore,
                total_possible: result.totalPossible,
                percentage: result.percentage,
                level: result.level,
                pathogen_count: result.pathogenCount,
                is_scorable: result.isScorable,
                is_complete: result.isComplete,
                is_valid: result.isValid,
                missing_count: result.missing.length,
                missing_notes_count: result.missingNotes.length,
                unexpected_count: result.unexpected.length,
                violation_count: result.violations.length,
            }

            for (const [key, value] of Object.entries(actual)) {
                if (key in expected) {
                    expect(value, key).toBe(expected[key])
                }
            }

            if (expected.sections) {
                const bySection = new Map(result.sections.map((s) => [s.code, s]))

                for (const [code, wanted] of Object.entries(expected.sections as Record<string, Record<string, unknown>>)) {
                    const tally = bySection.get(code)
                    expect(tally, `section ${code} missing from the breakdown`).toBeDefined()
                    expect(
                        Object.keys(wanted).filter((k) => !SECTION_KEYS.includes(k)),
                        `section ${code}: unrecognised keys`,
                    ).toEqual([])

                    for (const [field, value] of Object.entries(wanted)) {
                        expect(tally![field as keyof typeof tally], `section ${code}.${field}`).toBe(value)
                    }
                }
            }

            if (expected.pathogens) {
                const byPathogen = new Map(result.pathogens.map((p) => [p.key, p]))
                const wantedAll = expected.pathogens as Record<string, Record<string, unknown>>

                expect(
                    byPathogen.size,
                    'pathogen breakdown has entries the case does not name',
                ).toBe(Object.keys(wantedAll).length)

                for (const [key, wanted] of Object.entries(wantedAll)) {
                    const tally = byPathogen.get(key)
                    expect(tally, `pathogen ${key} missing from the breakdown`).toBeDefined()
                    expect(
                        Object.keys(wanted).filter((k) => !PATHOGEN_KEYS.includes(k)),
                        `pathogen ${key}: unrecognised keys`,
                    ).toEqual([])

                    for (const [field, value] of Object.entries(wanted)) {
                        expect(tally![field as keyof typeof tally], `pathogen ${key}.${field}`).toBe(value)
                    }
                }
            }
        },
    )

    it('reports conflicting answers rather than picking one', () => {
        const tpl = template('fixture-custom-bands')

        const result = score(tpl, [
            { question_code: '1.1', pathogen: null, response: 'Y' },
            { question_code: '1.1', pathogen: null, response: 'N' },
            { question_code: '1.2', pathogen: null, response: 'Y' },
            { question_code: '1.3', pathogen: null, response: 'Y' },
            { question_code: '1.4', pathogen: null, response: 'Y' },
        ])

        expect(result.isValid).toBe(false)
        expect(result.violations).toHaveLength(1)
        expect(result.violations[0]).toContain('more than once')
        expect(result.totalScore, 'the first answer stands; the duplicate is dropped').toBe(8)
    })

    /**
     * The question stays in the denominator: an unrecognised response leaves
     * it unanswered, and an unanswered question counts against the visit. That
     * is the safe direction — the alternative is a payload that scores well by
     * sending nonsense for everything it would rather not answer.
     */
    it('refuses a response the template does not define', () => {
        const tpl = template('fixture-custom-bands')
        const result = score(tpl, [{ question_code: '1.1', pathogen: null, response: 'MAYBE' }])

        expect(result.isValid).toBe(false)
        expect(result.isComplete).toBe(false)
        expect(result.totalScore, 'nonsense earns no points').toBe(0)
        expect(result.totalPossible, 'and does not shrink what it is measured against').toBe(8)
    })

    it.each([
        ['option key yes', 'yes', 9],
        ['option key no', 'no', 0],
        ['boolean true', true, 9],
        ['boolean false', false, 0],
        ['number one', 1, 9],
        ['number zero', 0, 0],
        ['absent', undefined, 0],
    ])('resolves an applicability field given as %s', (_label, value, expectedCount) => {
        const tpl = template('spi-rdt-1.0.0')
        const expectedQuestions = score(tpl, [], { refers_specimens: value }, []).sections

        const sectionFive = expectedQuestions.find((s) => s.code === '5')
        expect(sectionFive?.applicable).toBe(expectedCount > 0)
    })

    it('reports the version that produced the numbers', () => {
        const tpl = template('fixture-custom-bands')
        expect(score(tpl, []).scoringVersion).toBe(SCORING_VERSION)
    })
})
