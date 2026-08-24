<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'

import { deleteSitePhoto, fetchSitePhoto, uploadSitePhoto } from '@/api/registry'
import { resizedForUpload } from '@/media/image'
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
 * RESIZED BEFORE IT IS SENT — see media/image, which does the same job for the
 * photographs taken during an audit.
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
        const image = await resizedForUpload(file)
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
