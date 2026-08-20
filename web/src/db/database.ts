import Dexie, { type Table } from 'dexie'

import { uuidv7 } from './uuid'

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
    /**
     * Which round of auditing this belongs to — "1", "Baseline", "Phase II".
     *
     * Text, because the first round of a programme is usually not called 1.
     * Optional, and no Dexie version was added for it: IndexedDB stores whole
     * objects, so a device carrying audits recorded before this shipped keeps
     * them and simply has no round on those rows, which is the truth about
     * them.
     */
    auditRound?: string
    /** Part A answers, keyed by field code. */
    context: Record<string, unknown>
    /** In sequence order. Section 4 repeats once per entry. */
    pathogens: StoredPathogen[]
    status: 'draft' | 'complete' | 'submitted'
    startedAt: string
    updatedAt: string
    /**
     * Where the assessor was when the visit started, if the device would say.
     *
     * Always optional and never waited on. A visit refused for want of a
     * satellite fix is a visit that does not happen, and the assessor is
     * standing in the laboratory either way. The server falls back to the
     * facility's own coordinates when this is absent, and records which of the
     * two a row came from.
     */
    latitude?: number | null
    longitude?: number | null
    accuracyM?: number | null
    locatedAt?: string | null
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
    /**
     * UUIDv7, minted here. The identity the server upserts on.
     *
     * It used to be the answer's natural key, which made one finding per
     * answer a structural fact. It is not one: a single No can hide the SOP
     * being missing AND the staff being untrained on it, and each needs its
     * own recommendation, owner and date.
     */
    key: string
    /**
     * The key this finding had before version 5 re-keyed it, or null.
     *
     * Set only by that upgrade, and only on rows that already existed. It is
     * how the server recognises a finding it has already stored under an id
     * the device can no longer reproduce — see the version 5 comment below.
     * Anything raised since is a finding the server has never seen, which is
     * exactly what null says.
     */
    legacyKey: string | null
    assessmentId: string
    /** `${questionCode}|${pathogen ?? ''}` — what groups several findings under one answer. */
    questionKey: string
    questionCode: string
    pathogen: string | null
    response: 'P' | 'N'
    gap: string
    recommendation: string
    /** Who acts. */
    responsibilityLevel: 'site' | 'facility' | 'district' | 'regional' | 'national'
    /**
     * When — a separate axis from who, and from the due date.
     *
     * Null means nobody said, which is not the same as follow-up. In a
     * quality-audit SOP "immediate" means correct before the assessor leaves,
     * or stop testing until it is fixed; a date cannot express that.
     */
    urgency: 'immediate' | 'follow_up' | null
    responsiblePerson: string
    dueDate: string | null
    updatedAt: string
    revision: number
    dirty: boolean
}

/**
 * Who signed off on the visit.
 *
 * `assessor_1` is whoever is signed in. `site_representative` is the person
 * debriefed at the site, whose name Part A already collects as
 * `interviewee_name` — so both roles can print a name beside the mark, which
 * is most of the difference between evidence and a squiggle. `assessor_2`
 * exists in the server's vocabulary for a two-person team; nothing offers it
 * yet.
 */
export type SignatureRole = 'assessor_1' | 'assessor_2' | 'site_representative'

/**
 * A signature, drawn on the device.
 *
 * Held as a Blob rather than a data URL. Base64 is a third larger, and this
 * shares its quota with the assessment it belongs to.
 *
 * Uploaded on its own channel once the assessment has landed. Nothing here is
 * required for a visit to be valid, in both directions: a signature that will
 * not upload must not hold up the assessment, and an assessment that will not
 * sync must not lose the signature.
 */
