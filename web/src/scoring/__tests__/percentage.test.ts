import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import { level, scaled, toNumber } from '../percentage'
import { fixtureRoot, read } from './fixtures'
import type { Band } from '../types'

/**
 * The percentage arithmetic against tests/fixtures/scoring/banding.json.
 *
 * The PHP suite reads the same file. If these two disagree, one of the two
 * engines is quietly certifying sites the other would not.
 */

interface BandingFile {
    bands: Band[]
    cases: {
        score: number
        possible: number
        round_dp: number
        expect: { percentage: number | null; level: number | null }
        why?: string
    }[]
}

const banding = read<BandingFile>(resolve(fixtureRoot, 'banding.json'))

describe('percentage', () => {
    it.each(banding.cases.map((c) => [`${c.score} of ${c.possible} at ${c.round_dp} dp`, c] as const))(
        '%s',
        (_label, testCase) => {
            const value = scaled(testCase.score, testCase.possible, testCase.round_dp)
            const percentage = value === null ? null : toNumber(value, testCase.round_dp)

            expect(percentage).toBe(testCase.expect.percentage)
            expect(level(value, banding.bands, testCase.round_dp)).toBe(testCase.expect.level)
        },
    )

    it('never produces more decimal places than it was asked for', () => {
        for (let score = 0; score <= 206; score += 1) {
            const value = scaled(score, 206, 2)

            expect(value).not.toBeNull()

            const percentage = toNumber(value as number, 2)

            expect(Math.round(percentage * 100) / 100).toBe(percentage)
        }
    })

    it('has nothing to divide by when the possible total is zero', () => {
        expect(scaled(0, 0, 2)).toBeNull()
        expect(level(null, banding.bands, 2)).toBeNull()
    })
})
