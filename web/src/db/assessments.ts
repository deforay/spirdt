import {
    answerKey,
    db,
    type StoredAnswer,
    type StoredAssessment,
    type StoredFinding,
    type StoredPathogen,
    type StoredResponse,
} from './database'
import { plain } from './plain'
import { uuidv7 } from './uuid'

/**
 * Reading and writing assessments locally.
 *
 * Every write here does two things in one transaction: it updates the row, and
 * it appends to the journal. Both land or neither does. The journal is what
 * makes a bad write recoverable — an answers row can be rebuilt from it, and
 * `recoverFromJournal` does exactly that.
 *
 * Writes to the same key are chained rather than fired in parallel. Typing a
 * comment produces a write per keystroke, and without ordering the last write
 * to finish wins rather than the last write made, which silently truncates
 * whatever the assessor typed last.
 */

const chains = new Map<string, Promise<unknown>>()

function chain<T>(key: string, run: () => Promise<T>): Promise<T> {
    const previous = chains.get(key) ?? Promise.resolve()
    // Run whether or not the previous write succeeded: one failure must not
    // strand every later write to the same question.
    const next = previous.then(run, run)

    chains.set(
        key,
        next.catch(() => undefined),
    )

    return next
}

/** Resolves once every queued write has settled. For tests and for submit. */
export async function flushWrites(): Promise<void> {
    await Promise.allSettled([...chains.values()])
}

export interface NewAssessment {
    organizationId: number
    siteId?: string | null
    siteName: string
    facilityId?: string | null
    templateCode: string
    templateVersion: string
    /** Defaults to today. A visit entered the next morning must say so. */
    assessedOn?: string
    context?: Record<string, unknown>
    pathogens?: StoredPathogen[]
}

export async function createAssessment(input: NewAssessment): Promise<StoredAssessment> {
    const now = new Date().toISOString()

    const assessment: StoredAssessment = {
        id: uuidv7(),
        organizationId: input.organizationId,
        siteId: input.siteId ?? null,
        siteName: input.siteName,
        facilityId: input.facilityId ?? null,
        templateCode: input.templateCode,
        templateVersion: input.templateVersion,
        assessedOn: input.assessedOn ?? now.slice(0, 10),
        context: input.context ?? {},
        pathogens: input.pathogens ?? [],
        status: 'draft',
        startedAt: now,
        updatedAt: now,
        syncedAt: null,
        syncState: 'pending',
        syncError: null,
    }

    await db.transaction('rw', db.assessments, db.journal, async () => {
        await db.assessments.put(assessment)
        await db.journal.add({
            assessmentId: assessment.id,
            at: now,
            kind: 'assessment',
            subject: assessment.id,
            payload: assessment,
        })
    })

    return assessment
}

export function getAssessment(id: string): Promise<StoredAssessment | undefined> {
    return db.assessments.get(id)
}

export function listAssessments(): Promise<StoredAssessment[]> {
    return db.assessments.orderBy('updatedAt').reverse().toArray()
}

export function loadAnswers(assessmentId: string): Promise<StoredAnswer[]> {
    return db.answers.where('assessmentId').equals(assessmentId).toArray()
}

export interface AnswerPatch {
    response?: StoredResponse | null
    comment?: string
}

/**
 * Write one answer.
 *
 * Awaited by the caller so a failure is surfaced rather than swallowed. An
 * unreported failed write is worse than a crash: the assessor sees the answer
 * on screen and has no reason to doubt it.
 */
