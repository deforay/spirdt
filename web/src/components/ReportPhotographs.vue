<script setup lang="ts">
import type { ReportPhotograph } from '@/api/reports'
import { t } from '@/i18n'

/**
 * The visit's photographs, as the management report shows them.
 *
 * ALWAYS OPEN, unlike the assessor's review screen, which keeps them behind a
 * disclosure because a phone decoding twenty-five images at once is a phone
 * that stops responding. This is a document read across a desk and printed,
 * and a picture nobody expanded does not print.
 *
 * THE CAPTION IS SHOWN WHETHER OR NOT THERE IS ONE. A photograph with the
 * assessor's own words under it is evidence; the same picture with nothing
 * under it is a picture of a shelf, and the gap should read as a gap rather
 * than as a tidy grid.
 *
 * THE BYTES ARRIVE AS OBJECT URLS, fetched by the view with the session's
 * token — see the note beside `images` there. `photograph.url` is where the
 * file lives, not something a browser can put in an `img` tag.
 */
defineProps<{
    photographs: ReportPhotograph[]
    /** Attachment id => object URL, or null for one that did not arrive. */
    images: Map<string, string | null>
}>()
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <figure
            v-for="photo in photographs"
            :key="photo.id"
            class="overflow-hidden rounded-card border border-hairline"
        >
            <img
                v-if="images.get(photo.id)"
                :src="images.get(photo.id)!"
                :alt="photo.caption ?? t('photos.untitled')"
                class="block h-40 w-full bg-white object-cover"
            />

            <!-- Said rather than left as a grey box. A report is evidence, and
                 a picture that will not load has to read as a picture that is
                 missing rather than as one still on its way. -->
            <p
                v-else
                class="flex h-40 w-full items-center justify-center bg-surface-2 px-3 text-center text-[14px] text-label-3"
            >
                {{ images.has(photo.id) ? t('report.imageUnavailable') : '' }}
            </p>

            <figcaption class="px-2.5 py-2 text-[14px] leading-snug">
                <span v-if="photo.caption">{{ photo.caption }}</span>
                <span v-else class="text-label-3">{{ t('photos.untitled') }}</span>
            </figcaption>
        </figure>
    </div>
</template>
