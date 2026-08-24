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
 * Two controls, one component, because a phone and a desk are not the same
 * instrument.
 *
 * ON A PHONE it is a switch: one track, and the answer is a raised chip that
 * moves along it. The choice is a thumb travelling in a groove — the shape a
 * phone has used for a set of exclusive options for a decade — and it beats
 * three outlined boxes on the two things that matter here. It says at a glance
 * that exactly one of these can be true, which three identical boxes do not;
 * and the chosen chip is lifted off the track rather than merely tinted, so
 * the answer survives daylight on a bench, a screen protector, and a thumb
 * hovering over it.
 *
 * AT A DESK it stays three separate buttons with air between them. The pointer
 * has no trouble with a small target and the desk screen shows a dozen rows at
 * once, where a row of grey wells reads as furniture and the answers get lost
 * in it.
 *
 * Both carry the colour in the same three places, so the two screens are the
 * same control in different clothes: the outline, the mark, and the word.
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
 * The word has to clear 4.5:1 on whatever is behind it, and at that ratio a
 * green is already halfway to bottle green — there is no vivid green that
 * passes as text on white. The border and the mark carry no text, answer to
 * 3:1, and can therefore be the actual red, yellow and green somebody expects
 * to see. So the outline and the tick are vivid, the label is the darker tone,
 * and the option still reads as green from across a bench.
 *
 * The chosen chip is white on the phone and tinted at the desk. On the phone
 * it has a grey track behind it doing the work of separating it from its
 * neighbours, and white is what makes it look lifted; at the desk there is no
 * track, so the tint is the only thing distinguishing a chosen box from an
 * empty one.
 */
const TONES: Record<ResponseCode, string> = {
    Y: 'data-[state=checked]:border-yes-vivid data-[state=checked]:text-yes md:data-[state=checked]:bg-yes-soft',
    P: 'data-[state=checked]:border-partial-vivid data-[state=checked]:text-partial md:data-[state=checked]:bg-partial-soft',
    N: 'data-[state=checked]:border-no-vivid data-[state=checked]:text-no md:data-[state=checked]:bg-no-soft',
    NA: 'data-[state=checked]:border-na data-[state=checked]:text-na md:data-[state=checked]:bg-na-soft',
}

/**
 * The mark takes its colour only once the option is chosen.
 *
 * It used to carry the response colour whether or not it was selected, and on
 * an unanswered question that is three vivid marks — a green tick, an amber
 * half-circle, a red cross — sitting in a row of pale outlines. Colour is the
 * strongest signal on the screen, and a screen that spends it on options
 * nobody has picked is a screen that reads as already answered. Assessors were
 * scrolling past unanswered questions because the control looked live.
 *
 * The SHAPE stays regardless, which is the part that has to: the icons exist
 * because roughly one man in twelve cannot rely on the green/amber difference,
 * and a tick is still a tick in grey. What changes is that colour now means
 * "this is the answer" and nothing else — so an answered question is visible
 * from across the section, and an unanswered one plainly is not.
 */
const MARKS: Record<ResponseCode, string> = {
    Y: 'text-label-3 group-data-[state=checked]:text-yes-vivid',
    P: 'text-label-3 group-data-[state=checked]:text-partial-vivid',
    N: 'text-label-3 group-data-[state=checked]:text-no-vivid',
    NA: 'text-label-3 group-data-[state=checked]:text-na',
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
        class="grid auto-cols-fr grid-flow-col gap-1 rounded-[12px] bg-track p-1 md:gap-2.5 md:rounded-none md:bg-transparent md:p-0"
    >
        <RadioGroupItem
            v-for="value in options()"
            :key="value"
            :value="value"
            :class="[
                'group flex min-h-12 cursor-pointer items-center justify-center gap-1.5 px-1',
                'rounded-[8px] border-2 border-transparent text-[15px] font-semibold text-label-2',
                'max-md:data-[state=checked]:bg-surface max-md:data-[state=checked]:shadow-pick',
                'md:min-h-12 md:gap-2 md:rounded-card md:border-hairline md:px-2',
                'transition-[background-color,color,border-color,box-shadow] duration-150',
                'hover:text-label md:hover:border-label-3/40',
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
