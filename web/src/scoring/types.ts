/**
 * The template shapes the scoring engine reads.
 *
 * These mirror resources/templates/template.schema.json. Only the parts
 * scoring touches are typed strictly; the rest of a template is carried
 * through untouched, because this module has no business knowing about
 * guidance text or criteria.
 */

export type ResponseCode = 'Y' | 'P' | 'N' | 'NA'

/** Text keyed by locale. Never a bare string — translations are a launch requirement. */
export type Localised = Record<string, string>

export interface ResponseOption {
    /** Null only for an excluded response. */
    points?: number | null
    /** When true the response is removed from the possible total, not scored zero. */
    excluded?: boolean
    label: Localised
}

export interface Band {
    level: number
    /** Lower bound only, so bands cannot overlap or leave a gap. */
    min_percent: number
    label?: Localised
    description?: Localised
}

export interface Scoring {
    responses: Record<string, ResponseOption>
    /** Decimal places the percentage is rounded to BEFORE banding. */
    round_dp: number
    bands: Band[]
}

export interface Question {
    code: string
    text: Localised
    guidance?: Localised
    criteria?: Partial<Record<ResponseCode, Localised>>
    na_allowed: boolean
    comment_required_for?: ResponseCode[]
}

export interface Section {
    number: number
    code: string
    title: Localised
    intro?: Localised
    /** 'pathogen' repeats the section once per pathogen assessed. */
    scope: 'assessment' | 'pathogen'
    optional?: boolean
    /** Assessment field deciding whether an optional section applies. */
    applicability_field?: string
    questions: Question[]
}

/**
 * One choice in a select_one field.
 *
 * `specify` marks an option that needs a free-text companion — "Other", and
 * also "Laboratory", which the source form asks to be qualified. The value is
 * stored under `<code>_other` so the chosen key stays a stable key.
 */
export interface ContextOption {
    key: string
    label: Localised
    specify?: boolean
}

export interface ContextField {
    code: string
    type: 'date' | 'time' | 'text' | 'textarea' | 'integer' | 'select_one' | 'repeat'
    label: Localised
    hint?: Localised
    required?: boolean
    options?: ContextOption[]
    /** For `repeat`: the fields making up one row. */
    fields?: ContextField[]
}

export interface Template {
    schema_version: number
    code: string
    version: string
    title: Localised
    locales: string[]
    default_locale: string
    scoring: Scoring
    /** Part A — everything asked before the checklist starts. */
    context_fields: ContextField[]
    sections: Section[]
    [key: string]: unknown
}

/** One answer, in the shape the local store and the sync payload both use. */
export interface AnswerInput {
    question_code: string
    pathogen?: string | null
    response: string
}

/** Part A answers, keyed by field code. Only applicability fields affect scoring. */
export type Context = Record<string, unknown>

export interface ExpectedQuestion {
    sectionNumber: number
    sectionCode: string
    scope: 'assessment' | 'pathogen'
    questionCode: string
    pathogen: string | null
    naAllowed: boolean
    /** Natural key, mirroring the (question_code, pathogen_key) unique constraint. */
    key: string
}

export interface SectionTally {
    number: number
    code: string
    scope: 'assessment' | 'pathogen'
    applicable: boolean
    score: number
    possible: number
    answered: number
    excluded: number
}

export interface PathogenTally {
    key: string
    score: number
    possible: number
    answered: number
    excluded: number
}

export interface ScoreResult {
    totalScore: number
    totalPossible: number
    percentageScaled: number | null
    percentage: number | null
    level: number | null
    roundDp: number
    pathogenCount: number
    sections: SectionTally[]
    pathogens: PathogenTally[]
    /** Expected but unanswered. Not in the denominator, so an unfinished assessment reads high. */
    missing: string[]
    /** Answered but not expected here. Ignored, never scored. */
    unexpected: string[]
    /** Recorded but forbidden by the template. Honoured as recorded, and reported. */
    violations: string[]
    scoringVersion: string
    /** There was something to divide by. */
    isScorable: boolean
    /** Every expected question has an answer. False mid-visit, which is normal. */
    isComplete: boolean
    /** Nothing was recorded that the template forbids. */
    isValid: boolean
}
