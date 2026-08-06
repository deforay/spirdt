<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import {
    type GeoTree,
    listGeoUnits,
    listTestingSites,
    type RegistryTestingSite,
} from '@/api/registry'
import { session } from '@/auth/session'
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
            <RouterLink
                v-if="canWrite"
                :to="{ name: 'admin-site-new' }"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
            >
                {{ t('sitesAdmin.add') }}
            </RouterLink>
        </div>

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
                <RouterLink
                    :to="{ name: 'admin-site', params: { id: site.id } }"
                    class="min-w-0 flex-1"
                >
                    <span class="block truncate text-[15px] hover:text-accent">{{ site.name }}</span>
                    <span class="block truncate text-[12px] text-label-2">
                        {{ site.facility_name }}<template v-if="site.place"> · {{ site.place }}</template>
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
