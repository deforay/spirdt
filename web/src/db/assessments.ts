import { answerKey, db, type StoredAnswer, type StoredAssessment, type StoredResponse } from './database'
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
 * whatever the auditor typed last.
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
    templateCode: string
    templateVersion: string
    context?: Record<string, unknown>
    pathogens?: string[]
}

export async function createAssessment(input: NewAssessment): Promise<StoredAssessment> {
    const now = new Date().toISOString()

    const assessment: StoredAssessment = {
        id: uuidv7(),
        organizationId: input.organizationId,
        siteId: input.siteId ?? null,
        siteName: input.siteName,
        templateCode: input.templateCode,
        templateVersion: input.templateVersion,
        context: input.context ?? {},
        pathogens: input.pathogens ?? [],
        status: 'draft',
        startedAt: now,
        updatedAt: now,
        syncedAt: null,
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
 * unreported failed write is worse than a crash: the auditor sees the answer
 * on screen and has no reason to doubt it.
 */
export async function saveAnswer(
    assessmentId: string,
    questionCode: string,
    pathogen: string | null,
    patch: AnswerPatch,
): Promise<StoredAnswer> {
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
            await db.assessments.update(assessmentId, { updatedAt: now })

            return row
        })
    })
}

export async function saveContext(
    assessmentId: string,
    context: Record<string, unknown>,
): Promise<void> {
    await chain(`context:${assessmentId}`, async () => {
        const now = new Date().toISOString()

        await db.transaction('rw', db.assessments, db.journal, async () => {
            await db.assessments.update(assessmentId, { context, updatedAt: now })
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
 * Mark rows the server has acknowledged.
 *
 * Nothing is deleted. The device keeps the assessment so the auditor can still
 * read what they filed, and so a server that later says it never received it
 * can be contradicted.
 */
export async function markSynced(assessmentId: string, answerKeys: string[]): Promise<void> {
    const now = new Date().toISOString()

    await db.transaction('rw', db.answers, db.assessments, async () => {
        for (const key of answerKeys) {
            await db.answers.update(key, { dirty: false })
        }

        await db.assessments.update(assessmentId, { syncedAt: now })
    })
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

    await db.transaction('rw', db.answers, async () => {
        for (const entry of entries) {
            if (entry.kind !== 'answer') {
                continue
            }

            const payload = entry.payload as { response: StoredResponse | null; comment: string }
            const [, questionCode, pathogen] = entry.subject.split('|')

            await db.answers.put({
                key: entry.subject,
                assessmentId,
                questionCode: questionCode ?? '',
                pathogen: pathogen === '' ? null : (pathogen ?? null),
                response: payload.response,
                comment: payload.comment,
                answeredAt: entry.at,
                updatedAt: entry.at,
                dirty: true,
            })

            restored += 1
        }
    })

    return restored
}
