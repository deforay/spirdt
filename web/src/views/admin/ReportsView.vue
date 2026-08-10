<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { type AssessmentRow, listAssessments } from '@/api/reports'
import { type GeoTree, listGeoUnits } from '@/api/registry'
import AdminShell from '@/components/admin/AdminShell.vue'
import PagedList from '@/components/admin/PagedList.vue'
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
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-start gap-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('reports.search')"
                class="min-w-[220px] flex-1 rounded-lg bg-surface px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <div class="min-w-[240px]">
                <PlacePicker
                    v-model="geoUnitId"
                    :tree="tree"
                    :placeholder="t('facilities.anywhere')"
                />
            </div>
            <select
                v-model="status"
                class="rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
            >
                <option value="">{{ t('reports.anyStatus') }}</option>
                <option value="draft">{{ t('reports.statusDraft') }}</option>
                <option value="submitted">{{ t('reports.statusSubmitted') }}</option>
                <option value="reviewed">{{ t('reports.statusReviewed') }}</option>
                <option value="finalised">{{ t('reports.statusFinalised') }}</option>
            </select>
            <select
                v-model="level"
                class="rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
            >
                <option value="">{{ t('reports.anyLevel') }}</option>
                <option v-for="n in [0, 1, 2, 3, 4]" :key="n" :value="String(n)">
                    {{ t('score.level', { level: n }) }}
                </option>
            </select>
            <label class="flex items-center gap-2 text-[13px] text-label-2">
                {{ t('reports.from') }}
                <input
                    v-model="from"
                    type="date"
                    class="rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
                />
            </label>
            <label class="flex items-center gap-2 text-[13px] text-label-2">
                {{ t('reports.to') }}
                <input
                    v-model="to"
                    type="date"
                    class="rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
                />
            </label>
        </div>

        <div class="overflow-hidden rounded-card bg-surface">
            <p v-if="!loading && rows.length === 0" class="px-4 py-3 text-[14px] text-label-2">
                {{ t('reports.nothingYet') }}
            </p>
            <RouterLink
                v-for="(row, index) in rows"
                :key="row.id"
                :to="{ name: 'admin-report', params: { id: row.id } }"
                class="flex items-center justify-between gap-3 px-4 py-2.5"
                :class="index > 0 ? 'border-t border-hairline' : ''"
            >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] hover:text-accent">
                        {{ row.site ?? t('reports.siteGone') }}
                    </span>
                    <span class="block truncate text-[12px] text-label-2">
                        {{ row.facility }}
                        <template v-if="row.place"> · {{ row.place }}</template>
                    </span>
                </span>

                <span class="shrink-0 text-right">
                    <span class="tnum block text-[13px] text-label-2">{{ row.assessed_on }}</span>
                    <span class="block text-[12px] text-label-3">
                        <template v-if="row.status !== 'submitted'">{{ row.status }}</template>
                    </span>
                </span>

                <span class="w-[112px] shrink-0 text-right">
                    <template v-if="row.percentage !== null">
                        <span class="tnum block text-[15px] font-semibold">
                            {{ formatPercent(row.percentage, 2) }}
                        </span>
                        <ScoreBadge :level="row.level" />
                    </template>
                    <span v-else class="text-[12px] text-label-3">{{ t('reports.notScoredYet') }}</span>
                </span>
            </RouterLink>
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
