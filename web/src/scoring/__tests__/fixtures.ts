import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expectedQuestions } from '../engine'
import type { AnswerInput, Context, Template } from '../types'

/**
 * Reads the shared fixtures in tests/fixtures/scoring/ — the same files the
 * PHPUnit suite reads, not a copy.
 *
 * The expansion rules are specified in that directory's README because both
 * harnesses implement them. Anything this file decides for itself is a place
 * the two can disagree while both engines are correct, so it decides as little
 * as possible.
 */

const here = dirname(fileURLToPath(import.meta.url))

/** web/src/scoring/__tests__ → repo root. */
export const repoRoot = resolve(here, '../../../..')
export const fixtureRoot = resolve(repoRoot, 'tests/fixtures/scoring')

export interface Fixture {
    name?: string
    why?: string
    template: string
    context?: Context
    pathogens?: string[]
    default_response?: string
    answers?: Record<string, string>
    omit?: string[]
    extra_answers?: AnswerInput[]
    expect?: Record<string, unknown>
}

export function read<T = unknown>(path: string): T {
    return JSON.parse(readFileSync(path, 'utf8')) as T
}

export function cases(): Fixture[] {
    const dir = resolve(fixtureRoot, 'cases')
    const files = readdirSync(dir).filter((file) => file.endsWith('.json'))

    if (files.length === 0) {
        throw new Error('No scoring cases found. The fixtures are the contract; an empty suite is a broken one.')
    }

    return files.sort().map((file) => read<Fixture>(resolve(dir, file)))
}

/**
 * Resolve a template name. Shipped templates first, so a fixture cannot shadow
 * the canonical one with a convenient local copy that then stops tracking it.
 */
export function template(name: string): Template {
    const candidates = [
        resolve(repoRoot, 'resources/templates', `${name}.json`),
        resolve(fixtureRoot, 'templates', `${name}.json`),
    ]

    for (const path of candidates) {
        if (existsSync(path)) {
            return read<Template>(path)
        }
    }

    throw new Error(`Unknown template in fixture: ${name}`)
}

/** Expand a case into the answer rows the engine takes. */
export function answersFor(
    fixture: Fixture,
    tpl: Template,
    context: Context,
    pathogens: string[],
): AnswerInput[] {
    const overrides = fixture.answers ?? {}
    const omit = fixture.omit ?? []
    const fallback = fixture.default_response
    const answers: AnswerInput[] = []

    for (const question of expectedQuestions(tpl, context, pathogens)) {
        const instance =
            question.pathogen === null
                ? question.questionCode
                : `${question.questionCode}@${question.pathogen}`

        if (omit.includes(instance) || omit.includes(question.questionCode)) {
            continue
        }

        const response = overrides[instance] ?? overrides[question.questionCode] ?? fallback

        if (typeof response !== 'string') {
            continue
        }

        answers.push({
            question_code: question.questionCode,
            pathogen: question.pathogen,
            response,
        })
    }

    for (const row of fixture.extra_answers ?? []) {
        answers.push({
            question_code: String(row.question_code ?? ''),
            pathogen: typeof row.pathogen === 'string' ? row.pathogen : null,
            response: String(row.response ?? ''),
        })
    }

    return answers
}