export async function saveAnswer(
    assessmentId: string,
    questionCode: string,
    pathogen: string | null,
    patch: AnswerPatch,
): Promise<StoredAnswer> {
    // The patch carries what the assessor typed, so it arrives reactive.
    patch = plain(patch)

    const key = answerKey(assessmentId, questionCode, pathogen)

    return chain(key, async () => {
        const now = new Date().toISOString()

        return db.transaction('rw', db.answers, db.assessments, db.journal, async () => {
            const existing = await db.answers.get(key)

            const row: StoredAnswer = {
                key,
                assessmentId,
                questionCode,
                pathogen,
                response: patch.response !== undefined ? patch.response : (existing?.response ?? null),
                comment: patch.comment !== undefined ? patch.comment : (existing?.comment ?? ''),
                answeredAt: now,
                updatedAt: now,
                revision: (existing?.revision ?? 0) + 1,
                dirty: true,
            }

            await db.answers.put(row)
            await db.journal.add({
                assessmentId,
                at: now,
                kind: 'answer',
                subject: key,
                payload: { response: row.response, comment: row.comment },
            })

            // Any edit re-arms the sync, including on an assessment the server
            // previously refused: the refusal was about the content, and the
            // content has just changed. Without this a blocked assessment stays
            // blocked no matter what the assessor corrects.
            await db.assessments.update(assessmentId, {
                updatedAt: now,
                syncState: 'pending',
                syncError: null,
            })

            return row
        })
    })
}

export async function saveContext(
    assessmentId: string,
    input: Record<string, unknown>,
): Promise<void> {
    // Everything the assessor types reaches here through a ref. IndexedDB
    // cannot clone a Proxy, so this is where reactivity stops. See plain().
    const context = plain(input)

    await chain(`context:${assessmentId}`, async () => {
        const now = new Date().toISOString()

        await db.transaction('rw', db.assessments, db.journal, async () => {
            await db.assessments.update(assessmentId, {
                context,
                updatedAt: now,
                syncState: 'pending',
                syncError: null,
            })
            await db.journal.add({
                assessmentId,
                at: now,
                kind: 'context',
                subject: assessmentId,
                payload: context,
            })
        })
    })
}

/**
 * Set which pathogens the visit covers.
 *
 * Section 4 repeats once per entry, so changing this changes what counts as a
 * complete assessment. Answers belonging to a removed pathogen are left alone
 * rather than deleted: the scoring engine reports them as unexpected and
 * ignores them, and a pathogen removed by mistake can be added back with its
 * answers intact. The server drops them from the payload for the same reason.
 */
export async function savePathogens(
    assessmentId: string,
    input: StoredPathogen[],
): Promise<void> {
    const pathogens = plain(input)

    await chain(`pathogens:${assessmentId}`, async () => {
        const now = new Date().toISOString()

        await db.transaction('rw', db.assessments, db.journal, async () => {
            await db.assessments.update(assessmentId, {
                pathogens,
                updatedAt: now,
                syncState: 'pending',
                syncError: null,
            })
            await db.journal.add({
                assessmentId,
                at: now,
                kind: 'pathogens',
                subject: assessmentId,
                payload: pathogens,
            })
        })
    })
}

export interface FindingPatch {
    gap?: string
    recommendation?: string
    responsibilityLevel?: StoredFinding['responsibilityLevel']
    urgency?: StoredFinding['urgency']
    responsiblePerson?: string
    dueDate?: string | null
}

/** `${questionCode}|${pathogen ?? ''}` — what groups several findings under one answer. */
export function questionGroupKey(questionCode: string, pathogen: string | null): string {
    return `${questionCode}|${pathogen ?? ''}`
}

/**
 * Start a new finding against an answer.
 *
 * Separate from updating one, because a question may carry several and the
 * caller has to say which it means. The row is created empty: an assessor adds
 * the slot and then types into it, which is the order the screen works in.
 */
export async function addFinding(
    assessmentId: string,
    questionCode: string,
    pathogen: string | null,
    response: 'P' | 'N',
): Promise<StoredFinding> {
    const key = uuidv7()
    const now = new Date().toISOString()

    return chain(`finding:${key}`, async () =>
        db.transaction('rw', db.findings, db.assessments, db.journal, async () => {
            const row: StoredFinding = {
                key,
                // Raised here, so the server has never seen it under any other
                // name. Only the version 5 upgrade sets this.
                legacyKey: null,
                assessmentId,
                questionKey: questionGroupKey(questionCode, pathogen),
                questionCode,
                pathogen,
                response,
                gap: '',
                recommendation: '',
                responsibilityLevel: 'site',
                urgency: null,
                responsiblePerson: '',
                dueDate: null,
                updatedAt: now,
                revision: 1,
                dirty: true,
            }

            await db.findings.put(row)
            await db.journal.add({
                assessmentId,
                at: now,
                kind: 'finding',
                subject: key,
                payload: row,
            })
            await db.assessments.update(assessmentId, {
                updatedAt: now,
                syncState: 'pending',
                syncError: null,
            })

            return row
        }),
    )
}