export interface StoredAttachment {
    /** `${assessmentId}|${kind}|${role}` — one per role, replaced when redrawn. */
    key: string
    assessmentId: string
    kind: 'signature' | 'photo'
    role: SignatureRole | string
    /** For a photo: the question it is evidence for. Null for a signature. */
    questionCode: string | null
    /** Printed beside the mark. Read from the session or Part A, never typed twice. */
    signedName: string
    blob: Blob
    mime: string
    bytes: number
    capturedAt: string
    /** The same counter as answers, for the same reason — see StoredAnswer. */
    revision: number
    dirty: boolean
    /** The server's id, once it has acknowledged this one. */
    remoteId: string | null
    /**
     * Why the server refused this image, for good.
     *
     * Set only for a refusal retrying cannot fix, and it clears `dirty` with
     * it — otherwise a signature the server will never accept is re-uploaded
     * on every sync for the life of the device. It is shown against the
     * signature on the review screen, because the assessor can act on it by
     * drawing again, and nobody else will ever see it.
     */
    syncError: string | null
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
    kind: 'answer' | 'context' | 'assessment' | 'finding' | 'pathogens' | 'attachment'
    /** The natural key this entry concerns, where it has one. */
    subject: string
    payload: unknown
}

export class SpirdtDatabase extends Dexie {
    assessments!: Table<StoredAssessment, string>
    answers!: Table<StoredAnswer, string>
    findings!: Table<StoredFinding, string>
    attachments!: Table<StoredAttachment, string>
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

        // Version 4 adds signatures. Also nothing to migrate.
        //
        // Indexed by assessment only. `dirty` is deliberately not an index
        // here: IndexedDB has no boolean key type, so a boolean index stores
        // nothing and every query against it silently returns none — which is
        // why the tables above index it and then filter in JavaScript anyway.
        // With two signatures per visit there is nothing to gain by pretending
        // otherwise.
        this.version(4).stores({
            assessments: 'id, status, syncState, syncedAt, updatedAt',
            answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
            findings: 'key, assessmentId, dirty',
            attachments: 'key, assessmentId',
            journal: '++id, assessmentId, at',
        })

        // Version 5 lets one question carry several findings.
        //
        // The primary key changes meaning: it was the answer's natural key,
        // which made one-per-answer structural, and is now the finding's own
        // UUID. `questionKey` takes over the grouping.
        //
        // The upgrade re-keys rather than leaving the old rows alone, and that
        // is not tidiness. The server now upserts on this id and refuses
        // anything that is not a UUID, so a finding carried through with its
        // old composite key would be dropped on every sync — silently, with
        // the gap still on screen.
        //
        // Re-keying does mean the server can no longer recognise a finding it
        // has already stored: it minted its own id for those, which this
        // device was never told, so the next sync would offer a known finding
        // under an unknown id and get a second copy of it. `legacyKey` is the
        // one thing that identifies them, and this upgrade is the last moment
        // it exists — after it, the old key is gone. It is carried so the sync
        // can hand it back and the server can match on it.
        this.version(5)
            .stores({
                assessments: 'id, status, syncState, syncedAt, updatedAt',
                answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
                findings: 'key, assessmentId, [assessmentId+questionKey]',
                attachments: 'key, assessmentId',
                journal: '++id, assessmentId, at',
            })
            .upgrade(async (transaction) => {
                const table = transaction.table('findings')
                const existing = (await table.toArray()) as Array<Record<string, unknown>>

                if (existing.length === 0) {
                    return
                }

                await table.clear()

                await table.bulkAdd(
                    existing.map((row) => ({
                        ...row,
                        key: uuidv7(),
                        legacyKey: String(row.key),
                        questionKey: `${String(row.questionCode)}|${row.pathogen ?? ''}`,
                        urgency: null,
                        // The server has never seen this id, so it has to go
                        // again whatever the old row claimed.
                        dirty: true,
                        revision: Number(row.revision ?? 0) + 1,
                    })),
                )
            })
    }
}

export const db = new SpirdtDatabase()

export function answerKey(assessmentId: string, questionCode: string, pathogen: string | null): string {
    return `${assessmentId}|${questionCode}|${pathogen ?? ''}`
}
