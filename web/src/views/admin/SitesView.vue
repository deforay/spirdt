<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import {
    createTestingSite,
    type Facility,
    type GeoTree,
    listFacilities,
    listGeoUnits,
    listTestingSites,
    type RegistryTestingSite,
    updateTestingSite,
} from '@/api/registry'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import { t } from '@/i18n'

/**
 * Testing sites — the benches assessments are actually against.
 *
 * Filtered by place in ONE request. The earlier version fetched a place's
 * facilities and then asked per facility, which in a district of two hundred
 * facilities is two hundred requests to fill one table.
 *
 * Adding one needs a facility, and the facility is found by typing rather than
 * chosen from a list of thousands.
 */

const tree = ref<GeoTree>({ units: [], paths: {} })
const sites = ref<RegistryTestingSite[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)

const geoUnitId = ref<number | null>(null)
const search = ref('')
const loading = ref(true)
const error = ref('')

const canWrite = computed(() => ['admin', 'superadmin'].includes(session.value?.user.role ?? ''))

const adding = ref(false)
const draft = ref({ name: '', facilityId: '' })
const facilitySearch = ref('')
const facilityMatches = ref<Facility[]>([])

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
        const result = await listTestingSites({
            geoUnitId: geoUnitId.value,
            search: search.value,
            page: page.value,
            perPage: perPage.value,
        })

        sites.value = result.rows
        total.value = result.total
        perPage.value = result.per_page
    })

    loading.value = false
}

let timer: ReturnType<typeof setTimeout> | undefined

watch([search, geoUnitId], () => {
    page.value = 1
    clearTimeout(timer)
    timer = setTimeout(load, 250)
})

watch(page, load)

/** The facility to hang a new site off, found by typing. */
let facilityTimer: ReturnType<typeof setTimeout> | undefined

watch(facilitySearch, () => {
    clearTimeout(facilityTimer)

    facilityTimer = setTimeout(async () => {
        if (facilitySearch.value.trim() === '') {
            facilityMatches.value = []

            return
        }

        await act(async () => {
            facilityMatches.value = (
                await listFacilities({ search: facilitySearch.value, perPage: 10 })
            ).rows
        })
    }, 250)
})

async function onAdd(): Promise<void> {
    if (draft.value.name.trim() === '' || draft.value.facilityId === '') {
        return
    }

    const created = await act(() =>
        createTestingSite({ name: draft.value.name.trim(), facility_id: draft.value.facilityId }),
    )

    if (created !== null) {
        draft.value = { name: '', facilityId: '' }
        facilitySearch.value = ''
        facilityMatches.value = []
        adding.value = false
        await load()
    }
}

async function onToggle(site: RegistryTestingSite): Promise<void> {
    const updated = await act(() => updateTestingSite(site.id, { is_active: !site.is_active }))

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
    <AdminShell :title="t('sitesAdmin.title')" :subtitle="t('sitesAdmin.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-start gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('sitesAdmin.search')"
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
                {{ adding ? t('action.cancel') : t('sitesAdmin.add') }}
            </button>
        </div>

        <form
            v-if="adding && canWrite"
            class="mb-5 rounded-card bg-surface p-4"
            @submit.prevent="onAdd"
        >
            <div class="flex flex-wrap items-start gap-2">
                <input
                    v-model="draft.name"
                    type="text"
                    :placeholder="t('registry.siteName')"
                    class="min-w-[200px] flex-1 rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                />
                <input
                    v-model="facilitySearch"
                    type="search"
                    :placeholder="t('sitesAdmin.findFacility')"
                    class="min-w-[220px] flex-1 rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                />
                <button
                    type="submit"
                    class="rounded-lg bg-accent px-4 py-2 text-[14px] font-semibold text-white disabled:opacity-40"
                    :disabled="draft.name.trim() === '' || draft.facilityId === ''"
                >
                    {{ t('action.add') }}
                </button>
            </div>

            <div v-if="facilityMatches.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                <button
                    v-for="facility in facilityMatches"
                    :key="facility.id"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-[13px]"
                    :class="
                        draft.facilityId === facility.id
                            ? 'bg-accent text-white'
                            : 'bg-ground text-label-2'
                    "
                    @click="draft.facilityId = facility.id"
                >
                    {{ facility.name }}
                    <span class="opacity-70">{{ facility.place }}</span>
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-card bg-surface">
            <p v-if="!loading && sites.length === 0" class="px-4 py-3 text-[14px] text-label-2">
                {{ t('registry.nothingFound') }}
            </p>
            <div
                v-for="(site, index) in sites"
                :key="site.id"
                class="flex items-center justify-between gap-3 px-4 py-2.5"
                :class="[index > 0 ? 'border-t border-hairline' : '', site.is_active ? '' : 'opacity-50']"
            >
                <div class="min-w-0 flex-1">
                    <span class="block truncate text-[15px]">{{ site.name }}</span>
                    <span class="block truncate text-[12px] text-label-2">
                        {{ site.facility_name }}<template v-if="site.place"> · {{ site.place }}</template>
                    </span>
                </div>
                <button
                    v-if="canWrite"
                    type="button"
                    class="shrink-0 text-[13px]"
                    :class="site.is_active ? 'text-no' : 'text-yes'"
                    @click="onToggle(site)"
                >
                    {{ site.is_active ? t('admin.deactivate') : t('admin.activate') }}
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
