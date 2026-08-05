import { db } from './database'

import type { MessageKey } from '@/i18n'

/**
 * Whether this device will actually keep what the assessor writes.
 *
 * Writing to IndexedDB is the easy half. The half that loses data:
 *
 *   - Private browsing can refuse IndexedDB outright, or accept writes and
 *     discard them when the tab closes.
 *   - Browsers evict storage from sites they consider unimportant. A browser
 *     opened once for a site visit and not touched for a week is exactly what
 *     that heuristic is built to clear. Installing the app to the home screen
 *     is what moves it out of that category.
 *   - A full disk fails a write, and a failed write that nobody surfaces is
 *     indistinguishable from a saved one.
 *
 * None of this is detectable after the fact, so it is all checked before the
 * assessor starts rather than after they have filled in fifty-nine questions.
 */

export type StorageRisk = 'safe' | 'at-risk' | 'broken'

export interface StorageReport {
    risk: StorageRisk
    /** The browser promised not to evict this data. */
    persisted: boolean
    /** Running as an installed app rather than a browser tab. */
    installed: boolean
    /** A write was made and read back successfully. */
    writable: boolean
    usageBytes: number | null
    quotaBytes: number | null
    /**
     * What to tell the assessor, as a key rather than a sentence. The report is
     * built once, before the visit starts; the language can be changed after
     * that, and a warning frozen in the language of the moment it was raised is
     * one nobody reads. Null when there is nothing to say.
     */
    messageKey: MessageKey | null
}

const CANARY = '__write_check__'

/**
 * Write something and read it back.
 *
 * Opening the database is not proof it works. Private browsing in some
 * browsers accepts an open and then fails or discards the write, so the only
 * honest test is a round trip.
 */
export async function verifyWritable(): Promise<boolean> {
    const stamp = new Date().toISOString()

    try {
        await db.journal.put({
            assessmentId: CANARY,
            at: stamp,
            kind: 'assessment',
            subject: CANARY,
            payload: { stamp },
        })

        const rows = await db.journal.where('assessmentId').equals(CANARY).toArray()
        const found = rows.some((row) => row.at === stamp)

        await db.journal.where('assessmentId').equals(CANARY).delete()

        return found
    } catch {
        return false
    }
}

/**
 * Ask the browser not to evict this data.
 *
 * Browsers decide for themselves and may say no. A refusal is not a failure to
 * handle — it is a fact to report, because it changes what the assessor should
 * do: install the app, and sync sooner rather than at the end of the day.
 */
export async function requestPersistence(): Promise<boolean> {
    if (typeof navigator === 'undefined' || !navigator.storage) {
        return false
    }

    try {
        if (typeof navigator.storage.persisted === 'function' && (await navigator.storage.persisted())) {
            return true
        }

        if (typeof navigator.storage.persist === 'function') {
            return await navigator.storage.persist()
        }
    } catch {
        return false
    }

    return false
}

export async function storageEstimate(): Promise<{ usage: number | null; quota: number | null }> {
    if (typeof navigator === 'undefined' || !navigator.storage?.estimate) {
        return { usage: null, quota: null }
    }

    try {
        const estimate = await navigator.storage.estimate()
        return { usage: estimate.usage ?? null, quota: estimate.quota ?? null }
    } catch {
        return { usage: null, quota: null }
    }
}

/** Running from the home screen rather than a browser tab. */
export function isInstalled(): boolean {
    if (typeof window === 'undefined') {
        return false
    }

    const standalone = window.matchMedia?.('(display-mode: standalone)').matches === true
    const iosStandalone = (window.navigator as { standalone?: boolean }).standalone === true

    return standalone || iosStandalone
}

export async function checkStorage(): Promise<StorageReport> {
    const writable = await verifyWritable()
    const persisted = await requestPersistence()
    const installed = isInstalled()
    const { usage, quota } = await storageEstimate()

    let risk: StorageRisk = 'safe'
    let messageKey: MessageKey | null = null

    if (!writable) {
        risk = 'broken'
        messageKey = 'storage.notWritable'
    } else if (!persisted && !installed) {
        risk = 'at-risk'
        messageKey = 'storage.mayClear'
    } else if (!persisted) {
        risk = 'at-risk'
        messageKey = 'storage.notPersisted'
    }

    // Under a megabyte of headroom will not finish an assessment with photos.
    if (risk !== 'broken' && quota !== null && usage !== null && quota - usage < 1_000_000) {
        risk = 'at-risk'
        messageKey = 'storage.almostFull'
    }

    return {
        risk,
        persisted,
        installed,
        writable,
        usageBytes: usage,
        quotaBytes: quota,
        messageKey,
    }
}
