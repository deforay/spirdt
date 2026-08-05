import { ApiError, apiRequest } from '../api/client'
import { dirtyAttachments, markAttachmentFailed, markAttachmentSynced } from '../db/attachments'
import type { StoredAttachment } from '../db/database'

/**
 * Getting signatures off the device.
 *
 * A separate channel from the assessment, which is the whole point of the
 * design: an image is orders of magnitude larger than the payload it belongs
 * to, and on the connection an offline-first tool exists for, the large thing
 * is the one that fails. Keeping them apart means a signature that will not
 * upload leaves a synced assessment behind it, rather than holding a whole
 * site visit hostage to a few kilobytes of PNG.
 *
 * The reverse is also true and less obvious: this runs only after the
 * assessment has landed. An attachment names an assessment the server has to
 * already have, so uploading first would earn a 404 on every retry until the
 * payload got through.
 */

interface UploadAck {
    id: string
    kind: string
    role: string | null
    signed_name: string | null
    checksum: string
    byte_size: number
}

/** Longer than the default: this is the one request here that carries an image. */
const UPLOAD_TIMEOUT_MS = 60_000

async function upload(row: StoredAttachment): Promise<void> {
    const form = new FormData()

    form.append('assessment_id', row.assessmentId)
    form.append('kind', row.kind)
    form.append('role', String(row.role))

    // Filed with the mark rather than resolved later. A user can be renamed
    // after a visit, and for a second assessor there is nothing to resolve
    // from at all — the system never knew they were there.
    form.append('signed_name', row.signedName)

    if (row.questionCode !== null) {
        form.append('question_code', row.questionCode)
    }

    // The filename is required by the multipart encoding and ignored by the
    // server, which mints its own — a name chosen by a client is how a path
    // traversal gets in, and nothing about this one is information.
    form.append('file', row.blob, 'signature.png')

    const ack = await apiRequest<UploadAck>('/sync/attachments', {
        body: form,
        timeoutMs: UPLOAD_TIMEOUT_MS,
    })

    // Against the revision that was sent, not whatever is on disk now. The
    // assessor may have redrawn the mark while this was in flight.
    await markAttachmentSynced(row.key, row.revision, ack.id)
}

/**
 * Send every image waiting for one assessment.
 *
 * A refusal and a failure are handled differently, for the same reason they
 * are on the assessment channel. A refusal the server will repeat — not an
 * image, too large — is recorded on the row and not tried again, because each
 * retry is a real upload on a connection somebody is paying for. Anything else
 * throws, and the backoff owns it.
 *
 * Throwing also stops the remaining images. A device with no signal would
 * otherwise attempt each one in turn and upload nothing several times over.
 */
export async function pushAttachments(assessmentId: string): Promise<void> {
    for (const row of await dirtyAttachments(assessmentId)) {
        try {
            await upload(row)
        } catch (error) {
            // 401 is excluded: the client refreshes and retries once on its
            // own, and a second 401 means the session went, not the image.
            if (error instanceof ApiError && !error.retryable && error.status !== 401) {
                await markAttachmentFailed(row.key, row.revision, error.message)

                continue
            }

            throw error
        }
    }
}
