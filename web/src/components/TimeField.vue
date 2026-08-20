<script setup lang="ts">
import { Time } from '@internationalized/date'
import { PhClock } from '@phosphor-icons/vue'
import {
    PopoverAnchor,
    PopoverContent,
    PopoverRoot,
    PopoverTrigger,
    TimeFieldInput,
    TimeFieldRoot,
} from 'reka-ui'
import { computed, ref, watch } from 'vue'

import { formattingLocale, t } from '@/i18n'

/**
 * A time, asked for without handing the question to the browser.
 *
 * Same reasoning as [DateField]: `input type="time"` is a different control on
 * every engine, takes its 12- or 24-hour cycle from the operating system
 * rather than from the app, and opens its list from a glyph at the far end of
 * the box. Here the mismatch is worse than cosmetic — this app records a
 * clinical time on a form used in four languages, and half the assessors would
 * be asked for a time in a cycle their own record is not written in.
 *
 * Twenty-four hours, everywhere. It is what the instrument is printed in and
 * what every locale the app speaks other than English uses anyway, and a time
 * with no am/pm on it cannot be written down twelve hours wrong.
 *
 * The columns exist because this field has exactly one common answer — now —
 * and one common correction, which is a few minutes either side of it. Typing
 * is still there for anything else; the segments take digits and arrow keys.
 *
 * The value crossing the boundary stays an `HH:MM` string in both directions.
 */

const props = defineProps<{
    id?: string
    /** Names the group of segments; `for` cannot reach one. */
    labelledBy?: string
    /** `HH:MM` in 24-hour form, or empty for unanswered. */
    modelValue: string
    invalid?: boolean
    describedBy?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

function parse(text: string): Time | undefined {
    const match = /^(\d{2}):(\d{2})/.exec(text)

    if (match === null) {
        return undefined
    }

    const hour = Number(match[1])
    const minute = Number(match[2])

    if (hour > 23 || minute > 59) {
        return undefined
    }

    return new Time(hour, minute)
}

function format(value: Time | undefined): string {
    if (value === undefined) {
        return ''
    }

    return `${String(value.hour).padStart(2, '0')}:${String(value.minute).padStart(2, '0')}`
}

const value = computed(() => parse(props.modelValue))

const open = ref(false)

function choose(next: Time | undefined) {
    emit('update:modelValue', format(next))
}

const HOURS = Array.from({ length: 24 }, (_, hour) => hour)

/** Five-minute steps in the list; the segments take the odd minute. */
const MINUTES = Array.from({ length: 12 }, (_, index) => index * 5)

function setHour(hour: number) {
    choose(new Time(hour, value.value?.minute ?? 0))
}

function setMinute(minute: number) {
    choose(new Time(value.value?.hour ?? 0, minute))
}

function setNow() {
    const clock = new Date()

    choose(new Time(clock.getHours(), clock.getMinutes()))
    open.value = false
}

/**
 * Opening the list puts the current answer in view.
 *
 * A column of twenty-four hours scrolled to the top means the afternoon is
 * below the fold, and the assessor scrolls past their own answer to find it.
 */
const columns = ref<HTMLElement | null>(null)

watch(open, (isOpen) => {
    if (!isOpen) {
        return
    }

    requestAnimationFrame(() => {
        for (const chosen of columns.value?.querySelectorAll('[data-chosen="true"]') ?? []) {
            chosen.scrollIntoView({ block: 'center' })
        }
    })
})

const cell =
    'flex min-h-11 w-full items-center justify-center rounded-card text-[15px] tnum transition-colors hover:bg-accent-soft'
</script>

<template>
    <PopoverRoot v-model:open="open">
        <!-- Anchored to the field, not to the icon inside it: see DateField. -->
        <PopoverAnchor>
            <TimeFieldRoot
                :model-value="value"
                :locale="formattingLocale"
                :hour-cycle="24"
                granularity="minute"
                v-slot="{ segments }"
                :aria-labelledby="labelledBy"
                :class="[
                    'field tnum flex items-center py-0 pr-1',
                    'focus-within:border-accent focus-within:shadow-[0_0_0_3px_color-mix(in_srgb,var(--c-accent)_14%,transparent)]',
                    invalid === true ? 'border-no' : '',
                ]"
                @update:model-value="choose($event as Time | undefined)"
            >
                <div class="flex flex-1 items-center">
                    <template v-for="item in segments" :key="item.part">
                        <TimeFieldInput
                            v-if="item.part === 'literal'"
                            :part="item.part"
                            class="px-0.5 text-label-3"
                        >
                            {{ item.value }}
                        </TimeFieldInput>
                        <TimeFieldInput
                            v-else
                            :id="item.part === 'hour' ? id : undefined"
                            :part="item.part"
                            :aria-invalid="invalid === true ? 'true' : undefined"
                            :aria-describedby="describedBy"
                            class="rounded-[6px] px-1 py-2 outline-none focus:bg-accent data-[placeholder]:text-label-3 focus:text-accent-ink focus:data-[placeholder]:text-accent-ink"
                        >
                            {{ item.value }}
                        </TimeFieldInput>
                    </template>
                </div>

                <PopoverTrigger
                    :aria-label="t('time.open')"
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-card text-label-2 transition-colors hover:bg-accent-soft hover:text-accent"
                >
                    <PhClock :size="20" aria-hidden="true" />
                </PopoverTrigger>
            </TimeFieldRoot>
        </PopoverAnchor>

        <PopoverContent
            :side-offset="6"
            align="start"
            class="z-50 w-[13rem] rounded-card border border-hairline bg-surface p-2 shadow-surface"
        >
            <div ref="columns" class="flex gap-2">
                <div class="flex-1">
                    <p class="pb-1 text-center text-[12px] font-semibold text-label-3">
                        {{ t('time.hour') }}
                    </p>
                    <div class="scroll-thin max-h-56 overflow-y-auto">
                        <button
                            v-for="hour in HOURS"
                            :key="hour"
                            type="button"
                            :data-chosen="value?.hour === hour"
                            :class="[
                                cell,
                                value?.hour === hour
                                    ? 'bg-accent font-semibold text-accent-ink hover:bg-accent'
                                    : '',
                            ]"
                            @click="setHour(hour)"
                        >
                            {{ String(hour).padStart(2, '0') }}
                        </button>
                    </div>
                </div>

                <div class="flex-1">
                    <p class="pb-1 text-center text-[12px] font-semibold text-label-3">
                        {{ t('time.minute') }}
                    </p>
                    <div class="scroll-thin max-h-56 overflow-y-auto">
                        <button
                            v-for="minute in MINUTES"
                            :key="minute"
                            type="button"
                            :data-chosen="value?.minute === minute"
                            :class="[
                                cell,
                                value?.minute === minute
                                    ? 'bg-accent font-semibold text-accent-ink hover:bg-accent'
                                    : '',
                            ]"
                            @click="setMinute(minute)"
                        >
                            {{ String(minute).padStart(2, '0') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-2 flex items-center justify-between gap-2 border-t border-hairline pt-2">
                <button
                    type="button"
                    class="min-h-10 rounded-card px-3 text-[14px] font-semibold text-accent transition-colors hover:bg-accent-soft"
                    @click="setNow"
                >
                    {{ t('time.now') }}
                </button>
                <button
                    type="button"
                    class="min-h-10 rounded-card px-3 text-[14px] font-medium text-label-2 transition-colors hover:bg-track"
                    @click="choose(undefined)"
                >
                    {{ t('date.clear') }}
                </button>
            </div>
        </PopoverContent>
    </PopoverRoot>
</template>
