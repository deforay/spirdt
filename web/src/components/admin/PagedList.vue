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
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 pt-3 text-[13px] text-label-2">
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

        <div v-if="pages > 1" class="flex items-center gap-2">
            <button
                type="button"
                class="rounded-full bg-surface px-3 py-1.5 text-[13px] disabled:opacity-40"
                :disabled="page <= 1"
                @click="emit('update:page', page - 1)"
            >
                {{ t('registry.previous') }}
            </button>
            <span class="tnum">
                {{ t('registry.pageOf', { page: formatNumber(page), pages: formatNumber(pages) }) }}
            </span>
            <button
                type="button"
                class="rounded-full bg-surface px-3 py-1.5 text-[13px] disabled:opacity-40"
                :disabled="page >= pages"
                @click="emit('update:page', page + 1)"
            >
                {{ t('registry.next') }}
            </button>
        </div>
    </div>
</template>
