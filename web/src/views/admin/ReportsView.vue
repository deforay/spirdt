<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { type AssessmentRow, listAssessments } from '@/api/reports'
import { type GeoTree, listGeoUnits } from '@/api/registry'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
import PdfDownload from '@/components/admin/PdfDownload.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import ScoreBadge from '@/components/admin/ScoreBadge.vue'
import { formatPercent, t } from '@/i18n'

/**
 * Every visit, newest first.
 *
 * This is the first screen in the application that shows collected data rather
 * than the things data is collected about, and it is what management signs in
 * for. It filters the same way the registry does — by place, by subtree — so
 * that "how is Copperbelt doing" is one control rather than a mental join.
 *
 * A draft with no score yet still appears. An assessment that stayed invisible
 * until it was finished would be missing in exactly the state somebody is
 * chasing it in.
 *
 * THE FILTERS LIVE IN THE URL. That is what makes this screen the place the
 * dashboard drills into — a band, a section or a month becomes a link rather
 * than a second implementation of filtering — and it is also what makes a
 * filtered view something somebody can bookmark or send to a colleague, which
 * a set of controls holding their state in memory never is.
 */

const tree = ref<GeoTree>({ units: [], paths: {} })
const rows = ref<AssessmentRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)

const route = useRoute()
const router = useRouter()

/** Seeded from the URL, so arriving from a chart lands on the same question. */
const geoUnitId = ref<number | null>(
    typeof route.query.place === 'string' ? Number(route.query.place) : null,
)
const search = ref(typeof route.query.q === 'string' ? route.query.q : '')
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const level = ref<string>(typeof route.query.level === 'string' ? route.query.level : '')
const from = ref(typeof route.query.from === 'string' ? route.query.from : '')
const to = ref(typeof route.query.to === 'string' ? route.query.to : '')

const loading = ref(true)
const error = ref('')

async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        const result = await listAssessments({
            geoUnitId: geoUnitId.value,
            search: search.value,
            status: status.value,
            // Level 0 is a real band, so the empty string is the only "any".
            level: level.value === '' ? null : Number(level.value),
            from: from.value,
            to: to.value,
            page: page.value,
            perPage: perPage.value,
        })

        rows.value = result.rows
        total.value = result.total
        perPage.value = result.per_page
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
    }

    loading.value = false
}

let timer: ReturnType<typeof setTimeout> | undefined

/**
 * Keep the address bar honest.
 *
 * Replaced rather than pushed: typing in the search box should not fill the
 * history with one entry per keystroke, and Back from here should leave the
 * screen rather than undo a character.
 */
function syncUrl(): void {
    const query: Record<string, string> = {}

    if (geoUnitId.value !== null) {
        query.place = String(geoUnitId.value)
    }
    if (search.value !== '') {
        query.q = search.value
    }
    if (status.value !== '') {
        query.status = status.value
    }
    // Level 0 is a real band, so only the empty string means "any".
    if (level.value !== '') {
        query.level = level.value
    }
    if (from.value !== '') {
        query.from = from.value
    }
    if (to.value !== '') {
        query.to = to.value
    }

    void router.replace({ query })
}

watch([search, geoUnitId, status, level, from, to], () => {
    page.value = 1
    syncUrl()
    clearTimeout(timer)
    timer = setTimeout(load, 250)
})

watch(page, load)

onMounted(async () => {
    try {
        tree.value = await listGeoUnits()
    } catch {
        // The place filter is a convenience. Losing it should not take the
        // list down with it.
    }

    await load()
})
</script>

