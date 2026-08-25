<script lang="ts">
/**
 * One photograph, big enough to tell what it is of.
 *
 * A thumbnail is `object-cover` at 160px, which is right for "there are five
 * here and this one is the fridge" and useless for anything else: a picture of
 * a screen, a label, a log book or a serial number is unreadable at that size,
 * and it is cropped, so the thing that was photographed may not even be in
 * shot. Somebody captioning five pictures at the end of a section has to be
 * able to look at them.
 *
 * TWO SIZES, NOT A ZOOM CONTROL. Fitted to the screen is what answers "which
 * one is this"; actual size answers "what does that say", and on a phone those
 * are genuinely different pictures — an image is stored at up to
 * `MAX_EDGE` = 1600px and a phone shows it at about a quarter of that. Tapping
 * the image swaps between them and the frame scrolls. Anything more — pinch,
 * momentum, a zoom slider — is a photo viewer, and the device already has one.
 */
export interface LightboxPhoto {
    key: string
    /** An object URL. The bytes never travel in a payload — see the callers. */
    src: string
    caption: string | null
}
</script>

<script setup lang="ts">
import {
    PhArrowLeft,
    PhArrowRight,
    PhMagnifyingGlassMinus,
    PhMagnifyingGlassPlus,
    PhX,
} from '@phosphor-icons/vue'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

import { t } from '@/i18n'

const props = defineProps<{
    photographs: LightboxPhoto[]
    /** Which one is open. Null is closed, and closed is the resting state. */
    index: number | null
}>()

const emit = defineEmits<{ 'update:index': [number | null] }>()

const zoomed = ref(false)
const frame = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)

const current = computed(() =>
    props.index === null ? null : (props.photographs[props.index] ?? null),
)

function close(): void {
    emit('update:index', null)
}

function step(by: number): void {
    if (props.index === null || props.photographs.length < 2) {
        return
    }

    // Wraps, because five pictures is a ring rather than a list — somebody
    // comparing the first and the last should not have to walk back through
    // the middle.
    const next = (props.index + by + props.photographs.length) % props.photographs.length

    emit('update:index', next)
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close()

        return
    }

    if (event.key === 'ArrowLeft') {
        step(-1)

        return
    }

    if (event.key === 'ArrowRight') {
        step(1)

        return
    }

    if (event.key !== 'Tab') {
        return
    }

    // HELD INSIDE, because `aria-modal` is a promise to assistive technology
    // and not a rule the browser enforces. Without this, tabbing past the last
    // control walks into the page underneath — where, on the screen this opens
    // from, the very next things are the caption fields and the delete buttons
    // of the photographs it is covering. Somebody looking at a picture could
    // delete a different one without ever seeing what they were on.
    const stops = Array.from(panel.value?.querySelectorAll<HTMLElement>('button') ?? [])

    const first = stops.at(0)
    const last = stops.at(-1)

    // Nothing to hold focus on. Refusing the Tab is still better than letting
    // it out of a dialog that is covering the screen.
    if (first === undefined || last === undefined) {
        event.preventDefault()

        return
    }

    const active = document.activeElement
    const inside = panel.value?.contains(active) ?? false

    if (event.shiftKey ? active === first || !inside : active === last || !inside) {
        event.preventDefault()
        ;(event.shiftKey ? last : first).focus()
    }
}

/**
 * The page behind must not scroll, and what was focused must come back.
 *
 * Without the first, a phone scrolls the audit underneath while somebody drags
 * a zoomed photograph. Without the second, closing the viewer drops the
 * keyboard back at the top of the document, which on a section with fifty-nine
 * questions means losing your place entirely.
 */
let restoreFocusTo: HTMLElement | null = null

watch(
    () => props.index,
    (index, previous) => {
        zoomed.value = false

        if (index !== null && previous === null) {
            restoreFocusTo = document.activeElement as HTMLElement | null
            document.body.style.overflow = 'hidden'
            window.addEventListener('keydown', onKey)
            // Moved INTO the viewer, so the next Tab lands on its own controls
            // rather than walking the audit behind it.
            void nextTick(() => panel.value?.focus())
        } else if (index === null && previous !== null) {
            document.body.style.overflow = ''
            window.removeEventListener('keydown', onKey)
            restoreFocusTo?.focus()
            restoreFocusTo = null
        }
    },
)

