<script setup lang="ts">
import {
    PhArrowsClockwise,
    PhCloudArrowUp,
    PhCloudCheck,
    PhCloudSlash,
    PhCloudWarning,
} from '@phosphor-icons/vue'
import { computed } from 'vue'

import { t } from '@/i18n'
import { syncStatus } from '@/sync/engine'

/**
 * Whether the work has reached the server.
 *
 * A separate question from whether it was saved, and the two get confused
 * constantly. Saved means it survives this tablet being dropped; synced means
 * it survives the tablet not coming back. The footer answers the first, this
 * answers the second.
 *
 * "Blocked" is the state worth interrupting for — it will not clear on its own.
 *
 * Drawn in tokens rather than in white, because the bar it sits on is white in
 * one theme and near-black in the other, and a translucent-white pill is
 * invisible on the first.
 *
 * Sync used to borrow green for synced and amber for waiting, which spent two
 * of the three response colours on a badge that is not a response — the exact
 * confusion the reserved palette exists to prevent, sitting a thumb's width
 * from the control it would be confused with. Only blocked keeps a colour, and
 * it is the one state worth interrupting for.
 *
 * THE ACCENT IS NOT A RESPONSE COLOUR, which is why the two states that want
 * something from the assessor may wear it. Waiting and never-synced are the
 * states somebody can do something about, and they are the ones this badge can
 * be pressed in; resting synced stays grey, because a state that needs nothing
 * should not be the brightest thing on the bar.
 *
 * Each state also carries its own mark, so five states are told apart by shape
 * as well as by a word — a glance in a corridor rather than a line to read.
 *
 * The word STAYS at every width, though, and that is not the same call the door
 * beside it made. What this badge says varies, and in one state it carries a
 * count: an icon alone would drop "12 waiting" to a cloud, and the number is
 * the whole of the information. Icons accompany words here (DESIGN.md), and
 * that rule bites hardest where the word is the part that changes.
 */

const emit = defineEmits<{ retry: [] }>()

const state = computed(() => {
    const status = syncStatus.value

    if (status.blocked > 0) {
        return {
            label: t('sync.blocked'),
            icon: PhCloudWarning,
            tone: 'bg-no-soft text-no',
            clickable: true,
        }
    }

    if (status.running) {
        return {
            label: t('sync.running'),
            icon: PhArrowsClockwise,
            tone: 'bg-track text-label-2',
            clickable: false,
        }
    }

    if (status.pending > 0) {
        return {
            label: t('sync.pending', { count: status.pending }),
            icon: PhCloudArrowUp,
            tone: 'bg-accent-soft text-accent',
            clickable: true,
        }
    }

    if (status.lastRunAt === null) {
        return {
            label: t('sync.never'),
            icon: PhCloudSlash,
            tone: 'bg-accent-soft text-accent',
            clickable: true,
        }
    }

    return {
        label: t('sync.synced'),
        icon: PhCloudCheck,
        tone: 'bg-track text-label-2',
        clickable: false,
    }
})
</script>

<template>
    <button
        type="button"
        :class="[
            'flex shrink-0 items-center gap-1.5 rounded-full px-2 py-1 text-[13px] font-semibold sm:px-2.5',
            state.tone,
        ]"
        :disabled="!state.clickable"
        :aria-label="state.label"
        :title="state.label"
        @click="emit('retry')"
    >
        <component :is="state.icon" :size="15" weight="bold" class="shrink-0" aria-hidden="true" />
        <span class="whitespace-nowrap">{{ state.label }}</span>
    </button>
</template>
