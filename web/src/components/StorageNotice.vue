<script setup lang="ts">
import { computed } from 'vue'

import type { StorageReport } from '@/db/storage'
import type { SaveState } from '@/composables/useAssessment'
import { t } from '@/i18n'

/**
 * Says whether this device is keeping the assessment.
 *
 * Shown before the assessor starts, because none of what it reports can be
 * fixed afterwards. A browser that is not saving, or that will clear the data
 * in a week, has to be found out at the door of the site and not at the end of
 * the visit.
 *
 * A failed write outranks everything else here. It is the one state where what
 * is on screen and what is on the device disagree.
 */

const props = defineProps<{
    storage: StorageReport | null
    saveState: SaveState
    saveError: string
}>()

const tone = computed(() => {
    if (props.saveState === 'error') return 'error'
    if (!props.storage) return 'none'
    if (props.storage.risk === 'broken') return 'error'
    if (props.storage.risk === 'at-risk') return 'warn'
    return 'none'
})

const message = computed(() => {
    if (props.saveState === 'error') {
        return props.saveError || t('storage.saveFailed')
    }

    const key = props.storage?.messageKey

    return key ? t(key) : ''
})
</script>

<template>
    <div
        v-if="tone !== 'none'"
        role="alert"
        :class="[
            'flex items-start gap-2.5 px-4 py-3 text-[13px] leading-snug',
            tone === 'error' ? 'bg-no-soft text-no' : 'bg-partial-soft text-partial',
        ]"
    >
        <span aria-hidden="true" class="mt-px font-semibold">!</span>
        <p class="flex-1">{{ message }}</p>
    </div>
</template>
