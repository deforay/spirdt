import type { Context, ContextField, Template } from '@/scoring/types'

/**
 * Checks Part A answers against the limits the template declares.
 *
 * Written twice, once here and once in PHP, for the same reason the scoring
 * engine is. Neither check can be the only one: the device is what the assessor
 * is standing in front of, and the server is the only thing that can be
 * trusted. Both read the same `constraints` block out of the template, and the
 * shared fixtures under tests/fixtures/context are what stop the two drifting.
 *
 * Nothing about the instrument is encoded here. Which field may not hold a
 * future date, and how many testing sites is too many, are facts about the form
 * — a country customising it changes them in the template and neither codebase
 * moves.
 */

/** A code, never a sentence — the wording is chosen in the reader's language. */
export type ProblemReason =
    | 'not_an_integer'
    | 'below_min'
    | 'above_max'
    | 'not_a_date'
    | 'in_the_future'

export interface Problem {
    /** The context field's code. */
    field: string
    reason: ProblemReason
    /** What the wording needs: the limit exceeded, not the value that exceeded it. */
    params: Record<string, number>
}

/** Part A fields, including the ones nested inside a repeat. */
function fieldsOf(template: Template): ContextField[] {
    const fields: ContextField[] = []

    for (const field of template.context_fields ?? []) {
        fields.push(field)

        for (const nested of field.fields ?? []) {
            fields.push(nested)
        }
    }

    return fields
}

/**
 * Today, where the device is.
 *
 * Built from the local parts rather than from toISOString, which converts to
 * UTC first — so east of Greenwich it returns tomorrow for part of every
 * evening, and a date the assessor can see is correct would be refused.
 *
 * The server allows a day of grace for the same disagreement seen from the
 * other side. The device needs none: it knows where it is.
 */
function today(): string {
    const now = new Date()
    const month = String(now.getMonth() + 1).padStart(2, '0')
    const day = String(now.getDate()).padStart(2, '0')

    return `${now.getFullYear()}-${month}-${day}`
}

function checkInteger(
    code: string,
    constraints: NonNullable<ContextField['constraints']>,
    value: unknown,
): Problem | null {
    const text = String(value).trim()

    // Strict, because the point of an integer field is that the answer can be
    // counted. "12 or so" is not a number of testing sites, and a form that
    // accepts it produces a column nobody can total.
    if (!/^-?\d+$/.test(text)) {
        return { field: code, reason: 'not_an_integer', params: {} }
    }

    const number = Number(text)

    if (typeof constraints.min === 'number' && number < constraints.min) {
        return { field: code, reason: 'below_min', params: { min: constraints.min } }
    }

    if (typeof constraints.max === 'number' && number > constraints.max) {
        return { field: code, reason: 'above_max', params: { max: constraints.max } }
    }

    return null
}

function checkDate(
    code: string,
    constraints: NonNullable<ContextField['constraints']>,
    value: unknown,
): Problem | null {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return { field: code, reason: 'not_a_date', params: {} }
    }

    const [year, month, day] = value.split('-').map(Number)
    const parsed = new Date(year!, month! - 1, day!)

    // Round-tripping is what catches the 31st of February: it passes the
    // pattern above and the calendar quietly moves it to the 3rd of March.
    if (
        parsed.getFullYear() !== year ||
        parsed.getMonth() !== month! - 1 ||
        parsed.getDate() !== day
    ) {
        return { field: code, reason: 'not_a_date', params: {} }
    }

    // Compared as strings, which is exact for ISO dates and needs no timezone.
    if (constraints.not_future === true && value > today()) {
        return { field: code, reason: 'in_the_future', params: {} }
    }

    return null
}

/**
 * Every problem, in template field order.
 *
 * All of them, not the first: two fields wrong means two things to correct, and
 * stopping early sends the assessor back twice.
 *
 * Blank is not a problem. A field left empty is either optional, in which case
 * there is nothing to check, or required, which is a different question
 * answered elsewhere. Reporting an empty optional field as out of range would
 * mean it could not be left empty, which is what optional means.
 */
export function validateContext(template: Template, context: Context): Problem[] {
    const problems: Problem[] = []

    for (const field of fieldsOf(template)) {
        const constraints = field.constraints

        if (!constraints || Object.keys(constraints).length === 0) {
            continue
        }

        const value = context[field.code]

        if (value === undefined || value === null || value === '') {
            continue
        }

        const problem =
            field.type === 'integer'
                ? checkInteger(field.code, constraints, value)
                : field.type === 'date'
                  ? checkDate(field.code, constraints, value)
                  : null

        if (problem !== null) {
            problems.push(problem)
        }
    }

    return problems
}
