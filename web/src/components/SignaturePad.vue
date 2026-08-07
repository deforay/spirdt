<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'

import { t } from '@/i18n'

/**
 * Sign with a finger.
 *
 * Strokes are kept as points rather than only painted, which buys three things
 * for very little: undo, because a slipped finger on a tablet held in one hand
 * is the normal case and Clear-and-start-again is a punishment for it; a
 * redraw at the right resolution when the device is rotated mid-signature; and
 * a reliable emptiness test that does not involve reading pixels back.
 *
 * The bitmap is transparent and the ink is dark. The white behind it is CSS on
 * the element, so it is not baked into the PNG — the mark composites onto a
 * report page or a pale panel without carrying a white rectangle with it, and
 * a dark-mode viewer still gets a white surface to sign on.
 */

defineProps<{
    /** Whose signature this is. Shown under the line, and filed with the image. */
    signedName: string
}>()

const emit = defineEmits<{ save: [blob: Blob]; cancel: [] }>()

type Point = { x: number; y: number }

const canvasRef = useTemplateRef<HTMLCanvasElement>('canvas')

/**
 * Deliberately not reactive.
 *
 * A stroke gathers a point every few milliseconds, and making Vue proxy each
 * one would put a reactivity system on the hot path of a drawing loop for no
 * gain — nothing renders from the points, `redraw()` is called by hand. Only
 * the count is reactive, which is all the buttons need.
 */
const strokes: Point[][] = []
const strokeCount = ref(0)
const drawing = ref(false)

const isEmpty = computed(() => strokeCount.value === 0)

/** CSS pixels; the bitmap behind it is this times the device pixel ratio. */
let width = 0
let height = 0

function context(): CanvasRenderingContext2D | null {
    return canvasRef.value?.getContext('2d') ?? null
}

/**
 * Match the bitmap to the element and to the screen.
 *
 * Without the ratio the mark is visibly soft on every phone made in the last
 * decade, and a signature that looks like a fax is not what anyone wants
 * against their name.
 */
function fit(): void {
    const canvas = canvasRef.value

    if (canvas === null) {
        return
    }

    const ratio = window.devicePixelRatio || 1
    const rect = canvas.getBoundingClientRect()

    width = rect.width
    height = rect.height

    canvas.width = Math.round(width * ratio)
    canvas.height = Math.round(height * ratio)

    const ctx = context()

    if (ctx !== null) {
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0)
        ctx.lineWidth = 2.2
        ctx.lineCap = 'round'
        ctx.lineJoin = 'round'
        ctx.strokeStyle = '#111827'
    }

    redraw()
}

/**
 * Repaint every stroke.
 *
 * Each is drawn as quadratic curves through the midpoints of consecutive
 * points. Joining the raw points with straight lines gives the polygonal look
 * that makes a signature read as traced rather than written.
 */
function redraw(): void {
    const ctx = context()

    if (ctx === null) {
        return
    }

    ctx.clearRect(0, 0, width, height)

    for (const stroke of strokes) {
        if (stroke.length === 0) {
            continue
        }

        if (stroke.length < 3) {
            // A tap or a flick: draw the dot rather than nothing.
            const point = stroke[0]!
            ctx.beginPath()
            ctx.arc(point.x, point.y, ctx.lineWidth / 2, 0, Math.PI * 2)
            ctx.fillStyle = ctx.strokeStyle as string
            ctx.fill()
            continue
        }

        ctx.beginPath()
        ctx.moveTo(stroke[0]!.x, stroke[0]!.y)

        for (let index = 1; index < stroke.length - 1; index += 1) {
            const current = stroke[index]!
            const next = stroke[index + 1]!

            ctx.quadraticCurveTo(
                current.x,
                current.y,
                (current.x + next.x) / 2,
                (current.y + next.y) / 2,
            )
        }

        const last = stroke[stroke.length - 1]!
        ctx.lineTo(last.x, last.y)
        ctx.stroke()
    }
}

function pointFrom(event: PointerEvent): Point {
    const rect = canvasRef.value!.getBoundingClientRect()

    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
}

