<script setup lang="ts">
import { PhCamera, PhImages, PhTrash } from '@phosphor-icons/vue'
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

/**
 * Whether a camera button can do what it says on this device.
 *
 * `capture` is an instruction to the operating system to open the camera, and
 * a desktop browser is entitled to ignore it — which it does, falling back to
 * the file picker. The result on a laptop is a control reading "Take a photo"
 * that opens a browse dialog, sitting next to a second control that opens the
 * same dialog. One of them is lying and both of them are the same button.
 *
 * There is no honest feature test for it: a desktop browser reports the
 * attribute and then disregards it. So this asks the question that actually
 * separates the two cases — a coarse pointer is a finger, and a finger comes
 * attached to a device whose camera is worth opening.
 */
const handheld = window.matchMedia('(pointer: coarse)').matches

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

/**
 * Whatever was chosen, one at a time.
 *
 * The camera hands over a single shot; the library hands over as many as the
 * assessor selected, which is why this takes a list rather than a file. They
 * are resized and written in order and the count is checked on every one — a
 * selection of eight against three free slots has to stop at three and SAY so,
 * because a picture that silently did not save is worse than one refused.
 */
async function onPicked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement
    const picked = Array.from(input.files ?? [])

    // Cleared straight away, so choosing the same file again — after a failed
    // attempt, or two shots of the same thing — still fires a change event.
    input.value = ''

    if (picked.length === 0 || props.assessmentId === '') {
        return
    }

    // PINNED BEFORE THE LOOP, and this is not defensive tidying. Resizing
    // several full-resolution photographs takes seconds, and this component
    // instance is reused as the assessor moves between sections rather than
    // rebuilt — so `props.sectionCode` follows them. Read fresh on each pass,
    // the first pictures of a selection land on the section they were chosen
    // in and the rest land on whatever section the assessor walked to while
    // they were being written. Evidence filed against the wrong part of the
    // audit is worse than evidence not filed: nothing on the report says it
    // is in the wrong place. The batch belongs where it was chosen.
    const assessmentId = props.assessmentId
    const sectionCode = props.sectionCode

    error.value = ''
    busy.value = true

    let added = 0
    let full = false
    let notImages = 0
    let failures = 0

    for (const file of picked) {
        if (!file.type.startsWith('image/')) {
            notImages += 1

            continue
        }

        try {
            const stored = await savePhoto({
                assessmentId,
                sectionCode,
                caption: '',
                blob: await resizedForUpload(file),
            })

            // Full. Nothing after this one can fit either, so the rest of the
            // selection is not worth resizing.
            if (stored === null) {
                full = true

                break
            }

            added += 1
        } catch {
            failures += 1
        }
    }

    try {
        await load()
    } catch {
        failures += 1
    }

    busy.value = false

    // Said only to somebody still looking at the section it is about. If they
    // moved on while the batch was being written, a count reported against the
    // section now on screen describes photographs that are not in it.
    if (assessmentId !== props.assessmentId || sectionCode !== props.sectionCode) {
        return
    }

    // One message, and the one that explains the largest gap between what was
    // chosen and what is now on screen.
    if (full) {
        error.value =
            added === 0
                ? t('photos.full', { count: PHOTOS_PER_SECTION })
                : t('photos.someFull', { added, count: PHOTOS_PER_SECTION })
    } else if (failures > 0) {
        error.value = t('photos.failed')
    } else if (notImages > 0) {
        error.value = t('photos.notAnImage')
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

        <!--
            TWO WAYS IN ON A PHONE, because one control cannot do both.
            `capture` is what sends the first straight to the rear camera in a
            single tap — the thing an assessor does standing in the room — and
            it also takes the library out of the sheet and refuses a selection
            of more than one. So the second drops `capture` to get both back:
            pictures taken earlier, and several of them at once.

            ONE WAY IN ON A LAPTOP, because there `capture` is ignored and both
            controls would open the same browse dialog, one of them under a
            label promising a camera.

            Labels wrapping their inputs rather than buttons beside them: the
            input is what opens the camera, and it stays reachable by the
            keyboard instead of being hidden from the tree.
        -->
        <div v-if="rows.length < PHOTOS_PER_SECTION" class="mt-3 flex flex-wrap gap-2">
            <label
                v-if="handheld"
                class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-surface-2 px-4 text-[14.5px] font-medium text-label-2 transition-colors hover:text-label"
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

            <label
                class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-surface-2 px-4 text-[14.5px] font-medium text-label-2 transition-colors hover:text-label"
                :class="busy ? 'pointer-events-none opacity-40' : ''"
            >
                <PhImages :size="17" aria-hidden="true" />
                <template v-if="handheld">{{ t('photos.addFromLibrary') }}</template>
                <template v-else>{{ busy ? t('photos.working') : t('photos.addAny') }}</template>
                <input
                    type="file"
                    accept="image/*"
                    multiple
                    class="sr-only"
                    :disabled="busy"
                    @change="onPicked"
                />
            </label>
        </div>
    </section>
</template>
