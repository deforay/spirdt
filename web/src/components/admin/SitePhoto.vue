<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'

import { deleteSitePhoto, fetchSitePhoto, uploadSitePhoto } from '@/api/registry'
import { t } from '@/i18n'

/**
 * What the bench actually looks like.
 *
 * A testing site is named by the people who work there — "Lab 2", "ART
 * corner", "the back room" — and an assessor arriving at a hospital with four
 * benches has a line of free text and somebody at reception to go on. A
 * photograph is the one part of the record that cannot be typed wrong.
 *
 * CAPTURED RATHER THAN UPLOADED, where the device allows it: `capture` sends a
 * phone or tablet straight to its camera, and falls back to the file picker on
 * a desktop, which is the right behaviour on both. Nothing here depends on it.
 *
 * RESIZED BEFORE IT IS SENT, and this is not an optimisation. A photograph off
 * a current phone is several megabytes — past what the server accepts — and
 * none of that detail helps somebody recognise a room. The upload also happens
 * over whatever connection a district office has, so the difference between
 * three megabytes and two hundred kilobytes is the difference between a photo
 * that arrives and one that times out.
 *
 * SENT AS SOON AS IT IS TAKEN, rather than held until Save. The site already
 * exists by the time this is shown, the image is not part of the form's
 * payload, and holding it would mean a photograph lost to a mis-click on
 * Cancel.
 */

const props = defineProps<{
    /** Null while the site is still being created — there is nothing to attach to yet. */
    siteId: string | null
    hasPhoto: boolean
    /** For the alt text, so a reader who cannot see it is told what it is of. */
    siteName: string
    /** False for somebody who may read the registry but not change it. */
    editable: boolean
}>()

const emit = defineEmits<{ change: [hasPhoto: boolean] }>()

/**
 * The long edge, in pixels.
 *
 * Enough to read a label on a shelf, and about a fifth of what the camera
 * produced. A room is recognisable well below this; a serial number on a
 * machine is not, and that is what pushes it above a thumbnail.
 */
const MAX_EDGE = 1600

/** Visibly indistinguishable from the original at this size, and a third of the bytes. */
const QUALITY = 0.82

const imageUrl = ref<string | null>(null)
const busy = ref(false)
const confirming = ref(false)
const error = ref('')

function show(blob: Blob | null): void {
    // Object URLs are held by the document until they are revoked, so the old
    // one goes before the new one arrives rather than at unmount.
    if (imageUrl.value !== null) {
        URL.revokeObjectURL(imageUrl.value)
    }

    imageUrl.value = blob === null ? null : URL.createObjectURL(blob)
}

async function load(): Promise<void> {
    if (props.siteId === null || !props.hasPhoto) {
        show(null)

        return
    }

    show(await fetchSitePhoto(props.siteId))
}

/**
 * The same picture, small enough to send.
 *
 * `imageOrientation` matters more than it looks: a photograph taken with the
 * phone on its side is stored upright with a rotation flag in its EXIF, and a
 * canvas that ignores that produces a sideways image with no flag left to fix
 * it. Decoding with the orientation applied bakes it in the right way up.
 *
 * Falls back to the original bytes rather than failing. A browser without
 * createImageBitmap or toBlob still gets to send a photograph, and the server
 * refuses it with a size message if it is too big — which is a worse outcome
 * than resizing and a much better one than a button that does nothing.
 */
async function resized(file: File): Promise<Blob> {
    if (typeof createImageBitmap !== 'function') {
        return file
    }

    let bitmap: ImageBitmap

    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch {
        return file
    }

    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)

    const context = canvas.getContext('2d')

    if (context === null) {
        bitmap.close()

        return file
    }

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    bitmap.close()

    const encoded = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', QUALITY),
    )

    return encoded ?? file
}

async function onPicked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0] ?? null

    // Cleared straight away so that choosing the same file twice — after a
    // failed upload, say — still fires a change event.
    input.value = ''

    if (file === null || props.siteId === null) {
        return
    }

    if (!file.type.startsWith('image/')) {
        error.value = t('sitePhoto.notAnImage')

        return
    }

    error.value = ''
    busy.value = true

    try {
        const image = await resized(file)
        await uploadSitePhoto(props.siteId, image)
        show(image)
        emit('change', true)
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
    } finally {
        busy.value = false
    }
}

async function onRemove(): Promise<void> {
    if (props.siteId === null) {
        return
    }

    confirming.value = false
    error.value = ''
    busy.value = true

    try {
        await deleteSitePhoto(props.siteId)
        show(null)
        emit('change', false)
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
    } finally {
        busy.value = false
    }
}

watch(() => [props.siteId, props.hasPhoto], load, { immediate: true })

onBeforeUnmount(() => show(null))
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-if="imageUrl !== null"
            class="overflow-hidden rounded-card border border-hairline bg-surface-2"
        >
            <img
                :src="imageUrl"
                :alt="t('sitePhoto.alt', { name: siteName })"
                class="block max-h-64 w-full object-contain"
            />
        </div>

        <p v-else class="text-[15px] text-label-3">
            {{ siteId === null ? t('sitePhoto.saveFirst') : t('sitePhoto.none') }}
        </p>

        <p v-if="error" class="text-[14px] font-medium text-no">{{ error }}</p>

        <div v-if="editable && siteId !== null" class="flex flex-wrap items-center gap-2">
            <!-- A label rather than a button wrapping an input: the input has
                 to be reachable by the keyboard and it is the thing that opens
                 the camera, so it is hidden by clipping rather than removed
                 from the tree with `hidden`. -->
            <label
                class="cursor-pointer rounded-full bg-surface px-3.5 py-1.5 text-[14px] font-medium text-label-2"
                :class="busy ? 'pointer-events-none opacity-40' : ''"
            >
                {{ busy ? t('sitePhoto.working') : imageUrl === null ? t('sitePhoto.take') : t('sitePhoto.replace') }}
                <input
                    type="file"
                    accept="image/*"
                    capture="environment"
                    class="sr-only"
                    :disabled="busy"
                    @change="onPicked"
                />
            </label>

            <template v-if="imageUrl !== null">
                <button
                    v-if="!confirming"
                    type="button"
                    class="rounded-full px-3.5 py-1.5 text-[14px] font-medium text-no disabled:opacity-40"
                    :disabled="busy"
                    @click="confirming = true"
                >
                    {{ t('sitePhoto.remove') }}
                </button>

                <!-- Two steps, because this deletes the image rather than
                     hiding it and there is nothing to undo it with. -->
                <template v-else>
                    <span class="text-[14px] text-label-2">{{ t('sitePhoto.removeConfirm') }}</span>
                    <button
                        type="button"
                        class="rounded-full px-3.5 py-1.5 text-[14px] font-semibold text-no"
                        @click="onRemove"
                    >
                        {{ t('sitePhoto.removeYes') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3.5 py-1.5 text-[14px] text-label-2"
                        @click="confirming = false"
                    >
                        {{ t('action.cancel') }}
                    </button>
                </template>
            </template>
        </div>
    </div>
</template>
