<script setup lang="ts">
import { PhCheck, PhMinus, PhProhibit, PhX } from '@phosphor-icons/vue'
import type { Component } from 'vue'
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui'

import { t } from '@/i18n'
import type { ResponseCode } from '@/scoring/types'

/**
 * The response control. One of these per question, and the assessor taps it
 * fifty-nine times a visit, so it is the single component most worth getting
 * right.
 *
 * Built on Reka's RadioGroup rather than a row of buttons. A set of mutually
 * exclusive choices is a radio group, and the free arrow-key navigation and
 * correct announcement are exactly what we said we would not hand-roll.
 *
 * Tapping the selected option clears it. An assessor who taps the wrong row
 * needs a way back to unanswered, and there is no other affordance for it.
 */

const model = defineModel<ResponseCode | null>({ required: true })

const props = withDefaults(
    defineProps<{
        /** Whether this question permits Not applicable. Comes from the template. */
        naAllowed?: boolean
        /** Names the control for screen readers, e.g. "Response to question 3.4". */
        label: string
        disabled?: boolean
    }>(),
    { naAllowed: false, disabled: false },
)

/**
 * Worded here rather than taken from the template's response labels. Four
 * options share the width of a phone, so what this needs is the shortest form
 * a language has — "N/A", not "Not Applicable" — and each language gets to
 * pick its own abbreviation. The template's labels are the long form, for
 * anywhere with room to print them.
 */
const LABELS: Record<ResponseCode, 'response.Y' | 'response.P' | 'response.N' | 'response.NA'> = {
    Y: 'response.Y',
    P: 'response.P',
    N: 'response.N',
    NA: 'response.NA',
}

/**
 * A mark beside the word, not instead of it.
 *
 * The four responses already differ by colour, and colour alone is not a
 * distinction — roughly one man in twelve cannot rely on the green/amber
 * difference that carries most of the meaning here. A shape carries it for
 * everyone.
 *
 * They stay small and they never appear alone. The word is what the assessor
 * reads; the icon is what they recognise on the second pass through a section,
 * when they are scanning rather than reading.
 */
const ICONS: Record<ResponseCode, Component> = {
    Y: PhCheck,
    P: PhMinus,
    N: PhX,
    // A circle-slash rather than a dash. Partial already owns the dash, and
    // the two are adjacent in the control.
    NA: PhProhibit,
}

const TONES: Record<ResponseCode, string> = {
    Y: 'data-[state=checked]:bg-yes-soft data-[state=checked]:text-yes',
    P: 'data-[state=checked]:bg-partial-soft data-[state=checked]:text-partial',
    N: 'data-[state=checked]:bg-no-soft data-[state=checked]:text-no',
    NA: 'data-[state=checked]:bg-na-soft data-[state=checked]:text-na',
}

const options = (): ResponseCode[] => (props.naAllowed ? ['Y', 'P', 'N', 'NA'] : ['Y', 'P', 'N'])

function choose(value: ResponseCode): void {
    model.value = model.value === value ? null : value
}
</script>

<template>
    <RadioGroupRoot
        v-model="model"
        :aria-label="label"
        :disabled="disabled"
        orientation="horizontal"
        class="grid auto-cols-fr grid-flow-col gap-0.5 rounded-[9px] bg-track p-0.5"
    >
        <RadioGroupItem
            v-for="value in options()"
            :key="value"
            :value="value"
            :class="[
                'flex cursor-pointer items-center justify-center gap-1 rounded-[7px]',
                'px-1 py-1.5 text-[13px] font-medium text-label-2',
                'transition-colors duration-150 hover:text-label',
                'data-[state=checked]:font-semibold data-[state=checked]:shadow-sm',
                'disabled:cursor-not-allowed disabled:opacity-50',
                TONES[value],
            ]"
            @click="choose(value)"
        >
            <!-- Decorative: the label beside it already says this, and an
                 announced "check Yes" is worse than "Yes". -->
            <component
                :is="ICONS[value]"
                :size="14"
                weight="bold"
                aria-hidden="true"
                class="shrink-0"
            />
            {{ t(LABELS[value]) }}
        </RadioGroupItem>
    </RadioGroupRoot>
</template>