function onPointerDown(event: PointerEvent): void {
    // Capture, so a finger that leaves the box and comes back keeps drawing
    // one stroke instead of three.
    canvasRef.value?.setPointerCapture(event.pointerId)
    drawing.value = true
    strokes.push([pointFrom(event)])
    strokeCount.value = strokes.length
    redraw()
}

function onPointerMove(event: PointerEvent): void {
    if (!drawing.value) {
        return
    }

    const stroke = strokes[strokes.length - 1]

    if (stroke === undefined) {
        return
    }

    // getCoalescedEvents recovers the positions the browser batched between
    // frames. On a fast stroke that is the difference between a smooth curve
    // and four straight segments.
    const events = typeof event.getCoalescedEvents === 'function' ? event.getCoalescedEvents() : []

    for (const sample of events.length > 0 ? events : [event]) {
        stroke.push(pointFrom(sample))
    }

    redraw()
}

function onPointerUp(event: PointerEvent): void {
    if (!drawing.value) {
        return
    }

    drawing.value = false
    canvasRef.value?.releasePointerCapture(event.pointerId)
}

function undo(): void {
    strokes.pop()
    strokeCount.value = strokes.length
    redraw()
}

function clear(): void {
    strokes.length = 0
    strokeCount.value = 0
    redraw()
}

function save(): void {
    const canvas = canvasRef.value

    if (canvas === null || isEmpty.value) {
        return
    }

    canvas.toBlob((blob) => {
        if (blob !== null) {
            emit('save', blob)
        }
    }, 'image/png')
}

/**
 * Watch the ELEMENT, not the window.
 *
 * The pad fills its container, and the container can change width without the
 * window doing anything — the review screen becomes two columns at 900px, and
 * a sheet or a details block can open beside it. A window listener misses all
 * of that, and the miss is not cosmetic: the backing bitmap keeps the old
 * width, so the pointer coordinates and the pixels stop agreeing and the mark
 * lands offset from the finger drawing it.
 */
let observer: ResizeObserver | null = null

onMounted(() => {
    fit()

    if (typeof ResizeObserver === 'undefined') {
        window.addEventListener('resize', fit)

        return
    }

    observer = new ResizeObserver(() => fit())

    if (canvasRef.value !== null) {
        observer.observe(canvasRef.value)
    }
})

onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
    window.removeEventListener('resize', fit)
})
</script>

<template>
    <div class="flex flex-col gap-2">
        <!-- White in both themes, and the two greys below are raw rather than
             tokens for the same reason: this is a document, not a surface. It
             is what gets filed against somebody's name, and a signature that
             changes colour with the reader's theme is not evidence of
             anything. -->
        <div class="overflow-hidden rounded-card bg-white sm:rounded-surface">
            <canvas
                ref="canvas"
                class="block h-[180px] w-full touch-none sm:h-[220px]"
                :aria-label="t('signature.canvasLabel')"
                @pointerdown.prevent="onPointerDown"
                @pointermove.prevent="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
            ></canvas>

            <!-- The line and the name sit outside the bitmap, so neither ends
                 up inside the image that gets filed. -->
            <div class="px-4 pb-3">
                <div class="border-t border-neutral-300"></div>
                <p class="pt-1 text-[13px] text-neutral-600">{{ signedName }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                class="rounded-full bg-surface px-3.5 py-1.5 text-[13px] font-medium text-label-2 disabled:opacity-40"
                :disabled="isEmpty"
                @click="undo"
            >
                {{ t('signature.undo') }}
            </button>
            <button
                type="button"
                class="rounded-full bg-surface px-3.5 py-1.5 text-[13px] font-medium text-label-2 disabled:opacity-40"
                :disabled="isEmpty"
                @click="clear"
            >
                {{ t('signature.clear') }}
            </button>

            <span class="flex-1"></span>

            <button
                type="button"
                class="rounded-full bg-surface px-3.5 py-1.5 text-[13px] font-medium text-label-2"
                @click="emit('cancel')"
            >
                {{ t('action.cancel') }}
            </button>
            <button
                type="button"
                class="rounded-full bg-accent px-3.5 py-1.5 text-[13px] font-semibold text-white disabled:opacity-40"
                :disabled="isEmpty"
                @click="save"
            >
                {{ t('signature.done') }}
            </button>
        </div>
    </div>
</template>
