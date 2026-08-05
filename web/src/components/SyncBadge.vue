<script setup lang="ts">
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
 */

const emit = defineEmits<{ retry: [] }>()

const state = computed(() => {
    const status = syncStatus.value

    if (status.blocked > 0) {
        return { label: t('sync.blocked'), tone: 'bg-no-soft text-no', clickable: true }
    }

    if (status.running) {
        return { label: t('sync.running'), tone: 'bg-track text-label-2', clickable: false }
    }

    if (status.pending > 0) {
        return {
            label: t('sync.pending', { count: status.pending }),
            tone: 'bg-partial-soft text-partial',
            clickable: true,
        }
    }

    if (status.lastRunAt === null) {
        return { label: t('sync.never'), tone: 'bg-track text-label-2', clickable: true }
    }

    return { label: t('sync.synced'), tone: 'bg-yes-soft text-yes', clickable: false }
})
</script>

<template>
    <button
        type="button"
        :class="['rounded-full px-2.5 py-1 text-[12px] font-semibold', state.tone]"
        :disabled="!state.clickable"
        @click="emit('retry')"
    >
        {{ state.label }}
    </button>
</template>
