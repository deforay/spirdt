import { db, type SignatureRole, type StoredAttachment } from './database'

/**
 * Signatures on the device.
 *
 * The same shape as answers and findings — write immediately, mark dirty, let
 * the sync clear the flag against the revision it actually sent — with one
 * difference that matters. A signature is not edited, it is replaced: an
 * assessor who is unhappy with a mark draws it again, and the new one wholly
 * supersedes the old. So the key is the role rather than the drawing, and
 * there is never more than one image per role on the device or on the server.
 */

export function attachmentKey(assessmentId: string, kind: string, role: string): string {
    return `${assessmentId}|${kind}|${role}`
}

export interface SignatureInput {
    assessmentId: string
    role: SignatureRole
    signedName: string
    blob: Blob
}

/**
 * Store a freshly drawn signature.
 *
 * The revision advances on every save, including a redraw, so an upload that
 * was in flight when the assessor drew again cannot come back and mark the new
 * mark clean — which would leave the server holding the drawing that was
 * rejected.
 */
export async function saveSignature(input: SignatureInput): Promise<StoredAttachment> {
    const key = attachmentKey(input.assessmentId, 'signature', input.role)
    const now = new Date().toISOString()

    return db.transaction('rw', db.attachments, db.journal, async () => {
        const existing = await db.attachments.get(key)

        const row: StoredAttachment = {
            key,
            assessmentId: input.assessmentId,
            kind: 'signature',
            role: input.role,
            questionCode: null,
            signedName: input.signedName,
            blob: input.blob,
            mime: input.blob.type === '' ? 'image/png' : input.blob.type,
            bytes: input.blob.size,
            capturedAt: now,
            revision: (existing?.revision ?? 0) + 1,
            dirty: true,
            remoteId: null,
            syncError: null,
        }

        await db.attachments.put(row)

        // The image is not journalled, only the fact of it. The journal exists
        // to replay a visit cheaply, and a few hundred kilobytes per entry
        // would undo that.
        await db.journal.add({
            assessmentId: input.assessmentId,
            at: now,
            kind: 'attachment',
            subject: key,
            payload: {
                role: input.role,
                signedName: input.signedName,
                bytes: row.bytes,
                revision: row.revision,
            },
        })

        return row
    })
}

export async function loadAttachments(assessmentId: string): Promise<StoredAttachment[]> {
    return db.attachments.where('assessmentId').equals(assessmentId).toArray()
}

export async function dirtyAttachments(assessmentId: string): Promise<StoredAttachment[]> {
    const rows = await loadAttachments(assessmentId)

    return rows.filter((row) => row.dirty)
}

/** Assessment ids with at least one image still to upload. */
export async function assessmentsWithPendingAttachments(): Promise<string[]> {
    const rows = await db.attachments.filter((row) => row.dirty).toArray()

    return [...new Set(rows.map((row) => row.assessmentId))]
}

export async function countPendingAttachments(): Promise<number> {
    return db.attachments.filter((row) => row.dirty).count()
}

/**
 * Clear the dirty flag, but only if the row is still the one that was sent.
 *
 * The same guard as answers, and it earns its keep more here: an upload takes
 * seconds, and redrawing a signature during one is exactly what an assessor
 * does when the first attempt looks wrong. Marking the new drawing clean on
 * the strength of the old upload would leave the rejected mark on the server
 * for good, with nothing on the device looking wrong.
 */
export async function markAttachmentSynced(
    key: string,
    revision: number,
    remoteId: string,
): Promise<void> {
    const row = await db.attachments.get(key)

    if (row === undefined || row.revision !== revision) {
        return
    }

    await db.attachments.update(key, { dirty: false, remoteId, syncError: null })
}

/**
 * Stop retrying an image the server will never take.
 *
 * Clears `dirty` deliberately. A refusal that retrying cannot fix — not an
 * image, too large, an assessment in another organisation — would otherwise be
 * re-sent on every sync for as long as the device lives, and each attempt is a
 * real upload on a connection somebody is paying for.
 *
 * The same revision guard applies: a mark redrawn since the failed upload is a
 * different mark, and it deserves its own attempt.
 */
export async function markAttachmentFailed(
    key: string,
    revision: number,
    reason: string,
): Promise<void> {
    const row = await db.attachments.get(key)

    if (row === undefined || row.revision !== revision) {
        return
    }

    await db.attachments.update(key, { dirty: false, syncError: reason })
}

// There is deliberately no way to un-sign. A mark can be drawn again, and the
// new one replaces the old on the device and on the server; removing one
// outright would need a delete endpoint, and on an audit instrument "this was
// signed and then unsigned by someone" is a question worth having to answer
// deliberately rather than by tapping Clear.
