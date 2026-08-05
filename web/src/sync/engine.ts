import { ref } from 'vue'
import { ApiError, apiRequest } from '../api/client'
import { deviceId, isSignedIn } from '../auth/session'
import { flushWrites, loadAnswers, loadFindings, markBlocked, markSynced } from '../db/assessments'
import { assessmentsWithPendingAttachments, countPendingAttachments } from '../db/attachments'
import { db } from '../db/database'
import { pushAttachments } from './attachments'
import { acknowledged, buildPayload, NotSendable } from './payload'

/**
 * Getting assessments off the device.
 *
 * The device is the source of truth until the server acknowledges a visit, so
 * this is the last link in the chain that starts at a tap on a segmented
 * control. Everything here is arranged around one question: what happens when
 * this does not work?
 *
 *   No connection      keep the work, try again later, say so on screen
 *   Server error       same
 *   Refused payload    stop retrying, keep the work, show a person the reason
 *   Acknowledged       mark clean — but only the revisions actually sent
 *
 * Nothing in any of those branches deletes anything.
 */

export interface SyncStatus {
    running: boolean
    lastRunAt: string | null
    /** Set while sync is failing for a reason that will clear on its own. */
    lastError: string | null
    pending: number
    blocked: number
}

export const syncStatus = ref<SyncStatus>({
    running: false,
    lastRunAt: null,
    lastError: null,
    pending: 0,
    blocked: 0,
})

interface SyncAck {
    assessment_id: string
    accepted: string[]
    accepted_findings?: string[]
    score: Record<string, unknown>
}

export type SyncOutcome = 'synced' | 'blocked' | 'waiting' | 'nothing-to-send'

/**
 * Send one assessment.
 *
 * Throws only for failures worth retrying. A refusal is recorded on the row and
 * returned, because it is not this function's caller that can fix it.
 */
export async function syncAssessment(assessmentId: string): Promise<SyncOutcome> {
    // Answers are written on a per-question chain, so a sync started in the
    // same tick as a keystroke could otherwise read the row before the write
    // lands and send the previous value.
    await flushWrites()

    const assessment = await db.assessments.get(assessmentId)

    if (assessment === undefined) {
        return 'nothing-to-send'
    }

    const answers = await loadAnswers(assessmentId)
    const findings = await loadFindings(assessmentId)

    let built
    try {
        built = buildPayload(assessment, answers, deviceId(), findings)
    } catch (error) {
        if (error instanceof NotSendable) {
            // Incomplete rather than refused. It stays pending, and the reason
            // is what the assessor needs to see to finish it.
            await db.assessments.update(assessmentId, { syncError: error.message })

            return 'waiting'
        }

        throw error
    }

    const nothingChanged =
        built.sent.length === 0 &&
        built.sentFindings.length === 0 &&
        assessment.syncedAt !== null &&
        assessment.syncState === 'synced'

    if (nothingChanged) {
        return 'nothing-to-send'
    }

    try {
        const result = await apiRequest<SyncAck>('/sync/assessments', { body: built.payload })

        await markSynced(
            assessmentId,
            acknowledged(assessmentId, built.sent, result.accepted ?? []),
            acknowledged(assessmentId, built.sentFindings, result.accepted_findings ?? []),
        )

        return 'synced'
    } catch (error) {
        if (error instanceof ApiError && !error.retryable && error.status !== 401) {
            await markBlocked(assessmentId, error.message)

            return 'blocked'
        }

        throw error
    }
}

/**
 * Send everything waiting.
 *
 * Stops at the first retryable failure. Continuing would mean a device with no
 * signal walks its whole queue producing identical failures, and on a metered
 * connection each of those is a real upload attempt of a real payload.
 */
export async function syncAll(): Promise<void> {
    if (syncStatus.value.running || !isSignedIn()) {
        return
    }

    syncStatus.value = { ...syncStatus.value, running: true }

    try {
        const queue = await db.assessments.where('syncState').equals('pending').toArray()

        for (const assessment of queue) {
            await syncAssessment(assessment.id)
        }

        // Images go after every assessment, not after each one. They are the
        // slow half, and an assessment still sitting on the device is worth
        // more than a signature belonging to one that has already landed.
        //
        // Read fresh rather than derived from the queue above: an assessment
        // synced days ago can still be carrying a signature that has never
        // gone through.
        for (const assessmentId of await assessmentsWithPendingAttachments()) {
            await pushAttachments(assessmentId)
        }

        syncStatus.value = { ...syncStatus.value, lastError: null }
        resetBackoff()
    } catch (error) {
        syncStatus.value = {
            ...syncStatus.value,
            lastError: error instanceof Error ? error.message : 'Sync failed.',
        }

        growBackoff()
    } finally {
        syncStatus.value = {
            ...syncStatus.value,
            running: false,
            lastRunAt: new Date().toISOString(),
            // Images count towards pending. Reporting "Synced" while a
            // signature is still only on the tablet is the exact reassurance
            // this badge exists to avoid giving.
            pending:
                (await db.assessments.where('syncState').equals('pending').count()) +
                (await countPendingAttachments()),
            blocked: await db.assessments.where('syncState').equals('blocked').count(),
        }
    }
}

/**
 * Retry timing.
 *
 * Doubling, capped at five minutes, with jitter. The cap matters because a
 * device left in a vehicle overnight should still notice a connection within
 * minutes of one appearing. The jitter matters because a team of assessors
 * driving back into coverage together would otherwise all retry in the same
 * second, on the same tower.
 */
const MIN_DELAY_MS = 5_000
const MAX_DELAY_MS = 300_000

let delay = MIN_DELAY_MS
let timer: ReturnType<typeof setTimeout> | null = null
let started = false

function resetBackoff(): void {
    delay = MIN_DELAY_MS
}

function growBackoff(): void {
    delay = Math.min(MAX_DELAY_MS, delay * 2)
}

function schedule(): void {
    if (timer !== null) {
        clearTimeout(timer)
    }

    const jittered = delay * (0.5 + Math.random() * 0.5)

    timer = setTimeout(() => {
        void syncAll().finally(schedule)
    }, jittered)
}

function onOnline(): void {
    // A connection just appeared. Try immediately rather than waiting out a
    // backoff that was measuring an outage which has now ended.
    resetBackoff()
    void syncAll().finally(schedule)
}

/** Begin syncing in the background. Safe to call more than once. */
export function startSync(): void {
    if (started) {
        return
    }

    started = true
    window.addEventListener('online', onOnline)
    void syncAll().finally(schedule)
}

export function stopSync(): void {
    started = false
    window.removeEventListener('online', onOnline)

    if (timer !== null) {
        clearTimeout(timer)
        timer = null
    }
}
