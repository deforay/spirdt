import { db, type SignatureRole, type StoredAttachment } from './database'
import { uuidv7 } from './uuid'

/**
 * Signatures and photographs on the device.
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
            sectionCode: null,
            caption: null,
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

/**
 * How many photographs one part of an audit may carry.
 *
 * Matched by the server, which enforces it too — the screen is not the only
 * thing that can reach the upload endpoint, and a device carrying a queue from
 * an older build is a real case. Five is enough to show a room, a shelf, a log
 * book and two things that surprised the assessor; forty pictures of the same
 * fridge is a queue that will not clear on a district office's connection.
 */
export const PHOTOS_PER_SECTION = 5

export interface PhotoInput {
    assessmentId: string
    /** A section code, or 'site' for the setup screen. */
    sectionCode: string
    caption: string
    blob: Blob
}

/**
 * Keep a photograph taken during the visit.
 *
 * Keyed by its own UUID rather than by anything about the image. A section
 * holds several, and two taken a minute apart by a phone that did not move are
 * byte-identical — so a key derived from the content could not tell a retry
 * from a second picture, and the server would fold one into the other.
 *
 * Refuses past the limit rather than silently keeping the extra, because the
 * server refuses it too and a row that can never upload is worse than a button
 * that stops working.
 */
export async function savePhoto(input: PhotoInput): Promise<StoredAttachment | null> {
    const now = new Date().toISOString()
    const key = uuidv7()

    return db.transaction('rw', db.attachments, db.journal, async () => {
        const held = await photosForSection(input.assessmentId, input.sectionCode)

        if (held.length >= PHOTOS_PER_SECTION) {
            return null
        }

        const row: StoredAttachment = {
            key,
            assessmentId: input.assessmentId,
            kind: 'photo',
            // Photographs carry no role. The column means "which signature
            // slot" and inventing a value would put one in a slot.
            role: '',
            questionCode: null,
            sectionCode: input.sectionCode,
            caption: input.caption.trim() === '' ? null : input.caption.trim(),
            signedName: '',
            blob: input.blob,
            mime: input.blob.type === '' ? 'image/jpeg' : input.blob.type,
            bytes: input.blob.size,
            capturedAt: now,
            revision: 1,
            dirty: true,
            remoteId: null,
            syncError: null,
        }

        await db.attachments.put(row)

        // The image is not journalled, only the fact of it — see the note on
        // signatures below.
        await db.journal.add({
            assessmentId: input.assessmentId,
            at: now,
            kind: 'attachment',
            subject: key,
            payload: { sectionCode: input.sectionCode, bytes: row.bytes, revision: 1 },
        })

        return row
    })
}

/**
 * Change what the assessor said about a picture.
 *
 * The revision advances and the row goes dirty again, so the caption reaches
 * the server even when the image itself is already there — the upload is
 * idempotent on the key, and a second send with a different caption is a
 * correction rather than a second photograph.
 */
export async function setPhotoCaption(key: string, caption: string): Promise<void> {
    const row = await db.attachments.get(key)

    if (row === undefined || row.kind !== 'photo') {
        return
    }

    const trimmed = caption.trim()

    await db.attachments.update(key, {
        caption: trimmed === '' ? null : trimmed,
        revision: row.revision + 1,
        dirty: true,
        syncError: null,
    })
}

/**
 * Take a photograph back.
 *
 * One the server has never seen goes outright — there is nobody to tell. One
 * that has synced is kept as a tombstone until the delete is acknowledged,
 * because the alternative is a report carrying evidence the assessor deleted
 * and nothing anywhere able to reach it.
 */
export async function deletePhoto(key: string): Promise<void> {
    const row = await db.attachments.get(key)

    if (row === undefined || row.kind !== 'photo') {
        return
    }

    if (row.remoteId === null) {
        await db.attachments.delete(key)

        return
    }

    await db.attachments.update(key, {
        deleted: true,
        dirty: true,
        revision: row.revision + 1,
        syncError: null,
    })
}

/** Dropped once the server has confirmed it is gone. */
export async function forgetAttachment(key: string): Promise<void> {
    await db.attachments.delete(key)
}

/**
 * The photographs of one part of an audit, oldest first.
 *
 * Tombstones are filtered out: the assessor deleted them, and the screen must
 * not show a picture back to somebody who just removed it because the upload
 * has not caught up.
 */
export async function photosForSection(
    assessmentId: string,
    sectionCode: string,
): Promise<StoredAttachment[]> {
    const rows = await db.attachments
        .where('[assessmentId+sectionCode]')
        .equals([assessmentId, sectionCode])
        .toArray()

    return rows
        .filter((row) => row.kind === 'photo' && row.deleted !== true)
        .sort((a, b) => a.capturedAt.localeCompare(b.capturedAt))
}

/** Every photograph of a visit, for the review screen. */
export async function photosForAssessment(assessmentId: string): Promise<StoredAttachment[]> {
    const rows = await loadAttachments(assessmentId)

    return rows
        .filter((row) => row.kind === 'photo' && row.deleted !== true)
        .sort((a, b) => a.capturedAt.localeCompare(b.capturedAt))
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
