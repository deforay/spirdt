<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { type GeoTree, listGeoUnits } from '@/api/registry'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'
import { RouterLink } from 'vue-router'

/**
 * The geographic hierarchy, on its own page.
 *
 * Search-first rather than a tree to drill through. A country runs to hundreds
 * of places across however many levels it uses, and somebody looking for one
 * knows its name — clicking down from province to district to find it is two
 * guesses in a fixed order.
 *
 * Every row shows its full path, because district names repeat across
 * provinces and the name alone does not identify one.
 */

const tree = ref<GeoTree>({ units: [], paths: {} })
const search = ref('')
const loading = ref(true)
const error = ref('')

const canWrite = computed(() => ['admin', 'superadmin'].includes(session.value?.user.role ?? ''))

const matches = computed(() => {
    const words = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean)

    if (words.length === 0) {
        return tree.value.units
    }

    return tree.value.units.filter((unit) => {
        const haystack = (tree.value.paths[unit.id] ?? unit.name).toLowerCase()

        return words.every((word) => haystack.includes(word))
    })
})

async function act<T>(run: () => Promise<T>): Promise<T | null> {
    error.value = ''

    try {
        return await run()
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')

        return null
    }
}

async function load(): Promise<void> {
    loading.value = true
    await act(async () => {
        tree.value = await listGeoUnits()
    })
    loading.value = false
}

onMounted(load)
</script>

<template>
    <AdminShell :title="t('places.title')" :subtitle="t('places.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('places.search')"
                class="min-w-[240px] flex-1 rounded-lg bg-surface px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
        </div>

        <div v-if="canWrite" class="mb-4 flex justify-end">
            <RouterLink
                :to="{ name: 'admin-place-new' }"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
            >
                {{ t('placeForm.addTitle') }}
            </RouterLink>
        </div>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="overflow-hidden rounded-card bg-surface">
            <p v-if="matches.length === 0" class="px-4 py-3 text-[14px] text-label-2">
                {{ t('registry.nothingFound') }}
            </p>
            <div
                v-for="(unit, index) in matches"
                :key="unit.id"
                class="flex items-center justify-between gap-3 px-4 py-2.5"
                :class="[index > 0 ? 'border-t border-hairline' : '', unit.is_active ? '' : 'opacity-50']"
            >
                <RouterLink
                    :to="{ name: 'admin-place', params: { id: unit.id } }"
                    class="min-w-0 flex-1"
                >
                    <span class="block truncate text-[15px] hover:text-accent">
                        {{ tree.paths[unit.id] }}
                    </span>
                    <span class="text-[12px] text-label-2">{{ unit.level }}</span>
                </RouterLink>
                <RouterLink
                    v-if="canWrite"
                    :to="{ name: 'admin-place-new', query: { parent: unit.id } }"
                    class="shrink-0 text-[13px] text-accent"
                >
                    {{ t('places.addUnder') }}
                </RouterLink>
            </div>
        </div>
    </AdminShell>
</template>
