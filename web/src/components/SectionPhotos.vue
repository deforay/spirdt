<script setup lang="ts">
import { PhCamera, PhTrash } from '@phosphor-icons/vue'
import { onBeforeUnmount, ref, watch } from 'vue'

import {
    deletePhoto,
    photosForSection,
    PHOTOS_PER_SECTION,
    savePhoto,
    setPhotoCaption,
} from '@/db/attachments'
import type { StoredAttachment } from '@/db/database'
import { t } from '@/i18n'
import { resizedForUpload } from '@/media/image'

/**
 * Photographs of what the assessor is standing in front of.
 *
 * AT THE END OF THE SECTION, beside the corrective actions, because that is
 * the same moment: the assessor works a section, sees what is missing, and
 * agrees with the site what will be done about it. The empty shelf, the fridge
 * with no temperature log, the wall with no organogram. Anywhere else on the
 * screen asks somebody to remember to come back, which at the end of a long
 * day means the pictures do not get taken.
 *
 * EACH ONE CARRIES ITS OWN WORDS. A photograph nobody captioned is a picture
 * of a shelf, and a year later — in front of a report, arguing about what was
 * found — nobody can say which shelf or why it was worth photographing. The
 * caption is not a finding: a finding says what will be DONE, this says what
 * is THERE.
 *
 * WRITTEN TO THE DEVICE THE MOMENT IT IS TAKEN, and uploaded whenever there is
 * a connection. An assessor works in a laboratory with no signal; nothing here
 * waits on the network, and a picture that will not upload cannot hold up the
 * visit it belongs to.
 */

const props = defineProps<{
    assessmentId: string
    /** A section code, or 'site' for the setup screen. */
    sectionCode: string
}>()

const rows = ref<StoredAttachment[]>([])
const busy = ref(false)
const error = ref('')

/**
 * One object URL per image, held until the row goes.
 *
 * They are references the document keeps alive, so a screen that minted one
 * per render would leak an image's worth of memory per keystroke in a caption
 * field. Keyed by the row, revoked when it leaves.
 */
const previews = ref(new Map<string, string>())

function refreshPreviews(next: StoredAttachment[]): void {
    const keep = new Set(next.map((row) => row.key))

    for (const [key, url] of previews.value) {
        if (!keep.has(key)) {
            URL.revokeObjectURL(url)
            previews.value.delete(key)
        }
    }

    for (const row of next) {
        if (!previews.value.has(row.key)) {
            previews.value.set(row.key, URL.createObjectURL(row.blob))
        }
    }
}

async function load(): Promise<void> {
    if (props.assessmentId === '') {
        rows.value = []

        return
    }

    const found = await photosForSection(props.assessmentId, props.sectionCode)

    refreshPreviews(found)
    rows.value = found
}

async function onPicked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0] ?? null

    // Cleared straight away, so choosing the same file again — after a failed
    // attempt, or two shots of the same thing — still fires a change event.
    input.value = ''

    if (file === null || props.assessmentId === '') {
        return
    }

    if (!file.type.startsWith('image/')) {
        error.value = t('photos.notAnImage')

        return
    }

    error.value = ''
    busy.value = true

    try {
        const stored = await savePhoto({
            assessmentId: props.assessmentId,
            sectionCode: props.sectionCode,
            caption: '',
            blob: await resizedForUpload(file),
        })

        if (stored === null) {
            error.value = t('photos.full', { count: PHOTOS_PER_SECTION })
        }

        await load()
    } catch {
        error.value = t('photos.failed')
    } finally {
        busy.value = false
    }
}

/**
 * Written on the way out of the field rather than on every keystroke.
 *
 * Each save advances the revision and marks the row for upload again, and a
 * caption typed a character at a time would queue thirty uploads of the same
 * photograph.
 */
async function onCaption(row: StoredAttachment, event: Event): Promise<void> {
    const value = (event.target as HTMLInputElement).value

    if (value.trim() === (row.caption ?? '')) {
        return
    }

    await setPhotoCaption(row.key, value)
    await load()
}

async function onDelete(key: string): Promise<void> {
    await deletePhoto(key)
    await load()
}

watch(() => [props.assessmentId, props.sectionCode], load, { immediate: true })

onBeforeUnmount(() => {
    for (const url of previews.value.values()) {
        URL.revokeObjectURL(url)
    }

    previews.value.clear()
})
</script>

<template>
    <section class="rounded-surface border border-hairline bg-surface p-4 md:p-5">
        <div class="flex items-baseline justify-between gap-3 pb-3">
            <h2 class="eyebrow text-label-3">{{ t('photos.heading') }}</h2>
            <span class="tnum text-[13px] text-label-3">
                {{ t('photos.count', { taken: rows.length, total: PHOTOS_PER_SECTION }) }}
            </span>
        </div>

        <p v-if="error" class="pb-2 text-[14px] font-medium text-no">{{ error }}</p>

        <div v-if="rows.length > 0" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <figure
                v-for="row in rows"
                :key="row.key"
                class="overflow-hidden rounded-card border border-hairline bg-surface-2"
            >
                <img
                    :src="previews.get(row.key)"
                    :alt="row.caption ?? t('photos.untitled')"
                    class="block h-40 w-full bg-white object-cover"
                />

                <figcaption class="flex items-center gap-2 px-2.5 py-2">
                    <!-- The words go under the picture, where somebody reading
                         the report will find them. -->
                    <input
                        :value="row.caption ?? ''"
                        type="text"
                        maxlength="500"
                        :placeholder="t('photos.captionPlaceholder')"
                        :aria-label="t('photos.captionLabel')"
                        class="min-h-11 w-full bg-transparent text-[14.5px] outline-none placeholder:text-label-3"
                        @change="onCaption(row, $event)"
                    />
                    <button
                        type="button"
                        class="shrink-0 p-1.5 text-label-3 transition-colors hover:text-no"
                        :aria-label="t('photos.remove')"
                        @click="onDelete(row.key)"
                    >
                        <PhTrash :size="16" aria-hidden="true" />
                    </button>
                </figcaption>

                <p v-if="row.syncError" class="px-2.5 pb-2 text-[13px] font-medium text-no">
                    {{ row.syncError }}
                </p>
            </figure>
        </div>

        <p v-else class="pb-3 text-[15px] text-label-3">{{ t('photos.none') }}</p>

        <!-- A label wrapping the input rather than a button beside it: the
             input is what opens the camera, and it stays reachable by the
             keyboard instead of being hidden from the tree. -->
        <label
            v-if="rows.length < PHOTOS_PER_SECTION"
            class="mt-3 inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-surface-2 px-4 text-[14.5px] font-medium text-label-2 transition-colors hover:text-label"
            :class="busy ? 'pointer-events-none opacity-40' : ''"
        >
            <PhCamera :size="17" aria-hidden="true" />
            {{ busy ? t('photos.working') : t('photos.add') }}
            <input
                type="file"
                accept="image/*"
                capture="environment"
                class="sr-only"
                :disabled="busy"
                @change="onPicked"
            />
        </label>
    </section>
</template>
