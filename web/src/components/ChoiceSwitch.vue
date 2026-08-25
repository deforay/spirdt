<script setup lang="ts">
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui'

import { text } from '@/i18n'
import type { ContextOption } from '@/scoring/types'

/**
 * A choice with two answers, as a switch.
 *
 * Two outlined boxes side by side say "here are two things"; a chip in a
 * groove says "one of these, and it is this one". The second is what a yes/no
 * question is, and it is the shape the response control already uses on a
 * phone — one track, and the answer raised off it — so this is the same
 * control the assessor meets fifty-nine times a visit, in a form that has room
 * for the words in full.
 *
 * Sized rather than stretched. The field beside it is a well the width of the
 * column because a name can be long; a switch that wide is a pair of enormous
 * halves with two short words lost in the middle of them.
 *
 * Built on Reka's RadioGroup for the reason the response control is: a set of
 * mutually exclusive answers is a radio group, and the arrow-key navigation
 * and the announcement come with it. Tapping the answer again clears it, which
 * is the only way back to unanswered.
 */

defineProps<{
    /**
     * Names the group for screen readers.
     *
     * A group is not labelable, so the question's `for` cannot reach it — and
     * must not: pointed at one of the answers instead, tapping the question
     * would answer it, and on the one question here that decides whether
     * Section 5 applies that is a silent change to the score.
     */
    labelledBy?: string
    /** The chosen option's key, or empty for unanswered. */
    modelValue: string
    options: ContextOption[]
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

function choose(current: string, key: string) {
    emit('update:modelValue', current === key ? '' : key)
}
</script>

<template>
    <RadioGroupRoot
        :model-value="modelValue"
        :aria-labelledby="labelledBy"
        orientation="horizontal"
        class="grid w-full max-w-[320px] auto-cols-fr grid-flow-col gap-1 rounded-[12px] bg-track p-1"
    >
        <RadioGroupItem
            v-for="option in options"
            :key="option.key"
            :value="option.key"
            class="flex min-h-11 cursor-pointer items-center justify-center rounded-[8px] px-3 text-[15px] font-semibold text-label-2 transition-[background-color,color,box-shadow] duration-150 hover:text-label data-[state=checked]:bg-surface data-[state=checked]:text-accent data-[state=checked]:shadow-pick"
            @click="choose(modelValue, option.key)"
        >
            {{ text(option.label) }}
        </RadioGroupItem>
    </RadioGroupRoot>
</template>
