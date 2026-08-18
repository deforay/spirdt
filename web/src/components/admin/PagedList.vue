<script setup lang="ts">
import { computed } from 'vue'

import { formatNumber, t } from '@/i18n'

/**
 * The frame every long list sits in: a count, and a way through it.
 *
 * The count is not decoration. A list that just stops gives no way to tell
 * "these are all of them" from "these are the first fifty", and the difference
 * matters when somebody is deciding whether a facility needs adding or already
 * exists further down.
 *
 * Numbered pages rather than only Previous and Next. "Page 3 of 9" tells you
 * where you are and gives you one way to move; the numbers let somebody go
 * back to the page they were on before they opened a record, which is the
 * actual journey through a registry. Long runs are elided so the row never
 * wraps: first, last, and a window around where you are.
 */

const props = defineProps<{
    total: number
    page: number
    perPage: number
    loading?: boolean
}>()

const emit = defineEmits<{ 'update:page': [value: number] }>()

const pages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))

const from = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1))
const to = computed(() => Math.min(props.page * props.perPage, props.total))

/**
 * The page numbers to draw: always the first and last, plus one either side of
 * the current page, with gaps marked rather than filled.
 */
const windowed = computed<(number | 'gap')[]>(() => {
    const last = pages.value
    const current = props.page
    const wanted = new Set<number>([1, last, current - 1, current, current + 1])
    const shown = [...wanted].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b)
    const out: (number | 'gap')[] = []

    shown.forEach((page, index) => {
        if (index > 0 && page - shown[index - 1]! > 1) out.push('gap')
        out.push(page)
    })

    return out
})
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-4 pt-4 text-[14px] text-label-2">
        <span class="tnum">
            <template v-if="loading">{{ t('admin.loading') }}</template>
            <template v-else-if="total === 0">{{ t('registry.nothingFound') }}</template>
            <template v-else>
                {{
                    t('registry.showing', {
                        from: formatNumber(from),
                        to: formatNumber(to),
                        total: formatNumber(total),
                    })
                }}
            </template>
        </span>

        <nav v-if="pages > 1" class="flex items-center gap-1.5" :aria-label="t('registry.pages')">
            <button
                type="button"
                class="min-h-10 rounded-card border border-hairline px-3.5 text-[14px] font-medium transition-colors hover:bg-accent-soft hover:text-accent disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-label-2"
                :disabled="page <= 1"
                @click="emit('update:page', page - 1)"
            >
                {{ t('registry.previous') }}
            </button>

            <template v-for="(entry, index) in windowed" :key="`${entry}-${index}`">
                <span v-if="entry === 'gap'" class="px-1 text-label-3" aria-hidden="true">…</span>
                <button
                    v-else
                    type="button"
                    :class="[
                        'tnum min-h-10 min-w-10 rounded-card px-2 text-[14px] font-medium transition-colors',
                        entry === page
                            ? 'bg-accent text-accent-ink'
                            : 'text-label-2 hover:bg-accent-soft hover:text-accent',
                    ]"
                    :aria-current="entry === page ? 'page' : undefined"
                    @click="emit('update:page', entry)"
                >
                    {{ formatNumber(entry) }}
                </button>
            </template>

            <button
                type="button"
                class="min-h-10 rounded-card border border-hairline px-3.5 text-[14px] font-medium transition-colors hover:bg-accent-soft hover:text-accent disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-label-2"
                :disabled="page >= pages"
                @click="emit('update:page', page + 1)"
            >
                {{ t('registry.next') }}
            </button>
        </nav>
    </div>
</template>
