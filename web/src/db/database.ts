import Dexie, { type Table } from 'dexie'

/**
 * The local database. This is where an assessment lives until it reaches the
 * server, which on a site visit is hours, and over a weekend can be days.
 *
 * Everything here is built on one rule: an answer is written the moment it is
 * given. Not on navigation, not on a timer, not on unload. `beforeunload` does
 * not fire when a tab is killed, when the browser is force-quit, or when the
 * battery dies, and all three happen in the field. There is no save button and
 * nothing to forget to press.
 *
 * Rows carry their own sync state rather than being moved to an outbox. A row
 * that has reached the server is marked, never deleted — the device keeps the
 * assessment so the assessor can still read what they filed.
 */

/** Mirrors answers.response in the database. */
export type StoredResponse = 'Y' | 'P' | 'N' | 'NA'

export interface StoredAssessment {
    /** UUIDv7, generated on the device. The server accepts this id as given. */
    id: string
    organizationId: number
    siteId: string | null
    siteName: string
    templateCode: string
    templateVersion: string
    /** Part A answers, keyed by field code. */
    context: Record<string, unknown>
    /** Pathogen keys in sequence order. Section 4 repeats once per entry. */
    pathogens: string[]
    status: 'draft' | 'complete' | 'submitted'
    startedAt: string
    updatedAt: string
    /** Set when the server has acknowledged this assessment. */
    syncedAt: string | null
}

export interface StoredAnswer {
    /** `${assessmentId}|${questionCode}|${pathogen ?? ''}` — the natural key. */
    key: string
    assessmentId: string
    questionCode: string
    pathogen: string | null
    response: StoredResponse | null
    comment: string
    answeredAt: string | null
    updatedAt: string
    /** False once the server has acknowledged this exact revision. */
    dirty: boolean
}

/**
 * Every write, in order, kept after the row it wrote.
 *
 * Deliberately redundant. If the answers table is ever lost or partially
 * written, this replays the visit. It costs a few hundred kilobytes across a
 * whole assessment, which is nothing next to what it protects.
 */
export interface JournalEntry {
    id?: number
    assessmentId: string
    at: string
    kind: 'answer' | 'context' | 'assessment'
    /** The natural key this entry concerns, where it has one. */
    subject: string
    payload: unknown
}

export class SpirdtDatabase extends Dexie {
    assessments!: Table<StoredAssessment, string>
    answers!: Table<StoredAnswer, string>
    journal!: Table<JournalEntry, number>

    constructor(name = 'spirdt') {
        super(name)

        this.version(1).stores({
            assessments: 'id, status, syncedAt, updatedAt',
            answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
            journal: '++id, assessmentId, at',
        })
    }
}

export const db = new SpirdtDatabase()

export function answerKey(assessmentId: string, questionCode: string, pathogen: string | null): string {
    return `${assessmentId}|${questionCode}|${pathogen ?? ''}`
}
