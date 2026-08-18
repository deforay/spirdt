<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { type GeoTree, listGeoUnits } from '@/api/registry'
import { can, PERMISSION } from '@/auth/permissions'
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

const canWrite = computed(() => can(PERMISSION.registryWrite))

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
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <!-- Search and the way to add sit on one line. They were stacked, so
             a screen whose whole content is a list spent two rows saying how to
             filter it. -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('places.search')"
                class="field min-w-[260px] flex-1"
            />

            <RouterLink
                v-if="canWrite"
                :to="{ name: 'admin-place-new' }"
                class="flex min-h-11 shrink-0 items-center rounded-card bg-accent px-5 text-[15px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover"
            >
                {{ t('placeForm.addTitle') }}
            </RouterLink>
        </div>

        <p v-if="loading" class="text-[16px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="data-card data-scroll">
            <table class="data-table min-w-[640px]">
                <thead>
                    <tr>
                        <th>{{ t('registry.placeName') }}</th>
                        <th>{{ t('registry.levelName') }}</th>
                        <th>{{ t('admin.status') }}</th>
                        <th class="text-right">{{ t('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="matches.length === 0">
                        <td colspan="4" class="text-label-2">{{ t('registry.nothingFound') }}</td>
                    </tr>

                    <tr v-for="unit in matches" :key="unit.id">
                        <td>
                            <RouterLink
                                :to="{ name: 'admin-place', params: { id: unit.id } }"
                                class="font-medium hover:text-accent"
                            >
                                {{ tree.paths[unit.id] }}
                            </RouterLink>
                        </td>
                        <td class="text-label-2">{{ unit.level }}</td>
                        <td>
                            <span
                                :class="[
                                    'chip',
                                    unit.is_active
                                        ? 'bg-accent-soft text-accent'
                                        : 'bg-track text-label-2',
                                ]"
                            >
                                {{ unit.is_active ? t('admin.active') : t('admin.disabled') }}
                            </span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <RouterLink
                                v-if="canWrite"
                                :to="{ name: 'admin-place-new', query: { parent: unit.id } }"
                                class="text-[14px] font-medium text-accent hover:text-accent-hover"
                            >
                                {{ t('places.addUnder') }}
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </AdminShell>
</template>
