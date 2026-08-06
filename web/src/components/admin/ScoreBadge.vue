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
 */

const props = defineProps<{ level: number | null }>()

const tone = computed(() => {
    switch (props.level) {
        case 0:
        case 1:
            return 'bg-no/10 text-no'
        case 2:
            return 'bg-partial/10 text-partial'
        case 3:
        case 4:
            return 'bg-yes/10 text-yes'
        default:
            return 'bg-fill text-label-2'
    }
})
</script>

<template>
    <span
        v-if="props.level !== null"
        class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold"
        :class="tone"
    >
        {{ t('score.level', { level: props.level }) }}
    </span>
</template>
