<script setup lang="ts">
import { PhInfo, PhX } from '@phosphor-icons/vue'
import { computed, ref } from 'vue'

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

    if (props.storage.risk === 'advisory') {
        return props.storage.messageKey === dismissed.value ? 'none' : 'note'
    }

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

/**
 * A note is said once. A problem is said until it stops being true.
 *
 * The advisory sat above every screen of a fifty-nine question form, saying
 * the same sentence for the whole visit. It is worth reading once — the work
 * is on this device and syncing is what gets it off — and after that it is a
 * row of the screen spent on something the reader has already dealt with. A
 * notice nobody can dismiss is a notice everybody stops seeing, including on
 * the day it changes.
 *
 * Dismissal is remembered against the MESSAGE, not against the component, so
 * a device whose situation changes — storage filling up, persistence lost —
 * says the new thing rather than staying quiet because something else was
 * dismissed once.
 *
 * Only the advisory can be dismissed. A device that is not saving is not a
 * preference.
 */
const DISMISSED_KEY = 'spirdt.storage.dismissed'

const dismissed = ref(read())

function read(): string {
    try {
        return localStorage.getItem(DISMISSED_KEY) ?? ''
    } catch {
        // Private browsing, which is itself one of the things worth warning
        // about — so fail towards showing the notice.
        return ''
    }
}

function dismiss(): void {
    const key = props.storage?.messageKey ?? ''

    dismissed.value = key

    try {
        localStorage.setItem(DISMISSED_KEY, key)
    } catch {
        // It stays dismissed for this tab and returns on the next load, which
        // is the right way round for a device that cannot remember anything.
    }
}

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
                ? '-mx-4 px-4 py-3 text-[13px] sm:-mx-6 sm:px-6'
                : 'mb-1 mt-3 rounded-card bg-surface px-3 py-2.5 text-[12px]',
            tone === 'error' ? 'bg-no-soft text-no' : '',
            tone === 'warn' ? 'bg-partial-soft text-partial' : '',
            tone === 'note' ? 'text-label-2' : '',
        ]"
    >
        <span v-if="alerting" aria-hidden="true" class="mt-px font-semibold">!</span>
        <PhInfo v-else :size="15" class="mt-px shrink-0 text-label-3" aria-hidden="true" />
        <p class="flex-1">{{ message }}</p>

        <button
            v-if="!alerting"
            type="button"
            class="-my-1 -mr-1 shrink-0 rounded-full p-1 text-label-3 transition-colors hover:text-label"
            :aria-label="t('action.dismiss')"
            @click="dismiss"
        >
            <PhX :size="14" aria-hidden="true" />
        </button>
    </div>
</template>
