<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import {
    type GeoTree,
    listGeoUnits,
    listTestingSites,
    type RegistryTestingSite,
} from '@/api/registry'
import { can, PERMISSION } from '@/auth/permissions'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import { t } from '@/i18n'
import { RouterLink } from 'vue-router'

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

onMounted(async () => {
    await act(async () => {
        tree.value = await listGeoUnits()
    })
    await load()
})
</script>

<template>
    <AdminShell :title="t('sitesAdmin.title')" :subtitle="t('sitesAdmin.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-start gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('sitesAdmin.search')"
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
                :to="{ name: 'admin-site-new' }"
                class="flex min-h-11 shrink-0 items-center rounded-card bg-accent px-5 text-[15px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover"
            >
                {{ t('sitesAdmin.add') }}
            </RouterLink>
        </div>

        <div class="data-card data-scroll">
            <table class="data-table min-w-[720px]">
                <thead>
                    <tr>
                        <th>{{ t('registry.siteName') }}</th>
                        <th>{{ t('registry.facilityName') }}</th>
                        <th>{{ t('registry.place') }}</th>
                        <th>{{ t('admin.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!loading && sites.length === 0">
                        <td colspan="4" class="text-label-2">{{ t('registry.nothingFound') }}</td>
                    </tr>

                    <tr v-for="site in sites" :key="site.id">
                        <td>
                            <RouterLink
                                :to="{ name: 'admin-site', params: { id: site.id } }"
                                class="font-medium hover:text-accent"
                            >
                                {{ site.name }}
                            </RouterLink>
                        </td>
                        <td class="text-label-2">{{ site.facility_name }}</td>
                        <td class="text-label-2">{{ site.place ?? '—' }}</td>
                        <td>
                            <span
                                :class="[
                                    'chip',
                                    site.is_active
                                        ? 'bg-accent-soft text-accent'
                                        : 'bg-track text-label-2',
                                ]"
                            >
                                {{ site.is_active ? t('admin.active') : t('admin.disabled') }}
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
