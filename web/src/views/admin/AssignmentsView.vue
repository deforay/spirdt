<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { type AdminUser, listUsers } from '@/api/admin'
import {
    type Assignment,
    createAssignment,
    type Facility,
    type GeoUnit,
    listAssignments,
    listFacilities,
    listGeoUnits,
    listTestingSites,
    type RegistryTestingSite,
    withdrawAssignment,
} from '@/api/registry'
import AdminShell from '@/components/admin/AdminShell.vue'
import GeoCascade from '@/components/admin/GeoCascade.vue'
import { t } from '@/i18n'

/**
 * Who covers which site.
 *
 * Assigning to nobody in particular means the whole organisation — that is the
 * standing plan, and the common case. Naming an assessor narrows it to them,
 * and the site then leaves their colleagues' lists.
 *
 * The organisation is never chosen here. It comes from the token, because an
 * administrator plans their own organisation's work; the registry being shared
 * across the programme makes that stricter rather than looser.
 */

const units = ref<GeoUnit[]>([])
const facilities = ref<Facility[]>([])
const sites = ref<RegistryTestingSite[]>([])
const assignments = ref<Assignment[]>([])
const users = ref<AdminUser[]>([])

const geoUnitId = ref<number | null>(null)
const loading = ref(true)
const error = ref('')

/** Blank means the whole organisation, which is what most plans want. */
const assignTo = ref<string>('')

const assessors = computed(() =>
    users.value.filter(
        (user) => user.is_active && ['assessor', 'admin', 'superadmin'].includes(user.role),
    ),
)

const nameOf = computed(() => new Map(users.value.map((user) => [user.id, user.full_name])))

const facilityName = computed(() => new Map(facilities.value.map((f) => [f.id, f.name])))

/** Live assignments by site, so each row can show what is already true of it. */
const bySite = computed(() => {
    const map = new Map<string, Assignment[]>()

    for (const assignment of assignments.value) {
        if (!assignment.is_active) {
            continue
        }

        map.set(assignment.testing_site_id, [
            ...(map.get(assignment.testing_site_id) ?? []),
            assignment,
        ])
    }

    return map
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
        units.value = await listGeoUnits()
        users.value = await listUsers()
        assignments.value = await listAssignments()
    })

    await loadSites()
    loading.value = false
}

/**
 * Every site under the chosen place, across all of its facilities.
 *
 * The site endpoint filters by facility, so this walks the facilities in the
 * selection. It is one round trip per facility, which is fine for a district
 * and would not be for a country — bounded by the cascade rather than by luck.
 */
async function loadSites(): Promise<void> {
    await act(async () => {
        facilities.value = await listFacilities(geoUnitId.value)

        const lists = await Promise.all(
            facilities.value.map((facility) => listTestingSites(facility.id)),
        )

        sites.value = lists.flat()
    })
}

watch(geoUnitId, loadSites)

async function onAssign(site: RegistryTestingSite): Promise<void> {
    const userId = assignTo.value === '' ? null : Number(assignTo.value)

    const created = await act(() =>
        createAssignment({ testing_site_id: site.id, user_id: userId }),
    )

    if (created !== null) {
        assignments.value = await listAssignments()
    }
}

async function onWithdraw(assignment: Assignment): Promise<void> {
    const done = await act(() => withdrawAssignment(assignment.id))

    if (done !== null) {
        assignments.value = await listAssignments()
    }
}

function describe(assignment: Assignment): string {
    return assignment.user_id === null
        ? t('assignments.wholeOrganisation')
        : (nameOf.value.get(assignment.user_id) ?? t('admin.person'))
}

onMounted(load)
</script>

<template>
    <AdminShell :title="t('assignments.title')" :subtitle="t('assignments.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4 rounded-card bg-surface p-4">
            <GeoCascade v-model="geoUnitId" :units="units" />

            <label class="flex flex-col gap-1">
                <span class="text-[12px] uppercase tracking-wide text-label-2">
                    {{ t('assignments.assignTo') }}
                </span>
                <select
                    v-model="assignTo"
                    class="min-w-[190px] rounded-lg bg-ground px-3 py-2 text-[15px] outline-none"
                >
                    <option value="">{{ t('assignments.wholeOrganisation') }}</option>
                    <option v-for="user in assessors" :key="user.id" :value="String(user.id)">
                        {{ user.full_name }}
                    </option>
                </select>
            </label>
        </div>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="overflow-x-auto rounded-card bg-surface">
            <table class="w-full min-w-[640px] text-left">
                <thead>
                    <tr class="border-b border-hairline text-[12px] uppercase tracking-wide text-label-2">
                        <th class="px-4 py-2.5 font-semibold">{{ t('registry.testingSites') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ t('assignments.coveredBy') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ t('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="sites.length === 0">
                        <td colspan="3" class="px-4 py-3 text-[14px] text-label-2">
                            {{ t('assignments.noSites') }}
                        </td>
                    </tr>
                    <tr
                        v-for="site in sites"
                        :key="site.id"
                        class="border-b border-hairline last:border-0"
                    >
                        <td class="px-4 py-3">
                            <span class="block text-[15px]">{{ site.name }}</span>
                            <span class="block text-[13px] text-label-2">
                                {{ facilityName.get(site.facility_id) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="(bySite.get(site.id) ?? []).length === 0"
                                class="text-[14px] text-label-3"
                            >
                                {{ t('sites.unassigned') }}
                            </span>
                            <span
                                v-for="assignment in bySite.get(site.id) ?? []"
                                :key="assignment.id"
                                class="mr-2 inline-flex items-center gap-2 rounded-full bg-accent-soft px-2.5 py-1 text-[13px] text-accent"
                            >
                                {{ describe(assignment) }}
                                <button
                                    type="button"
                                    :aria-label="t('assignments.withdraw')"
                                    class="font-bold"
                                    @click="onWithdraw(assignment)"
                                >
                                    &times;
                                </button>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="text-[14px] text-accent"
                                @click="onAssign(site)"
                            >
                                {{ t('assignments.assign') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminShell>
</template>
