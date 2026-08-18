<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { type Facility, type GeoTree, listFacilities, listGeoUnits } from '@/api/registry'
import { can, PERMISSION } from '@/auth/permissions'
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

const canWrite = computed(() => can(PERMISSION.registryWrite))

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
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-start gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('facilities.search')"
                class="field min-w-[260px] flex-1"
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
                class="flex min-h-11 shrink-0 items-center rounded-card bg-accent px-5 text-[15px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover"
            >
                {{ t('facilities.add') }}
            </RouterLink>
        </div>

        <div class="data-card data-scroll">
            <table class="data-table min-w-[760px]">
                <thead>
                    <tr>
                        <th>{{ t('registry.facilityName') }}</th>
                        <th>{{ t('report.facilityCode') }}</th>
                        <th>{{ t('registry.place') }}</th>
                        <th>{{ t('admin.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!loading && facilities.length === 0">
                        <td colspan="4" class="text-label-2">{{ t('registry.nothingFound') }}</td>
                    </tr>

                    <tr v-for="facility in facilities" :key="facility.id">
                        <td>
                            <RouterLink
                                :to="{ name: 'admin-facility', params: { id: facility.id } }"
                                class="font-medium hover:text-accent"
                            >
                                {{ facility.name }}
                            </RouterLink>
                            <!-- Brass rather than amber: a facility added by an
                                 assessor in the field is a provenance note, not
                                 a Partial. -->
                            <span
                                v-if="facility.source === 'field'"
                                class="chip ml-2 bg-brass-soft text-brass"
                            >
                                {{ t('registry.fromTheField') }}
                            </span>
                            <span
                                v-if="facility.contact_phone"
                                class="tnum mt-1 block text-[13px] text-label-3"
                            >
                                {{ facility.contact_phone }}
                            </span>
                        </td>
                        <td class="tnum text-label-2">{{ facility.code ?? '—' }}</td>
                        <td class="text-label-2">{{ facility.place ?? t('facilities.noPlace') }}</td>
                        <td>
                            <span
                                :class="[
                                    'chip',
                                    facility.is_active
                                        ? 'bg-accent-soft text-accent'
                                        : 'bg-track text-label-2',
                                ]"
                            >
                                {{ facility.is_active ? t('admin.active') : t('admin.disabled') }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
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
