<script setup lang="ts">
import { CalendarDate, type DateValue, getLocalTimeZone, today } from '@internationalized/date'
import {
    PhCalendarBlank,
    PhCaretDoubleLeft,
    PhCaretDoubleRight,
    PhCaretLeft,
    PhCaretRight,
} from '@phosphor-icons/vue'
import {
    DatePickerAnchor,
    DatePickerCalendar,
    DatePickerCell,
    DatePickerCellTrigger,
    DatePickerContent,
    DatePickerField,
    DatePickerGrid,
    DatePickerGridBody,
    DatePickerGridHead,
    DatePickerGridRow,
    DatePickerHeadCell,
    DatePickerHeading,
    DatePickerInput,
    DatePickerRoot,
    DatePickerTrigger,
} from 'reka-ui'
import { computed, shallowRef, watch } from 'vue'

import { formattingLocale, t } from '@/i18n'

/**
 * A date, asked for without handing the question to the browser.
 *
 * `input type="date"` looked like the honest choice and is not one. It is a
 * different control on every engine and platform the app runs on; it takes its
 * order of parts from the operating system rather than from the language the
 * assessor chose in this app, so a tablet set up in English asks a French
 * assessor for mm/dd/yyyy; and on a desktop the only way into the calendar is
 * a glyph a few millimetres wide at the far end of the box. What it cannot do
 * at all is the thing this form most needs: say that a date is out of bounds
 * before it is entered. The instrument declares `not_future` on the date of
 * assessment, and until now that was discovered after typing, in red, below.
 *
 * So the field is ours: segments that follow the app's language, a calendar
 * that greys out what the instrument does not allow, and a year step, because
 * the previous assessment is usually a year ago and twelve taps on a month
 * arrow is not a way to travel there.
 *
 * The value crossing the boundary stays an ISO `YYYY-MM-DD` string in both
 * directions. Everything upstream — the draft, the validator, the sync queue,
 * the server — trades in that string, and a component is not the place to
 * introduce a second representation of a date into an offline app.
 */

const props = defineProps<{
    id?: string
    /** Names the group of segments; `for` cannot reach one. */
    labelledBy?: string
    /** ISO `YYYY-MM-DD`, or empty for unanswered. */
    modelValue: string
    /** From the template's constraints: today is the latest date allowed. */
    notFuture?: boolean
    invalid?: boolean
    describedBy?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

function parse(iso: string): DateValue | undefined {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)

    if (match === null) {
        return undefined
    }

    // Built rather than parsed, so an impossible date — the 31st of February,
    // which the pattern above is happy with — throws here and is treated as no
    // answer instead of silently sliding into March.
    try {
        return new CalendarDate(Number(match[1]), Number(match[2]), Number(match[3]))
    } catch {
        return undefined
    }
}

function format(value: DateValue | undefined): string {
    if (value === undefined) {
        return ''
    }

    return `${String(value.year).padStart(4, '0')}-${String(value.month).padStart(2, '0')}-${String(value.day).padStart(2, '0')}`
}

const value = computed(() => parse(props.modelValue))

/** Today where the device is, which is the only "today" an assessor means. */
const now = computed(() => today(getLocalTimeZone()))

const maximum = computed(() => (props.notFuture === true ? now.value : undefined))

/**
 * Which month the calendar opens on.
 *
 * Held here rather than left to Reka so the year arrows have something to move.
 * An empty field opens on this month; a filled one opens on the answer, and
 * follows it when the segments are typed into.
 */
// Shallow: these are immutable value objects, and a deep ref would
// rewrite the class instance into a plain object on its way through.
const month = shallowRef<DateValue>(value.value ?? now.value)

watch(value, (next) => {
    if (next !== undefined) {
        month.value = next
    }
})

function choose(next: DateValue | undefined) {
    emit('update:modelValue', format(next))
}

/** Disabled rather than hidden: the day is there, and it is not allowed. */
function isDisabled(date: DateValue): boolean {
    return maximum.value !== undefined && date.compare(maximum.value) > 0
}

const stepIcon =
    'size-9 shrink-0 rounded-card text-label-2 transition-colors hover:bg-accent-soft hover:text-accent disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-label-2 inline-flex items-center justify-center'
</script>

