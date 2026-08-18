<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { type AdminUser, listUsers } from '@/api/admin'
import {
    type Assignment,
    createAssignment,
    type GeoTree,
    listAssignments,
    listGeoUnits,
    listTestingSites,
    type RegistryTestingSite,
    withdrawAssignment,
} from '@/api/registry'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
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

const tree = ref<GeoTree>({ units: [], paths: {} })
const sites = ref<RegistryTestingSite[]>([])
const assignments = ref<Assignment[]>([])
const users = ref<AdminUser[]>([])

const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const geoUnitId = ref<number | null>(null)
const search = ref('')
const loading = ref(true)
const error = ref('')

/** Ticked rows, so a district can be handed over in one action. */
const selected = ref(new Set<string>())

/** Blank means the whole organisation, which is what most plans want. */
const assignTo = ref<string>('')

const assessors = computed(() =>
    users.value.filter(
        (user) => user.is_active && ['assessor', 'admin', 'superadmin'].includes(user.role),
    ),
)

const nameOf = computed(() => new Map(users.value.map((user) => [user.id, user.full_name])))

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
        tree.value = await listGeoUnits()
        users.value = await listUsers()
        assignments.value = await listAssignments()
    })

    await loadSites()
    loading.value = false
}

/**
 * Every site under the chosen place, in ONE request.
 *
 * This used to fetch the place's facilities and then ask per facility. In a
 * district with two hundred facilities that was two hundred requests to fill
 * one table, and it got worse the more real the data became.
 */
async function loadSites(): Promise<void> {
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

watch([geoUnitId, search], () => {
    page.value = 1
    selected.value = new Set()
    clearTimeout(timer)
    timer = setTimeout(loadSites, 250)
})

watch(page, loadSites)

function toggleSelected(id: string): void {
    const next = new Set(selected.value)

    next.has(id) ? next.delete(id) : next.add(id)
    selected.value = next
}

function selectAllOnPage(): void {
    selected.value =
        selected.value.size === sites.value.length
            ? new Set()
            : new Set(sites.value.map((site) => site.id))
}

/**
 * Hand over everything ticked at once.
 *
 * Assigning a district site by site through a paginated table is the kind of
 * task somebody does once and then stops using the tool for.
 */
async function onAssignSelected(): Promise<void> {
    const userId = assignTo.value === '' ? null : Number(assignTo.value)

    for (const siteId of selected.value) {
        const done = await act(() => createAssignment({ testing_site_id: siteId, user_id: userId }))

        if (done === null) {
            break
        }
    }

    selected.value = new Set()
    assignments.value = await listAssignments()
}

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
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4 rounded-surface border border-hairline bg-surface p-5">
            <input
                v-model="search"
                type="search"
                :placeholder="t('sitesAdmin.search')"
                class="min-w-[220px] flex-1 rounded-lg bg-ground px-3 py-2 text-[16px] outline-none placeholder:text-label-3"
            />
            <div class="min-w-[240px]">
                <PlacePicker v-model="geoUnitId" :tree="tree" :placeholder="t('facilities.anywhere')" />
            </div>

            <label class="flex flex-col gap-1">
                <span class="text-[13px] uppercase tracking-wide text-label-2">
                    {{ t('assignments.assignTo') }}
                </span>
                <select
                    v-model="assignTo"
                    class="min-w-[190px] rounded-lg bg-ground px-3 py-2 text-[16px] outline-none"
                >
                    <option value="">{{ t('assignments.wholeOrganisation') }}</option>
                    <option v-for="user in assessors" :key="user.id" :value="String(user.id)">
                        {{ user.full_name }}
                    </option>
                </select>
            </label>
        </div>

        <div
            v-if="selected.size > 0"
            class="mb-3 flex items-center justify-between gap-3 rounded-card bg-accent-soft px-5 py-3.5"
        >
            <span class="text-[15px] font-medium text-accent">
                {{ t('assignments.selected', { count: selected.size }) }}
            </span>
            <button
                type="button"
                class="rounded-full bg-accent px-4 py-1.5 text-[14px] font-semibold text-accent-ink"
                @click="onAssignSelected"
            >
                {{ t('assignments.assignSelected') }}
            </button>
        </div>

        <div class="data-card data-scroll">
            <table class="data-table min-w-[640px]">
                <thead>
                    <tr>
                        <th>
                            <input
                                type="checkbox"
                                class="mr-2 align-middle"
                                :checked="selected.size > 0 && selected.size === sites.length"
                                :aria-label="t('assignments.selectAll')"
                                @change="selectAllOnPage"
                            />
                            {{ t('registry.testingSites') }}
                        </th>
                        <th>{{ t('assignments.coveredBy') }}</th>
                        <th class="text-right">{{ t('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="sites.length === 0">
                        <td colspan="3" class="text-[15px] text-label-2">
                            {{ t('assignments.noSites') }}
                        </td>
                    </tr>
                    <tr
                        v-for="site in sites"
                        :key="site.id"
                    >
                        <td>
                            <label class="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    class="mt-1"
                                    :checked="selected.has(site.id)"
                                    @change="toggleSelected(site.id)"
                                />
                                <span class="min-w-0">
                                    <span class="block truncate text-[16px]">{{ site.name }}</span>
                                    <span class="block truncate text-[14px] text-label-2">
                                        {{ site.facility_name
                                        }}<template v-if="site.place"> · {{ site.place }}</template>
                                    </span>
                                </span>
                            </label>
                        </td>
                        <td>
                            <span
                                v-if="(bySite.get(site.id) ?? []).length === 0"
                                class="text-[15px] text-label-3"
                            >
                                {{ t('sites.unassigned') }}
                            </span>
                            <span
                                v-for="assignment in bySite.get(site.id) ?? []"
                                :key="assignment.id"
                                class="mr-2 inline-flex items-center gap-2 rounded-full bg-accent-soft px-2.5 py-1 text-[14px] text-accent"
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
                        <td class="text-right">
                            <button
                                type="button"
                                class="text-[15px] text-accent"
                                @click="onAssign(site)"
                            >
                                {{ t('assignments.assign') }}
                            </button>
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
