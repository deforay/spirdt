<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import {
    createFacility,
    createGeoUnit,
    createTestingSite,
    type Facility,
    type GeoUnit,
    listFacilities,
    listGeoUnits,
    listTestingSites,
    type RegistryTestingSite,
    updateFacility,
    updateGeoUnit,
    updateTestingSite,
} from '@/api/registry'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import GeoCascade from '@/components/admin/GeoCascade.vue'
import { t } from '@/i18n'

/**
 * The national list: places, the facilities in them, the benches inside those.
 *
 * One cascade drives the whole screen. Narrowing to a district filters the
 * facilities; choosing a facility reveals its testing sites. That is the same
 * order the data has, and it keeps three lists on one page without three sets
 * of filters.
 *
 * Nothing here deletes. Deactivation instead — assessments already reference
 * these rows, and `geo_units.parent_id` cascades on delete, so removing a
 * province would take every district and facility under it with no warning.
 */

const units = ref<GeoUnit[]>([])
const facilities = ref<Facility[]>([])
const sites = ref<RegistryTestingSite[]>([])

const geoUnitId = ref<number | null>(null)
const facilityId = ref<string | null>(null)

const loading = ref(true)
const error = ref('')

const canWrite = computed(() => ['admin', 'superadmin'].includes(session.value?.user.role ?? ''))

const newPlace = ref({ name: '', level: '' })
const newFacility = ref({ name: '' })
const newSite = ref({ name: '' })

/**
 * The level a new child would sit at.
 *
 * Suggested from what siblings already use, so an administrator adding the
 * fortieth district does not retype the word — and left editable, because the
 * first child at any depth has no sibling to copy and the programme is the only
 * thing that knows what that tier is called.
 */
const suggestedLevel = computed(() => {
    const siblings = units.value.filter((unit) => unit.parent_id === geoUnitId.value)

    return siblings[0]?.level ?? ''
})

const placesHere = computed(() =>
    units.value.filter((unit) => unit.parent_id === geoUnitId.value),
)

