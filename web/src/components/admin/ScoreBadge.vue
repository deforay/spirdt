<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@/i18n'

/**
 * The band, as a colour and a word.
 *
 * Colour alone would not do: these reports are printed, photocopied and read
 * by people who do not see red and green apart, and the difference between
 * Level 1 and Level 3 is the whole point of the instrument. So the level is
 * always written out, and the colour only agrees with it.
 *
 * The ramp is sequential navy, not red to green. Two reasons, and the second
 * is the one that bites. A level is a position on a scale, and a scale is
 * drawn as one hue deepening — red to green is the encoding for opposition,
 * which is not what Level 1 and Level 3 are to each other. And red, amber and
 * green already mean No, Partial and Yes on every question in the instrument;
 * spending them here left a Level 0 site wearing the same red as a failed
 * answer, on a badge that is not an answer to anything.
 */

const props = defineProps<{ level: number | null }>()

const TONES: Record<number, string> = {
    0: 'bg-level-0 text-level-0-ink',
    1: 'bg-level-1 text-level-1-ink',
    2: 'bg-level-2 text-level-2-ink',
    3: 'bg-level-3 text-level-3-ink',
    4: 'bg-level-4 text-level-4-ink',
}

const tone = computed(() =>
    props.level === null ? 'bg-track text-label-2' : (TONES[props.level] ?? 'bg-track text-label-2'),
)
</script>

<template>
    <span
        v-if="props.level !== null"
        class="tnum inline-block rounded-full px-2.5 py-0.5 text-[12px] font-semibold tracking-tight"
        :class="tone"
    >
        {{ t('score.level', { level: props.level }) }}
    </span>
</template>