/**
 * Write to one finding, by its own id.
 *
 * Journalled like an answer, and for the same reason: this is the part of the
 * visit the site is debriefed on, and it is typed at the end of a long day.
 */
export async function saveFinding(key: string, patch: FindingPatch): Promise<StoredFinding | null> {
    patch = plain(patch)

    return chain(`finding:${key}`, async () => {
        const now = new Date().toISOString()

        return db.transaction('rw', db.findings, db.assessments, db.journal, async () => {
            const existing = await db.findings.get(key)

            if (existing === undefined) {
                return null
            }

            const row: StoredFinding = {
                ...existing,
                gap: patch.gap ?? existing.gap,
                recommendation: patch.recommendation ?? existing.recommendation,
                responsibilityLevel: patch.responsibilityLevel ?? existing.responsibilityLevel,
                urgency: patch.urgency !== undefined ? patch.urgency : existing.urgency,
                responsiblePerson: patch.responsiblePerson ?? existing.responsiblePerson,
                dueDate: patch.dueDate !== undefined ? patch.dueDate : existing.dueDate,
                updatedAt: now,
                revision: existing.revision + 1,
                dirty: true,
            }

            await db.findings.put(row)
            await db.journal.add({
                assessmentId: row.assessmentId,
                at: now,
                kind: 'finding',
                subject: key,
                payload: row,
            })
            await db.assessments.update(row.assessmentId, {
                updatedAt: now,
                syncState: 'pending',
                syncError: null,
            })

            return row
        })
    })
}

export function loadFindings(assessmentId: string): Promise<StoredFinding[]> {
    return db.findings.where('assessmentId').equals(assessmentId).toArray()
}

/** Remove one finding the assessor no longer wants. */
export async function removeFinding(key: string): Promise<void> {
    await chain(`finding:${key}`, async () => {
        const existing = await db.findings.get(key)

        if (existing === undefined) {
            return
        }

        const now = new Date().toISOString()

        await db.transaction('rw', db.findings, db.journal, async () => {
            await db.journal.add({
                assessmentId: existing.assessmentId,
                at: now,
                kind: 'finding',
                subject: key,
                payload: { discarded: true, was: existing },
            })
            await db.findings.delete(key)
        })
    })
}

/**
 * Drop every finding on a question that no longer has an answer to hang on.
 *
 * Called when a Partial or No is corrected to Yes or Not applicable. ALL of
 * them go, not one — a question may carry several, and leaving the rest would
 * hand the site an action list for a shortfall the assessment no longer
 * records. The gap text stays in the journal, so a mis-tap is recoverable.
 */
export async function discardFindingsFor(
    assessmentId: string,
    questionCode: string,
    pathogen: string | null,
): Promise<void> {
    const doomed = await db.findings
        .where('[assessmentId+questionKey]')
        .equals([assessmentId, questionGroupKey(questionCode, pathogen)])
        .toArray()

    for (const finding of doomed) {
        await removeFinding(finding.key)
    }
}

/** Mark the assessment ready to submit, and then submitted. */
export async function setStatus(
    assessmentId: string,
    status: StoredAssessment['status'],
): Promise<void> {
    const now = new Date().toISOString()

    await db.transaction('rw', db.assessments, db.journal, async () => {
        await db.assessments.update(assessmentId, {
            status,
            updatedAt: now,
            syncState: 'pending',
            syncError: null,
        })
        await db.journal.add({
            assessmentId,
            at: now,
            kind: 'assessment',
            subject: assessmentId,
            payload: { status },
        })
    })
}

/** What was sent, as it was at the moment it was sent. */
export interface AcknowledgedAnswer {
    key: string
    /** The revision that went to the server, not the one on disk now. */
    revision: number
}