<template>
    <DatePickerRoot
        :model-value="value"
        v-model:placeholder="month"
        :locale="formattingLocale"
        :max-value="maximum"
        :is-date-disabled="isDisabled"
        granularity="day"
        @update:model-value="choose"
    >
        <!-- The box is the same `.field` every other answer on this screen
             sits in, and it lights on focus-within because the thing being
             focused is a segment inside it, not the box. -->
        <!-- Anchored to the field rather than to the icon inside it. The
             popover is as wide as the field and belongs under its left edge;
             hung off the trigger it drops from the far right and reads as
             belonging to whatever is in the next column. -->
        <DatePickerAnchor>
            <DatePickerField
                v-slot="{ segments }"
                :aria-labelledby="labelledBy"
                :class="[
                    'field tnum flex items-center py-0 pr-1',
                    'focus-within:border-accent focus-within:shadow-[0_0_0_3px_color-mix(in_srgb,var(--c-accent)_14%,transparent)]',
                    invalid === true ? 'border-no' : '',
                ]"
            >
                <div class="flex flex-1 items-center">
                    <template v-for="item in segments" :key="item.part">
                        <DatePickerInput
                            v-if="item.part === 'literal'"
                            :part="item.part"
                            class="px-0.5 text-label-3"
                        >
                            {{ item.value }}
                        </DatePickerInput>
                        <DatePickerInput
                            v-else
                            :id="item.part === 'day' ? id : undefined"
                            :part="item.part"
                            :aria-invalid="invalid === true ? 'true' : undefined"
                            :aria-describedby="describedBy"
                            class="rounded-[6px] px-1 py-2 outline-none focus:bg-accent data-[placeholder]:text-label-3 focus:text-accent-ink focus:data-[placeholder]:text-accent-ink"
                        >
                            {{ item.value }}
                        </DatePickerInput>
                    </template>
                </div>

                <DatePickerTrigger :aria-label="t('date.open')" :class="stepIcon">
                    <PhCalendarBlank :size="20" aria-hidden="true" />
                </DatePickerTrigger>
            </DatePickerField>
        </DatePickerAnchor>

        <DatePickerContent
            :side-offset="6"
            align="start"
            class="z-50 rounded-card border border-hairline bg-surface p-3 shadow-surface"
        >
            <DatePickerCalendar v-slot="{ weekDays, grid }">
                <!-- Year on the outside, month on the inside, in the order the
                     eye reads them. The previous assessment is a year old more
                     often than it is a month old. -->
                <div class="mb-2 flex items-center gap-1">
                    <button
                        type="button"
                        :aria-label="t('date.previousYear')"
                        :class="stepIcon"
                        @click="month = month.subtract({ years: 1 })"
                    >
                        <PhCaretDoubleLeft :size="16" weight="bold" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        :aria-label="t('date.previousMonth')"
                        :class="stepIcon"
                        @click="month = month.subtract({ months: 1 })"
                    >
                        <PhCaretLeft :size="16" weight="bold" aria-hidden="true" />
                    </button>

                    <DatePickerHeading class="flex-1 text-center text-[15px] font-semibold" />

                    <button
                        type="button"
                        :aria-label="t('date.nextMonth')"
                        :class="stepIcon"
                        @click="month = month.add({ months: 1 })"
                    >
                        <PhCaretRight :size="16" weight="bold" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        :aria-label="t('date.nextYear')"
                        :class="stepIcon"
                        @click="month = month.add({ years: 1 })"
                    >
                        <PhCaretDoubleRight :size="16" weight="bold" aria-hidden="true" />
                    </button>
                </div>

                <DatePickerGrid
                    v-for="page in grid"
                    :key="page.value.toString()"
                    class="w-full border-collapse select-none"
                >
                    <DatePickerGridHead>
                        <DatePickerGridRow class="flex">
                            <DatePickerHeadCell
                                v-for="day in weekDays"
                                :key="day"
                                class="w-10 text-[12px] font-semibold text-label-3"
                            >
                                {{ day }}
                            </DatePickerHeadCell>
                        </DatePickerGridRow>
                    </DatePickerGridHead>

                    <DatePickerGridBody>
                        <DatePickerGridRow
                            v-for="(week, index) in page.rows"
                            :key="`week-${index}`"
                            class="flex w-full"
                        >
                            <DatePickerCell
                                v-for="date in week"
                                :key="date.toString()"
                                :date="date"
                            >
                                <!--
                                    Today is outlined, the answer is filled. Two
                                    different facts about a day and they can both
                                    be true of the same one, so they cannot share
                                    a treatment.
                                -->
                                <DatePickerCellTrigger
                                    :day="date"
                                    :month="page.value"
                                    class="flex size-10 items-center justify-center rounded-card text-[15px] tnum transition-colors hover:bg-accent-soft data-[outside-view]:text-label-3 data-[today]:font-semibold data-[today]:ring-1 data-[today]:ring-inset data-[today]:ring-accent data-[selected]:bg-accent data-[selected]:font-semibold data-[selected]:text-accent-ink data-[disabled]:opacity-30 data-[disabled]:hover:bg-transparent"
                                />
                            </DatePickerCell>
                        </DatePickerGridRow>
                    </DatePickerGridBody>
                </DatePickerGrid>
            </DatePickerCalendar>

            <!-- Nearly every date on this form is today, and clearing one is
                 otherwise impossible without deleting three segments by hand. -->
            <div class="mt-2 flex items-center justify-between gap-2 border-t border-hairline pt-2">
                <button
                    type="button"
                    class="min-h-10 rounded-card px-3 text-[14px] font-semibold text-accent transition-colors hover:bg-accent-soft"
                    @click="choose(now)"
                >
                    {{ t('date.today') }}
                </button>
                <button
                    type="button"
                    class="min-h-10 rounded-card px-3 text-[14px] font-medium text-label-2 transition-colors hover:bg-track"
                    @click="choose(undefined)"
                >
                    {{ t('date.clear') }}
                </button>
            </div>
        </DatePickerContent>
    </DatePickerRoot>
</template>
