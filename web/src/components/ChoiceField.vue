<script setup lang="ts">
import { PhCaretUpDown, PhCheck } from '@phosphor-icons/vue'
import {
    SelectContent,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'reka-ui'

import { t, text } from '@/i18n'
import type { ContextOption } from '@/scoring/types'

/**
 * One answer out of a list, asked for as a field rather than as a wall.
 *
 * Part A's choices were bordered tiles, one per option, and on the three long
 * ones — six types of facility, five affiliations, four levels — that is
 * fifteen boxes competing with the eleven real fields around them for the same
 * screen. The tiles are the right control for a question with two answers and
 * the wrong one for a question with six: a list nobody is reading yet should
 * take the space of the answer, not the space of every answer it might have
 * had.
 *
 * So this looks like every other box on the setup screen — the same `.field`
 * well, the same height, the same focus — and opens a list when it is asked
 * to. Built on Reka's Select rather than a native one for the reason the date
 * and time fields are ours: a `<select>` is a different control on every
 * engine, it cannot be styled to sit in a recessed well, and on a tablet its
 * options arrive as an operating-system sheet in the operating system's idea
 * of the language rather than the assessor's.
 *
 * The value crossing the boundary is the option's key, exactly as the tiles
 * emitted it. Nothing downstream knows this changed.
 */

defineProps<{
    id?: string
    /** The chosen option's key, or empty for unanswered. */
    modelValue: string
    options: ContextOption[]
}>()

defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
    <SelectRoot
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', ($event as string) ?? '')"
    >
        <!-- A button wearing `.field`, so a row of answers reads as a row of
             answers whether it is typed into or chosen from. The caret is the
             only thing marking it as the one that opens. -->
        <SelectTrigger
            :id="id"
            class="field flex items-center justify-between gap-3 text-left data-[placeholder]:text-label-3 data-[state=open]:border-accent"
        >
            <SelectValue :placeholder="t('context.choose')" class="truncate" />

            <PhCaretUpDown :size="16" aria-hidden="true" class="shrink-0 text-label-3" />
        </SelectTrigger>

        <!--
            Portalled, because the setup screen scrolls inside a panel and a
            list drawn in place is a list clipped by the panel's edge.

            As wide as the field it came from, and never taller than the room
            below it — both are Reka's measurements, and on a phone in
            landscape the second is what stops a six-option list running off
            the bottom of the screen with no way to reach the last two.
        -->
        <SelectPortal>
            <SelectContent
                position="popper"
                :side-offset="6"
                align="start"
                class="z-50 max-h-[var(--reka-select-content-available-height)] w-[var(--reka-select-trigger-width)] overflow-hidden rounded-card border border-hairline bg-surface shadow-surface"
            >
                <SelectViewport class="scroll-thin max-h-[inherit] overflow-y-auto p-1.5">
                    <SelectItem
                        v-for="option in options"
                        :key="option.key"
                        :value="option.key"
                        class="flex min-h-11 cursor-pointer select-none items-center justify-between gap-3 rounded-card px-3 text-[15px] outline-none transition-colors data-[highlighted]:bg-accent-soft data-[highlighted]:text-accent data-[state=checked]:font-semibold data-[state=checked]:text-accent"
                    >
                        <SelectItemText>{{ text(option.label) }}</SelectItemText>

                        <!-- The mark, not the word: which one is the answer has
                             to survive a glance down a list of six. -->
                        <SelectItemIndicator class="shrink-0 text-accent">
                            <PhCheck :size="15" weight="bold" aria-hidden="true" />
                        </SelectItemIndicator>
                    </SelectItem>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>
</template>
