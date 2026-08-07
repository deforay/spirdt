<script setup lang="ts">
import { PhInfo } from '@phosphor-icons/vue'
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
    if (props.storage.risk === 'advisory') return 'note'
    return 'none'
})

/**
 * Only a real problem interrupts.
 *
 * `role="alert"` is announced immediately and cuts across whatever a screen
 * reader was saying. That is right for a device which is not saving. It is
 * wrong for a browser that has merely not promised to keep the data, which is
 * the ordinary state of every desktop tab — and a notice that shouts at
 * everybody, every time, is one nobody reads on the day it matters.
 */
const alerting = computed(() => tone.value === 'error' || tone.value === 'warn')

const message = computed(() => {
    if (props.saveState === 'error') {
        return props.saveError || t('storage.saveFailed')
    }

    const key = props.storage?.messageKey

    return key ? t(key) : ''
})
</script>

<template>
    <!--
        A problem is a band across the whole screen, in its own colour, with a
        mark beside it. A note is a card like every other card on the screen.

        The note is deliberately colourless. Green, amber and red mean a
        response on this app and nothing else, so a reassuring green tick here
        would be borrowing a word that is already spoken for — see
        docs/design.md.
    -->
    <div
        v-if="tone !== 'none'"
        :role="alerting ? 'alert' : 'status'"
        :class="[
            'flex items-start gap-2.5 leading-snug',
            alerting
                ? 'px-4 py-3 text-[13px]'
                : 'mx-4 mb-1 mt-3 rounded-card bg-surface px-3 py-2.5 text-[12px] md:mx-0',
            tone === 'error' ? 'bg-no-soft text-no' : '',
            tone === 'warn' ? 'bg-partial-soft text-partial' : '',
            tone === 'note' ? 'text-label-2' : '',
        ]"
    >
        <span v-if="alerting" aria-hidden="true" class="mt-px font-semibold">!</span>
        <PhInfo v-else :size="15" class="mt-px shrink-0 text-label-3" aria-hidden="true" />
        <p class="flex-1">{{ message }}</p>
    </div>
</template>
