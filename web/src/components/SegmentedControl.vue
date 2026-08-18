<script setup lang="ts">
import { PhCheck, PhCircleHalf, PhProhibit, PhX } from '@phosphor-icons/vue'
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
    // A half-filled circle, not a dash. A dash is the conventional mark for
    // "not applicable" in any table, which makes it the closest thing on
    // screen to the response sitting next to it — the one distinction these
    // icons exist to draw. Half-filled says partly met, and says it in
    // monochrome, which is the point: the icons are here because the
    // green/amber difference is not one roughly a twelfth of men can rely on.
    P: PhCircleHalf,
    N: PhX,
    NA: PhProhibit,
}

/**
 * The selected state, and why it is this loud.
 *
 * It used to be a soft tint and a hairline shadow, which meant the control
 * with an answer in it and the two next to it without one looked like the same
 * object at slightly different brightnesses. On a bench, in daylight, through
 * a screen protector, that difference is not there at all — and this is the
 * one control whose state the whole record depends on.
 *
 * Three separate buttons with air between them, not three slots cut into one
 * grey well. The well was the single largest object on the screen and the
 * least designed, and it is most of why the application read as an unstyled
 * form.
 *
 * A chosen option is tinted in its own colour and outlined in it, at a weight
 * heavier than the resting border. Tint alone is invisible on a bench in
 * daylight; the outline is what survives that, a projector, and a photocopy.
 * Solid fill was the other candidate and it was too loud at fifty-nine rows —
 * a finished section became a wall of colour with the questions lost inside
 * it.
 *
 * Unselected options carry the response colour in the mark alone. The word
 * stays in the label colour so the row reads as a question with three answers
 * rather than as three coloured things.
 *
 * Fourteen millimetres of target on a phone, twelve at a desk. The phone is
 * the one being tapped standing up, sometimes through a glove, and it is the
 * only screen where the target is the whole interaction; a mouse does not need
 * the extra height and spending it there would cost a question per screen.
 */
/**
 * Two strengths of each colour, and which one goes where is a contrast rule
 * rather than a preference.
 *
 * The word has to clear 4.5:1 on the tint behind it, and at that ratio a green
 * is already halfway to bottle green — there is no vivid green that passes as
 * text on white. The border and the mark carry no text, answer to 3:1, and can
 * therefore be the actual red, yellow and green somebody expects to see. So
 * the outline and the tick are vivid, the label is the darker tone, and the
 * option still reads as green from across a bench.
 */
const TONES: Record<ResponseCode, string> = {
    Y: 'data-[state=checked]:border-yes-vivid data-[state=checked]:bg-yes-soft data-[state=checked]:text-yes',
    P: 'data-[state=checked]:border-partial-vivid data-[state=checked]:bg-partial-soft data-[state=checked]:text-partial',
    N: 'data-[state=checked]:border-no-vivid data-[state=checked]:bg-no-soft data-[state=checked]:text-no',
    NA: 'data-[state=checked]:border-na data-[state=checked]:bg-na-soft data-[state=checked]:text-na',
}

/** The mark keeps its response colour whether or not the option is chosen. */
const MARKS: Record<ResponseCode, string> = {
    Y: 'text-yes-vivid',
    P: 'text-partial-vivid',
    N: 'text-no-vivid',
    NA: 'text-na',
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
        class="grid auto-cols-fr grid-flow-col gap-2 md:gap-2.5"
    >
        <RadioGroupItem
            v-for="value in options()"
            :key="value"
            :value="value"
            :class="[
                'flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-card md:min-h-12',
                'border-2 border-hairline px-2 text-[16px] font-semibold text-label-2 md:text-[15px]',
                'transition-[background-color,color,border-color] duration-150',
                'hover:border-label-3/40 hover:text-label',
                'disabled:cursor-not-allowed disabled:opacity-50',
                TONES[value],
            ]"
            @click="choose(value)"
        >
            <!-- Decorative: the label beside it already says this, and an
                 announced "check Yes" is worse than "Yes". -->
            <component
                :is="ICONS[value]"
                :size="16"
                weight="bold"
                aria-hidden="true"
                :class="['shrink-0', MARKS[value]]"
            />
            {{ t(LABELS[value]) }}
        </RadioGroupItem>
    </RadioGroupRoot>
</template>
