<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { createGeoUnit, type GeoTree, listGeoUnits, updateGeoUnit } from '@/api/registry'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'

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

/** Adding under a parent chosen from the list, so the level can be suggested. */
const parentId = ref<number | null>(null)
const draft = ref({ name: '', level: '' })

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

/** Whatever siblings already use, so nobody retypes "District" forty times. */
const suggestedLevel = computed(
    () => tree.value.units.find((unit) => unit.parent_id === parentId.value)?.level ?? '',
)

const parentPath = computed(() =>
    parentId.value === null ? t('places.topLevel') : (tree.value.paths[parentId.value] ?? ''),
)

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

async function onAdd(): Promise<void> {
    const level = draft.value.level.trim() || suggestedLevel.value

    if (draft.value.name.trim() === '' || level === '') {
        return
    }

    const created = await act(() =>
        createGeoUnit({ name: draft.value.name.trim(), level, parent_id: parentId.value }),
    )

    if (created !== null) {
        draft.value = { name: '', level: '' }
        await load()
    }
}

async function onToggle(id: number, isActive: boolean): Promise<void> {
    const updated = await act(() => updateGeoUnit(id, { is_active: !isActive }))

    if (updated !== null) {
        await load()
    }
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

        <form v-if="canWrite" class="mb-5 rounded-card bg-surface p-4" @submit.prevent="onAdd">
            <p class="mb-2 text-[13px] text-label-2">
                {{ t('places.addingUnder', { place: parentPath }) }}
                <button
                    v-if="parentId !== null"
                    type="button"
                    class="ml-2 text-accent"
                    @click="parentId = null"
                >
                    {{ t('places.moveToTop') }}
                </button>
            </p>
            <div class="flex flex-wrap gap-2">
                <input
                    v-model="draft.name"
                    type="text"
                    :placeholder="t('registry.placeName')"
                    class="min-w-[180px] flex-1 rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                />
                <input
                    v-model="draft.level"
                    type="text"
                    :placeholder="suggestedLevel || t('registry.levelName')"
                    class="w-[140px] rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                />
                <button
                    type="submit"
                    class="rounded-lg bg-accent px-4 py-2 text-[14px] font-semibold text-white"
                >
                    {{ t('action.add') }}
                </button>
            </div>
        </form>

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
                <div class="min-w-0 flex-1">
                    <span class="block truncate text-[15px]">{{ tree.paths[unit.id] }}</span>
                    <span class="text-[12px] text-label-2">{{ unit.level }}</span>
                </div>
                <button
                    v-if="canWrite"
                    type="button"
                    class="shrink-0 text-[13px] text-accent"
                    @click="parentId = unit.id"
                >
                    {{ t('places.addUnder') }}
                </button>
                <button
                    v-if="canWrite"
                    type="button"
                    class="shrink-0 text-[13px]"
                    :class="unit.is_active ? 'text-no' : 'text-yes'"
                    @click="onToggle(unit.id, unit.is_active)"
                >
                    {{ unit.is_active ? t('admin.deactivate') : t('admin.activate') }}
                </button>
            </div>
        </div>
    </AdminShell>
</template>
