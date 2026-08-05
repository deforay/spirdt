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

/**
 * One pathogen assessed during a visit. The key is what answers reference; the
 * name is what the server stores and what the score is reported against.
 */
export interface StoredPathogen {
    key: string
    name: string
}

/**
 * Where an assessment stands with the server.
 *
 * `blocked` is the one that matters. It means the server refused the payload
 * for a reason that retrying cannot fix — the wrong organisation, a template it
 * does not have — and something has to be shown to a person rather than
 * retried quietly until the tablet is wiped.
 */
export type SyncState = 'pending' | 'synced' | 'blocked'

export interface StoredAssessment {
    /** UUIDv7, generated on the device. The server accepts this id as given. */
    id: string
    organizationId: number
    siteId: string | null
    siteName: string
    /** The facility the site belongs to. The server requires both. */
    facilityId: string | null
    templateCode: string
    templateVersion: string
    /** The date of the visit, YYYY-MM-DD. Not the date it was synced. */
    assessedOn: string
    /** Part A answers, keyed by field code. */
    context: Record<string, unknown>
    /** In sequence order. Section 4 repeats once per entry. */
    pathogens: StoredPathogen[]
    status: 'draft' | 'complete' | 'submitted'
    startedAt: string
    updatedAt: string
    /** Set when the server has acknowledged this assessment. */
    syncedAt: string | null
    syncState: SyncState
    /** Why the server refused, in words for the assessor. */
    syncError: string | null
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
    /**
     * Incremented on every write to this answer.
     *
     * A counter rather than updatedAt, because two writes can land in the same
     * millisecond and an ISO timestamp cannot tell them apart. The sync uses
     * this to check that the answer it is about to mark clean is still the one
     * it sent, so a comparison that silently reports "unchanged" for a real
     * change would discard the change.
     */
    revision: number
    /** False once the server has acknowledged this exact revision. */
    dirty: boolean
}

/**
 * A gap, and what is to be done about it.
 *
 * The User's Guide has the assessor debrief the site on gaps and actions before
 * leaving, so these are recorded during the visit rather than written up later
 * from the scores. Only a Partial or No can carry one — a finding against a Yes
 * would have nothing to describe.
 *
 * Responsibility matters more than it looks: many gaps are not the site's to
 * fix, and one recorded against a site that cannot act on it is a gap that
 * stays open forever.
 */
export interface StoredFinding {
    /** `${assessmentId}|${questionCode}|${pathogen ?? ''}` — one per answer. */
    key: string
    assessmentId: string
    questionCode: string
    pathogen: string | null
    response: 'P' | 'N'
    gap: string
    recommendation: string
    responsibilityLevel: 'site' | 'facility' | 'district' | 'regional' | 'national'
    responsiblePerson: string
    dueDate: string | null
    updatedAt: string
    revision: number
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
    kind: 'answer' | 'context' | 'assessment' | 'finding' | 'pathogens'
    /** The natural key this entry concerns, where it has one. */
    subject: string
    payload: unknown
}

export class SpirdtDatabase extends Dexie {
    assessments!: Table<StoredAssessment, string>
    answers!: Table<StoredAnswer, string>
    findings!: Table<StoredFinding, string>
    journal!: Table<JournalEntry, number>

    constructor(name = 'spirdt') {
        super(name)

        this.version(1).stores({
            assessments: 'id, status, syncedAt, updatedAt',
            answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
            journal: '++id, assessmentId, at',
        })

        // Version 2 adds what the server requires to accept a visit — the
        // facility, the date it happened — and the sync bookkeeping.
        //
        // The upgrade fills those in rather than leaving them undefined,
        // because a row half-described here is a row that fails to sync later
        // with nothing on screen to explain why. A device carrying an
        // unfinished visit through this upgrade keeps it.
        this.version(2)
            .stores({
                assessments: 'id, status, syncState, syncedAt, updatedAt',
                answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
                journal: '++id, assessmentId, at',
            })
            .upgrade(async (transaction) => {
                await transaction
                    .table('assessments')
                    .toCollection()
                    .modify((row: Record<string, unknown>) => {
                        row.facilityId ??= null
                        row.assessedOn ??= String(row.startedAt ?? '').slice(0, 10)
                        row.syncError ??= null
                        row.syncState ??= row.syncedAt == null ? 'pending' : 'synced'

                        // Pathogens were bare keys before the server needed a
                        // name to score against. The key is the best name
                        // available, and the review screen can correct it.
                        if (Array.isArray(row.pathogens)) {
                            row.pathogens = row.pathogens.map((entry: unknown) =>
                                typeof entry === 'string' ? { key: entry, name: entry } : entry,
                            )
                        }
                    })

                await transaction
                    .table('answers')
                    .toCollection()
                    .modify((row: Record<string, unknown>) => {
                        row.revision ??= 1
                    })
            })

        // Version 3 adds findings. Nothing to migrate — there were none — but
        // the store has to be declared before anything can write to it.
        this.version(3).stores({
            assessments: 'id, status, syncState, syncedAt, updatedAt',
            answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
            findings: 'key, assessmentId, dirty',
            journal: '++id, assessmentId, at',
        })
    }
}

export const db = new SpirdtDatabase()

export function answerKey(assessmentId: string, questionCode: string, pathogen: string | null): string {
    return `${assessmentId}|${questionCode}|${pathogen ?? ''}`
}