const selectedFacility = computed(() =>
    facilities.value.find((facility) => facility.id === facilityId.value) ?? null,
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

async function loadAll(): Promise<void> {
    loading.value = true
    await act(async () => {
        units.value = await listGeoUnits()
    })
    await loadFacilities()
    loading.value = false
}

async function loadFacilities(): Promise<void> {
    await act(async () => {
        facilities.value = await listFacilities(geoUnitId.value)
    })

    // A facility chosen under the previous filter may not be in this list.
    if (!facilities.value.some((facility) => facility.id === facilityId.value)) {
        facilityId.value = null
        sites.value = []
    }
}

async function loadSites(): Promise<void> {
    if (facilityId.value === null) {
        sites.value = []

        return
    }

    await act(async () => {
        sites.value = await listTestingSites(facilityId.value)
    })
}

watch(geoUnitId, loadFacilities)
watch(facilityId, loadSites)

async function onAddPlace(): Promise<void> {
    const level = newPlace.value.level.trim() || suggestedLevel.value

    if (newPlace.value.name.trim() === '' || level === '') {
        return
    }

    const created = await act(() =>
        createGeoUnit({ name: newPlace.value.name.trim(), level, parent_id: geoUnitId.value }),
    )

    if (created !== null) {
        newPlace.value = { name: '', level: '' }
        units.value = await listGeoUnits()
    }
}

async function onTogglePlace(unit: GeoUnit): Promise<void> {
    const updated = await act(() => updateGeoUnit(unit.id, { is_active: !unit.is_active }))

    if (updated !== null) {
        units.value = await listGeoUnits()
    }
}

async function onAddFacility(): Promise<void> {
    if (newFacility.value.name.trim() === '') {
        return
    }

    const created = await act(() =>
        createFacility({ name: newFacility.value.name.trim(), geo_unit_id: geoUnitId.value }),
    )

    if (created !== null) {
        newFacility.value = { name: '' }
        await loadFacilities()
    }
}

async function onToggleFacility(facility: Facility): Promise<void> {
    const updated = await act(() => updateFacility(facility.id, { is_active: !facility.is_active }))

    if (updated !== null) {
        await loadFacilities()
    }
}

async function onAddSite(): Promise<void> {
    const facility = facilityId.value

    if (newSite.value.name.trim() === '' || facility === null) {
        return
    }

    const created = await act(() =>
        createTestingSite({ name: newSite.value.name.trim(), facility_id: facility }),
    )

    if (created !== null) {
        newSite.value = { name: '' }
        await loadSites()
    }
}

async function onToggleSite(site: RegistryTestingSite): Promise<void> {
    const updated = await act(() => updateTestingSite(site.id, { is_active: !site.is_active }))

    if (updated !== null) {
        await loadSites()
    }
}

onMounted(loadAll)
</script>

<template>
    <AdminShell :title="t('registry.title')" :subtitle="t('registry.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-5 rounded-card bg-surface p-4">
            <GeoCascade v-model="geoUnitId" :units="units" />
        </div>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="grid gap-5 lg:grid-cols-3">
            <!-- Places at the current depth. -->
            <section>
                <h2 class="mb-2 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                    {{ t('registry.placesWithin') }}
                </h2>

                <div class="overflow-hidden rounded-card bg-surface">
                    <p v-if="placesHere.length === 0" class="px-3.5 py-3 text-[14px] text-label-2">
                        {{ t('registry.noPlaces') }}
                    </p>
                    <div
                        v-for="(unit, index) in placesHere"
                        :key="unit.id"
                        class="flex items-center justify-between gap-3 px-3.5 py-2.5"
                        :class="[index > 0 ? 'border-t border-hairline' : '', unit.is_active ? '' : 'opacity-50']"
                    >
                        <button
                            type="button"
                            class="flex-1 text-left text-[15px] hover:text-accent"
                            @click="geoUnitId = unit.id"
                        >
                            {{ unit.name }}
                            <span class="text-[12px] text-label-3">{{ unit.level }}</span>
                        </button>
                        <button
                            v-if="canWrite"
                            type="button"
                            class="text-[13px]"
                            :class="unit.is_active ? 'text-no' : 'text-yes'"
                            @click="onTogglePlace(unit)"
                        >
                            {{ unit.is_active ? t('admin.deactivate') : t('admin.activate') }}
                        </button>
                    </div>
                </div>

                <form v-if="canWrite" class="mt-2 flex gap-2" @submit.prevent="onAddPlace">
                    <input
                        v-model="newPlace.name"
                        type="text"
                        :placeholder="t('registry.placeName')"
                        class="min-w-0 flex-1 rounded-lg bg-surface px-3 py-2 text-[14px] outline-none placeholder:text-label-3"
                    />
                    <input
                        v-model="newPlace.level"
                        type="text"
                        :placeholder="suggestedLevel || t('registry.levelName')"
                        class="w-[110px] rounded-lg bg-surface px-3 py-2 text-[14px] outline-none placeholder:text-label-3"
                    />
                    <button type="submit" class="rounded-lg bg-accent px-3 py-2 text-[14px] font-semibold text-white">
                        {{ t('action.add') }}
                    </button>
                </form>
            </section>

            <!-- Facilities in the selected place. -->
            <section>
                <h2 class="mb-2 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                    {{ t('registry.facilities') }}
                </h2>

                <div class="overflow-hidden rounded-card bg-surface">
                    <p v-if="facilities.length === 0" class="px-3.5 py-3 text-[14px] text-label-2">
                        {{ t('registry.noFacilities') }}
                    </p>
                    <div
                        v-for="(facility, index) in facilities"
                        :key="facility.id"
                        class="flex items-center justify-between gap-3 px-3.5 py-2.5"
                        :class="[
                            index > 0 ? 'border-t border-hairline' : '',
                            facility.is_active ? '' : 'opacity-50',
                            facility.id === facilityId ? 'bg-accent-soft' : '',
                        ]"
                    >
                        <button
                            type="button"
                            class="flex-1 text-left text-[15px] hover:text-accent"
                            @click="facilityId = facility.id"
                        >
                            {{ facility.name }}
                            <!-- Created in the field and never reconciled against
                                 the registry. Worth seeing at a glance. -->
                            <span v-if="facility.source === 'field'" class="text-[12px] text-partial">
                                {{ t('registry.fromTheField') }}
                            </span>
                        </button>
                        <button
                            v-if="canWrite"
                            type="button"
                            class="text-[13px]"
                            :class="facility.is_active ? 'text-no' : 'text-yes'"
                            @click="onToggleFacility(facility)"
                        >
                            {{ facility.is_active ? t('admin.deactivate') : t('admin.activate') }}
                        </button>
                    </div>
                </div>

                <form v-if="canWrite" class="mt-2 flex gap-2" @submit.prevent="onAddFacility">
                    <input
                        v-model="newFacility.name"
                        type="text"
                        :placeholder="t('registry.facilityName')"
                        class="min-w-0 flex-1 rounded-lg bg-surface px-3 py-2 text-[14px] outline-none placeholder:text-label-3"
                    />
                    <button type="submit" class="rounded-lg bg-accent px-3 py-2 text-[14px] font-semibold text-white">
                        {{ t('action.add') }}
                    </button>
                </form>
            </section>

            <!-- Testing sites in the selected facility. -->
            <section>
                <h2 class="mb-2 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                    {{ t('registry.testingSites') }}
                </h2>

                <div class="overflow-hidden rounded-card bg-surface">
                    <p v-if="selectedFacility === null" class="px-3.5 py-3 text-[14px] text-label-2">
                        {{ t('registry.chooseFacility') }}
                    </p>
                    <p
                        v-else-if="sites.length === 0"
                        class="px-3.5 py-3 text-[14px] text-label-2"
                    >
                        {{ t('registry.noSites') }}
                    </p>
                    <div
                        v-for="(site, index) in sites"
                        :key="site.id"
                        class="flex items-center justify-between gap-3 px-3.5 py-2.5"
                        :class="[index > 0 ? 'border-t border-hairline' : '', site.is_active ? '' : 'opacity-50']"
                    >
                        <span class="flex-1 text-[15px]">{{ site.name }}</span>
                        <button
                            v-if="canWrite"
                            type="button"
                            class="text-[13px]"
                            :class="site.is_active ? 'text-no' : 'text-yes'"
                            @click="onToggleSite(site)"
                        >
                            {{ site.is_active ? t('admin.deactivate') : t('admin.activate') }}
                        </button>
                    </div>
                </div>

                <form
                    v-if="canWrite && selectedFacility !== null"
                    class="mt-2 flex gap-2"
                    @submit.prevent="onAddSite"
                >
                    <input
                        v-model="newSite.name"
                        type="text"
                        :placeholder="t('registry.siteName')"
                        class="min-w-0 flex-1 rounded-lg bg-surface px-3 py-2 text-[14px] outline-none placeholder:text-label-3"
                    />
                    <button type="submit" class="rounded-lg bg-accent px-3 py-2 text-[14px] font-semibold text-white">
                        {{ t('action.add') }}
                    </button>
                </form>
            </section>
        </div>
    </AdminShell>
</template>