<template>
    <AdminShell :title="t('reports.title')" :subtitle="t('reports.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-start gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('reports.search')"
                class="field min-w-[260px] flex-1"
            />
            <div class="min-w-[240px]">
                <PlacePicker
                    v-model="geoUnitId"
                    :tree="tree"
                    :placeholder="t('facilities.anywhere')"
                />
            </div>

            <select
                v-model="level"
                class="field w-auto"
            >
                <option value="">{{ t('reports.anyLevel') }}</option>
                <option v-for="n in [0, 1, 2, 3, 4]" :key="n" :value="String(n)">
                    {{ t('score.level', { level: n }) }}
                </option>
            </select>
            <label class="flex items-center gap-2 text-[14px] text-label-2">
                {{ t('reports.from') }}
                <input
                    v-model="from"
                    type="date"
                    class="field w-auto"
                />
            </label>
            <label class="flex items-center gap-2 text-[14px] text-label-2">
                {{ t('reports.to') }}
                <input
                    v-model="to"
                    type="date"
                    class="field w-auto"
                />
            </label>
        </div>

        <div
            class="mb-4 flex flex-wrap gap-1 rounded-card border border-hairline bg-surface p-1"
            role="tablist"
        >
            <button
                v-for="tab in [
                    { key: '', label: t('reports.anyStatus') },
                    { key: 'draft', label: t('reports.statusDraft') },
                    { key: 'submitted', label: t('reports.statusSubmitted') },
                    { key: 'reviewed', label: t('reports.statusReviewed') },
                    { key: 'finalised', label: t('reports.statusFinalised') },
                ]"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="status === tab.key"
                :class="[
                    'flex min-h-11 items-center gap-2 rounded-card px-4 text-[14.5px] font-medium transition-colors',
                    status === tab.key
                        ? 'bg-accent-soft text-accent'
                        : 'text-label-2 hover:bg-surface-2 hover:text-label',
                ]"
                @click="status = tab.key"
            >
                {{ tab.label }}
                <!-- The count belongs to the filter that is running, so it is
                     shown on the tab that produced it and nowhere else. A
                     number under every tab would mean five queries per page
                     load to fill them. -->
                <span
                    v-if="status === tab.key && !loading"
                    class="tnum rounded-full bg-accent px-2 py-0.5 text-[12px] font-semibold text-accent-ink"
                >
                    {{ total }}
                </span>
            </button>
        </div>

        <div class="data-card data-scroll">
            <table class="data-table min-w-[960px]">
                <thead>
                    <tr>
                        <th>{{ t('registry.siteName') }}</th>
                        <th>{{ t('registry.facilityName') }}</th>
                        <th>{{ t('report.assessedOn') }}</th>
                        <th>{{ t('reports.round') }}</th>
                        <th>{{ t('admin.status') }}</th>
                        <th class="text-right">{{ t('report.overall') }}</th>
                        <!-- Unnamed: the button in the cell says what it is,
                             and a column heading over one control is a word
                             the eye has to read on every row to ignore. -->
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="7" class="text-label-2">{{ t('reports.nothingYet') }}</td>
                    </tr>

                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <RouterLink
                                :to="{ name: 'admin-report', params: { id: row.id } }"
                                class="font-medium hover:text-accent"
                            >
                                {{ row.site ?? t('reports.siteGone') }}
                            </RouterLink>
                        </td>
                        <td class="text-label-2">
                            {{ row.facility }}
                            <span v-if="row.place" class="block text-[13px] text-label-3">
                                {{ row.place }}
                            </span>
                        </td>
                        <td class="tnum text-label-2">{{ row.assessed_on }}</td>
                        <!-- An em dash rather than an empty cell. A blank in a
                             table reads as a rendering fault; this says the
                             round was not recorded, which for every audit
                             filed before the field existed is the truth. -->
                        <td class="text-label-2">{{ row.audit_round || '—' }}</td>
                        <td>
                            <!-- A draft is a state of the document, so it is
                                 stated. Everything else is the ordinary case
                                 and does not need a badge to say so. -->
                            <span
                                :class="[
                                    'chip',
                                    row.status === 'draft'
                                        ? 'bg-brass-soft text-brass'
                                        : 'bg-accent-soft text-accent',
                                ]"
                            >
                                {{ row.status }}
                            </span>
                        </td>
                        <td class="text-right">
                            <template v-if="row.percentage !== null">
                                <span class="tnum block text-[16px] font-semibold">
                                    {{ formatPercent(row.percentage, 2) }}
                                </span>
                                <ScoreBadge :level="row.level" />
                            </template>
                            <span v-else class="text-[13px] text-label-3">
                                {{ t('reports.notScoredYet') }}
                            </span>
                        </td>
                        <!-- Every audit, downloadable from the list it is in.
                             The alternative is opening each one to get at the
                             file, which for a quarter's worth of visits is the
                             difference between a task and an afternoon. -->
                        <td class="text-right">
                            <PdfDownload :assessment-id="row.id" compact />
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
