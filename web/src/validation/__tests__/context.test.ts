import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import rawTemplate from '@resources/templates/spi-rdt-1.0.0.json'
import type { Context, Template } from '@/scoring/types'
import { type Problem, validateContext } from '../context'

/**
 * Part A validation, against the shared fixtures.
 *
 * The cases live in tests/fixtures/context because the PHP validator reads the
 * same files. A rule proved here and not there is a rule the assessor is told
 * about at the bench and the server disagrees with, or the reverse — and either
 * way somebody is arguing with a form.
 *
 * This file decides as little as possible. Anything it works out for itself is
 * a place the two harnesses can disagree while both validators are correct.
 */

interface Case {
    name: string
    why: string
    template: string
    context: Context
    expect: Problem[]
}

const root = join(__dirname, '../../../../tests/fixtures/context')

const cases: Case[] = readdirSync(root)
    .filter((file) => file.endsWith('.json'))
    .sort()
    .map((file) => JSON.parse(readFileSync(join(root, file), 'utf8')) as Case)

describe('Part A validation', () => {
    it('reads the shared fixtures', () => {
        expect(cases.length, 'the fixtures directory is empty').toBeGreaterThan(0)
    })

    it.each(cases.map((entry) => [entry.name, entry] as const))('%s', (_name, entry) => {
        // Every case names spi-rdt-1.0.0; asserted rather than assumed, so a
        // fixture written against another instrument fails loudly here rather
        // than being silently checked against the wrong one.
        expect(entry.template).toBe('spi-rdt-1.0.0')

        const template = rawTemplate as unknown as Template

        expect(validateContext(template, entry.context), entry.why).toEqual(entry.expect)
    })
})
