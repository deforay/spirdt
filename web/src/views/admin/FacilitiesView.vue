<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { type Facility, type GeoTree, listFacilities, listGeoUnits } from '@/api/registry'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import { t } from '@/i18n'
import { RouterLink } from 'vue-router'

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
            <RouterLink
                v-if="canWrite"
                :to="{ name: 'admin-facility-new' }"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
            >
                {{ t('facilities.add') }}
            </RouterLink>
        </div>

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
                <RouterLink
                    :to="{ name: 'admin-facility', params: { id: facility.id } }"
                    class="min-w-0 flex-1"
                >
                    <span class="block truncate text-[15px] hover:text-accent">
                        {{ facility.name }}
                        <span v-if="facility.code" class="text-[12px] text-label-3">
                            {{ facility.code }}
                        </span>
                        <span v-if="facility.source === 'field'" class="text-[12px] text-partial">
                            {{ t('registry.fromTheField') }}
                        </span>
                    </span>
                    <span class="block truncate text-[12px] text-label-2">
                        {{ facility.place ?? t('facilities.noPlace') }}
                        <template v-if="facility.contact_phone"> · {{ facility.contact_phone }}</template>
                    </span>
                </RouterLink>
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