/**
 * Mark rows the server has acknowledged.
 *
 * The revision check is the whole point. A sync takes seconds on a bad
 * connection, and the assessor keeps working through it — so by the time the
 * acknowledgement lands, an answer in that payload may already have been
 * changed on screen. Clearing its dirty flag on the strength of the OLD
 * revision would mean that edit is never sent and never retried, and nothing
 * anywhere would look wrong: the answer reads correctly on the device and is
 * simply absent from the server's copy for good.
 *
 * So a row whose revision has moved is left dirty and goes in the next sync.
 *
 * Nothing is deleted. The device keeps the assessment so the assessor can still
 * read what they filed, and so a server that later says it never received it
 * can be contradicted.
 */
export async function markSynced(
    assessmentId: string,
    acknowledged: AcknowledgedAnswer[],
    acknowledgedFindings: AcknowledgedAnswer[] = [],
): Promise<void> {
    const now = new Date().toISOString()

    await db.transaction('rw', db.answers, db.findings, db.assessments, async () => {
        for (const entry of acknowledged) {
            const row = await db.answers.get(entry.key)

            if (row === undefined || row.revision !== entry.revision) {
                continue
            }

            await db.answers.update(entry.key, { dirty: false })
        }

        for (const entry of acknowledgedFindings) {
            const row = await db.findings.get(entry.key)

            if (row === undefined || row.revision !== entry.revision) {
                continue
            }

            await db.findings.update(entry.key, { dirty: false })
        }

        const dirtyFindings = await db.findings
            .where('assessmentId')
            .equals(assessmentId)
            .filter((row) => row.dirty)
            .count()

        const stillDirty = await db.answers
            .where('assessmentId')
            .equals(assessmentId)
            .filter((row) => row.dirty)
            .count()

        await db.assessments.update(assessmentId, {
            syncedAt: now,
            syncState: stillDirty === 0 && dirtyFindings === 0 ? 'synced' : 'pending',
            syncError: null,
        })
    })
}

/**
 * Record that the server refused, and will refuse again.
 *
 * Kept on the row rather than in memory so the reason survives a reload — the
 * device may not be looked at again until someone asks why a visit never
 * arrived.
 */
export async function markBlocked(assessmentId: string, reason: string): Promise<void> {
    await db.assessments.update(assessmentId, { syncState: 'blocked', syncError: reason })
}

export function pendingAnswers(assessmentId: string): Promise<StoredAnswer[]> {
    return db.answers
        .where('assessmentId')
        .equals(assessmentId)
        .filter((row) => row.dirty)
        .toArray()
}

/**
 * Rebuild an assessment's answers from the journal.
 *
 * The journal is append-only and written in the same transaction as the row it
 * describes, so replaying it in order reproduces the answers table. This is
 * the reason the journal exists, and it is exercised by the tests rather than
 * left as a claim.
 */
export async function recoverFromJournal(assessmentId: string): Promise<number> {
    const entries = await db.journal.where('assessmentId').equals(assessmentId).sortBy('id')

    let restored = 0

    // Rebuilt by counting replays rather than carried in the journal: the
    // revision only has to increase with each write to the same answer, and
    // the journal is already in write order.
    const revisions = new Map<string, number>()

    await db.transaction('rw', db.answers, async () => {
        for (const entry of entries) {
            if (entry.kind !== 'answer') {
                continue
            }

            const payload = entry.payload as { response: StoredResponse | null; comment: string }
            const [, questionCode, pathogen] = entry.subject.split('|')
            const revision = (revisions.get(entry.subject) ?? 0) + 1

            revisions.set(entry.subject, revision)

            await db.answers.put({
                key: entry.subject,
                assessmentId,
                questionCode: questionCode ?? '',
                pathogen: pathogen === '' ? null : (pathogen ?? null),
                response: payload.response,
                comment: payload.comment,
                answeredAt: entry.at,
                updatedAt: entry.at,
                revision,
                dirty: true,
            })

            restored += 1
        }
    })

    return restored
}