watch(zoomed, (on) => {
    // Back to the top-left of the picture rather than wherever the last one was
    // left scrolled to, which is otherwise a blank corner of an unrelated image.
    if (on && frame.value !== null) {
        frame.value.scrollTop = 0
        frame.value.scrollLeft = 0
    }
})

onBeforeUnmount(() => {
    document.body.style.overflow = ''
    window.removeEventListener('keydown', onKey)
})
</script>

<template>
    <!--
        Teleported, unlike the confirm dialogs elsewhere, because this one opens
        from inside the checklist's scrolling pane. `position: fixed` is not
        contained by an overflow ancestor but IS contained by a transformed one,
        and the difference between those two is not something a future edit to a
        wrapper class should be able to turn into a photograph trapped in a
        160px box.
    -->
    <Teleport to="body">
        <div
            v-if="current !== null"
            ref="panel"
            class="fixed inset-0 z-50 flex flex-col bg-black/90 outline-none"
            role="dialog"
            aria-modal="true"
            tabindex="-1"
            @click.self="close"
        >
            <div class="flex shrink-0 items-center gap-3 px-3 py-2 text-white/70">
                <span v-if="photographs.length > 1" class="tnum text-[14px]">
                    {{ t('photos.position', { index: (index ?? 0) + 1, total: photographs.length }) }}
                </span>

                <span class="flex-1"></span>

                <!-- Tapping the picture does this too, but nothing on screen
                     says so, and a tap is not available to somebody on a
                     keyboard. -->
                <button
                    type="button"
                    class="rounded-full p-2 transition-colors hover:bg-white/10 hover:text-white"
                    :aria-label="zoomed ? t('photos.fitToScreen') : t('photos.actualSize')"
                    @click="zoomed = !zoomed"
                >
                    <PhMagnifyingGlassMinus v-if="zoomed" :size="20" aria-hidden="true" />
                    <PhMagnifyingGlassPlus v-else :size="20" aria-hidden="true" />
                </button>

                <button
                    v-if="photographs.length > 1"
                    type="button"
                    class="rounded-full p-2 transition-colors hover:bg-white/10 hover:text-white"
                    :aria-label="t('photos.previous')"
                    @click="step(-1)"
                >
                    <PhArrowLeft :size="20" aria-hidden="true" />
                </button>
                <button
                    v-if="photographs.length > 1"
                    type="button"
                    class="rounded-full p-2 transition-colors hover:bg-white/10 hover:text-white"
                    :aria-label="t('photos.next')"
                    @click="step(1)"
                >
                    <PhArrowRight :size="20" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    class="rounded-full p-2 transition-colors hover:bg-white/10 hover:text-white"
                    :aria-label="t('photos.close')"
                    @click="close"
                >
                    <PhX :size="20" aria-hidden="true" />
                </button>
            </div>

            <!--
                A FLEX BOX WHEN FITTED, A PLAIN SCROLLING BLOCK WHEN NOT, and
                the difference is not tidiness. Centring an overflowing item in
                a flex container — with `justify-center` or with auto margins —
                puts half the overflow off the START edge, where no amount of
                scrolling reaches it: a 1600px image in a 1265px frame loses its
                left 167px outright, measured. On a picture somebody opened
                because they could not read it, that is precisely the failure
                this control exists to prevent. As a block, `text-center` centres
                the image while it is smaller than the frame and lets it start at
                the left edge once it is larger, which scrolls all the way.
            -->
            <div
                ref="frame"
                class="min-h-0 flex-1"
                :class="
                    zoomed
                        ? 'overflow-auto text-center'
                        : 'flex items-center justify-center overflow-hidden'
                "
                @click.self="close"
            >
                <img
                    :key="current.key"
                    :src="current.src"
                    :alt="current.caption ?? t('photos.untitled')"
                    :class="
                        zoomed
                            ? 'max-w-none cursor-zoom-out'
                            : 'max-h-full max-w-full cursor-zoom-in object-contain'
                    "
                    @click="zoomed = !zoomed"
                />
            </div>

            <!-- The words the assessor wrote, under the picture they wrote them
                 about. This is often the screen where they are checking that
                 the two match. -->
            <p class="shrink-0 px-4 py-3 text-center text-[15px] leading-snug text-white/80">
                <span v-if="current.caption">{{ current.caption }}</span>
                <span v-else class="text-white/40">{{ t('photos.untitled') }}</span>
            </p>
        </div>
    </Teleport>
</template>
