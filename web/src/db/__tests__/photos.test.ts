import 'fake-indexeddb/auto'

import { beforeEach, describe, expect, it } from 'vitest'

import {
    deletePhoto,
    forgetAttachment,
    photosForAssessment,
    photosForSection,
    PHOTOS_PER_SECTION,
    savePhoto,
    setPhotoCaption,
} from '../attachments'
import { createAssessment } from '../assessments'
import { db } from '../database'

/**
 * Photographs on the device.
 *
 * Three rules are worth pinning, and each of them is a case where the obvious
 * implementation loses somebody's evidence.
 *
 * A section holds several, and two of them may be byte-identical — a phone
 * that did not move between shots produces the same bytes — so identity has to
 * come from the device rather than from the image.
 *
 * The limit is enforced where the write happens, not only on the button,
 * because the button is one build of one client.
 *
 * And a photograph deleted after it has synced cannot simply vanish from the
 * table: the server still holds it, so the row has to survive as a tombstone
 * until the delete has been sent. One that never left the device goes outright.
 */

async function newAssessment(): Promise<string> {
    const created = await createAssessment({
        organizationId: 1,
        siteId: '019fd700-0000-7000-8000-00000000000a',
        facilityId: '019fd700-0000-7000-8000-00000000000b',
        siteName: 'Kitwe TB clinic',
        templateCode: 'spi-rdt',
        templateVersion: '1.0.0',
    })

    return created.id
}

function image(text = 'a photograph'): Blob {
    return new Blob([text], { type: 'image/jpeg' })
}

beforeEach(async () => {
    await db.delete()
    await db.open()
})

describe('photographs', () => {
    it('keeps one per section and reads it back', async () => {
        const id = await newAssessment()

        await savePhoto({ assessmentId: id, sectionCode: '2', caption: 'Fridge', blob: image() })

        const held = await photosForSection(id, '2')

        expect(held).toHaveLength(1)
        expect(held[0]?.caption).toBe('Fridge')
        expect(held[0]?.dirty).toBe(true)
        expect(await photosForSection(id, '3')).toHaveLength(0)
    })

    it('gives identical images their own identity', async () => {
        const id = await newAssessment()
        const bytes = image('same shelf, same phone, one minute apart')

        const first = await savePhoto({ assessmentId: id, sectionCode: '2', caption: '', blob: bytes })
        const second = await savePhoto({ assessmentId: id, sectionCode: '2', caption: '', blob: bytes })

        expect(first?.key).not.toBe(second?.key)
        expect(await photosForSection(id, '2')).toHaveLength(2)
    })

    it('refuses past the limit rather than keeping one that can never upload', async () => {
        const id = await newAssessment()

        for (let taken = 0; taken < PHOTOS_PER_SECTION; taken++) {
            expect(await savePhoto({ assessmentId: id, sectionCode: '4', caption: '', blob: image(String(taken)) })).not.toBeNull()
        }

        expect(await savePhoto({ assessmentId: id, sectionCode: '4', caption: '', blob: image('one too many') })).toBeNull()
        expect(await photosForSection(id, '4')).toHaveLength(PHOTOS_PER_SECTION)
    })

    it('sends a corrected caption again', async () => {
        const id = await newAssessment()
        const saved = await savePhoto({ assessmentId: id, sectionCode: '1', caption: 'Shelf', blob: image() })

        // As though it had been uploaded: the caption still has to reach the
        // server, and the upload is idempotent on the key.
        await db.attachments.update(saved!.key, { dirty: false, remoteId: 'server-id' })
        await setPhotoCaption(saved!.key, 'Shelf with no reagents')

        const row = await db.attachments.get(saved!.key)

        expect(row?.caption).toBe('Shelf with no reagents')
        expect(row?.dirty).toBe(true)
        expect(row?.revision).toBe(2)
    })

    it('removes an unsynced photograph outright', async () => {
        const id = await newAssessment()
        const saved = await savePhoto({ assessmentId: id, sectionCode: '1', caption: '', blob: image() })

        await deletePhoto(saved!.key)

        expect(await db.attachments.get(saved!.key)).toBeUndefined()
    })

    it('keeps a synced one as a tombstone until the delete has been sent', async () => {
        const id = await newAssessment()
        const saved = await savePhoto({ assessmentId: id, sectionCode: '1', caption: '', blob: image() })

        await db.attachments.update(saved!.key, { dirty: false, remoteId: 'server-id' })
        await deletePhoto(saved!.key)

        const row = await db.attachments.get(saved!.key)

        expect(row?.deleted).toBe(true)
        expect(row?.dirty).toBe(true)
        // Gone from the screen the moment the assessor deleted it, whatever
        // the upload queue is still doing.
        expect(await photosForSection(id, '1')).toHaveLength(0)
        expect(await photosForAssessment(id)).toHaveLength(0)

        await forgetAttachment(saved!.key)

        expect(await db.attachments.get(saved!.key)).toBeUndefined()
    })
})
