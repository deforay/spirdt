<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import {
    createFacility,
    type Facility,
    type GeoTree,
    listFacilities,
    listGeoUnits,
    updateFacility,
} from '@/api/registry'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import { t } from '@/i18n'

/**
 * Facilities, on their own page, searched rather than browsed.
 *
 * A country runs to thousands of them, so the server pages and filters and
 * this never asks for all of them. The place filter narrows by SUBTREE —
 * choosing a province means every facility in every district under it, which
 * is what somebody choosing a province means.
 *
 * Every row prints its full path. Two clinics called "Chilenje Health Centre"
 * in different districts are otherwise the same row to whoever is reading.
 */

const tree = ref<GeoTree>({ units: [], paths: {} })
const facilities = ref<Facility[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)

const geoUnitId = ref<number | null>(null)
const search = ref('')
const loading = ref(true)
const error = ref('')

const canWrite = computed(() => ['admin', 'superadmin'].includes(session.value?.user.role ?? ''))

const adding = ref(false)
const draft = ref<{ name: string; geoUnitId: number | null }>({ name: '', geoUnitId: null })

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
        const result = await listFacilities({
            geoUnitId: geoUnitId.value,
            search: search.value,
            page: page.value,
            perPage: perPage.value,
        })

        facilities.value = result.rows
        total.value = result.total
        perPage.value = result.per_page
    })

    loading.value = false
}

/**
 * Typing restarts the search rather than paging through the old one.
 *
 * Debounced, because a request per keystroke against a national registry is
 * the thing the pagination was added to avoid.
 */
let timer: ReturnType<typeof setTimeout> | undefined

watch([search, geoUnitId], () => {
    page.value = 1
    clearTimeout(timer)
    timer = setTimeout(load, 250)
})

watch(page, load)

async function onAdd(): Promise<void> {
    if (draft.value.name.trim() === '') {
        return
    }

    const created = await act(() =>
        createFacility({ name: draft.value.name.trim(), geo_unit_id: draft.value.geoUnitId }),
    )

    if (created !== null) {
        draft.value = { name: '', geoUnitId: null }
        adding.value = false
        await load()
    }
}

async function onToggle(facility: Facility): Promise<void> {
    const updated = await act(() => updateFacility(facility.id, { is_active: !facility.is_active }))

    if (updated !== null) {
        await load()
    }
}

onMounted(async () => {
    await act(async () => {
        tree.value = await listGeoUnits()
    })
    await load()
})
</script>

<template>
    <AdminShell :title="t('facilities.title')" :subtitle="t('facilities.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-start gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('facilities.search')"
                class="min-w-[240px] flex-1 rounded-lg bg-surface px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <div class="min-w-[260px]">
                <PlacePicker
                    v-model="geoUnitId"
                    :tree="tree"
                    :placeholder="t('facilities.anywhere')"
                />
            </div>
            <button
                v-if="canWrite"
                type="button"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
                @click="adding = !adding"
            >
                {{ adding ? t('action.cancel') : t('facilities.add') }}
            </button>
        </div>

        <form
            v-if="adding && canWrite"
            class="mb-5 flex flex-wrap items-start gap-2 rounded-card bg-surface p-4"
            @submit.prevent="onAdd"
        >
            <input
                v-model="draft.name"
                type="text"
                :placeholder="t('registry.facilityName')"
                class="min-w-[200px] flex-1 rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <div class="min-w-[260px]">
                <PlacePicker v-model="draft.geoUnitId" :tree="tree" />
            </div>
            <button
                type="submit"
                class="rounded-lg bg-accent px-4 py-2 text-[14px] font-semibold text-white disabled:opacity-40"
                :disabled="draft.name.trim() === ''"
            >
                {{ t('action.add') }}
            </button>
        </form>

        <div class="overflow-hidden rounded-card bg-surface">
            <p v-if="!loading && facilities.length === 0" class="px-4 py-3 text-[14px] text-label-2">
                {{ t('registry.nothingFound') }}
            </p>
            <div
                v-for="(facility, index) in facilities"
                :key="facility.id"
                class="flex items-center justify-between gap-3 px-4 py-2.5"
                :class="[
                    index > 0 ? 'border-t border-hairline' : '',
                    facility.is_active ? '' : 'opacity-50',
                ]"
            >
                <div class="min-w-0 flex-1">
                    <span class="block truncate text-[15px]">
                        {{ facility.name }}
                        <span v-if="facility.source === 'field'" class="text-[12px] text-partial">
                            {{ t('registry.fromTheField') }}
                        </span>
                    </span>
                    <span class="block truncate text-[12px] text-label-2">
                        {{ facility.place ?? t('facilities.noPlace') }}
                    </span>
                </div>
                <button
                    v-if="canWrite"
                    type="button"
                    class="shrink-0 text-[13px]"
                    :class="facility.is_active ? 'text-no' : 'text-yes'"
                    @click="onToggle(facility)"
                >
                    {{ facility.is_active ? t('admin.deactivate') : t('admin.activate') }}
                </button>
            </div>
        </div>

        <PagedList
            :total="total"
            :page="page"
            :per-page="perPage"
            :loading="loading"
            @update:page="page = $event"
        />
    </AdminShell>
</template>
