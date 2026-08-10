<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { type DashboardSummary, loadDashboard } from '@/api/admin'
import AdminShell from '@/components/admin/AdminShell.vue'
import EChart from '@/components/admin/EChart.vue'

/**
 * Leaflet arrives only when there is something to plot.
 *
 * The map is rendered behind a v-if on having any points at all, and an
 * ordinary import would still pull the library into this chunk for every
 * installation — including the many where no assessment carries a position
 * yet, which is all of them today. Asynchronous, so the cost is paid by the
 * screens that draw a map and by nobody else.
 */
const AuditMap = defineAsyncComponent(() => import('@/components/admin/AuditMap.vue'))
import ScoreBadge from '@/components/admin/ScoreBadge.vue'
import { locale, t } from '@/i18n'

/**
 * Where the country stands.
 *
 * The picture the predecessor produced and this tool could not: how many
 * laboratories have been audited, how they sit across the certification
 * levels, whether that is moving, and which part of the standard is dragging
 * the scores down.
 *
 * TWO KINDS OF CHART, AND THE SPLIT IS DELIBERATE. The distribution bars and
 * the month columns stay hand-drawn: they are a rectangle and a row of
 * rectangles, and a library adds nothing to either. The radar and the map are
 * not — a polygon on five axes and a tile map are exactly what a library is
 * for, and writing them by hand would be worse in every way that matters.
 *
 * Neither reaches the assessor. Every management route is a dynamic import, so
 * ECharts and Leaflet land in admin chunks and a phone in a clinic never
 * downloads them. That is what changed since the first version of this screen
 * argued against a library on weight — the weight was never going to be
 * shared, and the argument was wrong about which bundle it lands in.
 *
 * The remaining hand-drawn panels are as they were rather than a library, and that is a size decision
 * rather than a purist one. Two shapes are needed — a distribution bar and a
 * column series — and the smallest capable library is around seventy
 * kilobytes. This is one application, so that weight would also land on the
 * assessor's phone, which is the half that has to arrive over the connection
 * it exists to cope with. If the panels ever need drill-down, zoom or export,
 * a library becomes the right answer and this is easy to replace.
 *
 * Restraint is the whole design. Hairline borders, one accent, and the score
 * colours used only where they mean something — a dashboard that colours
 * everything says nothing, and these particular colours are a certification
 * level rather than decoration.
 *
 * SUBMITTED VISITS ONLY. A draft is a visit somebody is part-way through, and
 * counting one would report a laboratory as scoring 12% because eleven of
 * fifty-nine questions have been answered so far. Drafts get a count of their
 * own, because "eight started and not finished" is worth knowing — it is just
 * not a score.
 *
 * Every panel has an empty state that says what would fill it. A dashboard on
 * a new installation is mostly zeroes, and zeroes with no explanation read as
 * a broken screen rather than as a country that has not started yet.
 */

const router = useRouter()

const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)
const error = ref('')

async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        summary.value = await loadDashboard(locale.value)
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')
    } finally {
        loading.value = false
    }
}

/**
 * Red through green, matching ScoreBadge and the instrument's own reading of
 * the bands: 0 and 1 need remediation, 2 is partial, 3 and 4 are certifiable.
 * Two levels share each colour, separated by weight rather than by inventing
 * a fifth hue nobody could name.
 */
const TONE: Record<number, { fill: string; alpha: number }> = {
    0: { fill: 'var(--color-no)', alpha: 1 },
    1: { fill: 'var(--color-no)', alpha: 0.5 },
    2: { fill: 'var(--color-partial)', alpha: 1 },
    3: { fill: 'var(--color-yes)', alpha: 0.5 },
    4: { fill: 'var(--color-yes)', alpha: 1 },
}

const totals = computed(() => summary.value?.totals ?? null)

const scored = computed(() =>
    (summary.value?.levels ?? []).reduce((sum, band) => sum + band.count, 0),
)

const recentScored = computed(() =>
    (summary.value?.recent ?? []).reduce((sum, band) => sum + band.count, 0),
)

function bandLabel(level: number): string {
    return summary.value?.bands.find((band) => band.level === level)?.label ?? `Level ${level}`
}

/** Share of the distribution bar. A band nobody reached takes no width. */
function share(count: number, total: number): number {
    return total === 0 ? 0 : (count / total) * 100
}

const months = computed(() => summary.value?.months ?? [])

const busiestMonth = computed(() =>
    months.value.reduce((most, month) => Math.max(most, month.count), 0),
)

function columnHeight(count: number): number {
    // A month with one visit still has to be visible, so an occupied month
    // never draws shorter than a few pixels.
    return busiestMonth.value === 0
        ? 0
        : Math.max(count === 0 ? 0 : 4, (count / busiestMonth.value) * 100)
}

function monthLabel(month: string): string {
    const [year, index] = month.split('-')

    return new Date(Number(year), Number(index) - 1, 1).toLocaleDateString(locale.value, {
        month: 'short',
    })
}

function assessedOn(value: string): string {
    const parsed = new Date(value + 'T00:00:00')

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' })
}

const trendScored = computed(() =>
    (summary.value?.trend ?? []).reduce((sum, band) => sum + band.count, 0),
)

/**
 * The radar, as ECharts wants it.
 *
 * Sections come back weakest first, which is right for a list and wrong for a
 * radar: a polygon whose axes are sorted by value is a spiral, and the shape
 * stops being comparable between two countries or two rounds. Ordered by
 * section code here, so the same section is always in the same place.
 */
const radar = computed(() => {
    const sections = [...(summary.value?.sections ?? [])].sort((a, b) =>
        a.code.localeCompare(b.code, undefined, { numeric: true }),
    )

    return {
        tooltip: { trigger: 'item' },
        radar: {
            indicator: sections.map((section) => ({ name: section.name, max: 100 })),
            radius: '68%',
            axisName: { color: 'rgba(110,110,118,1)', fontSize: 11 },
            splitArea: { areaStyle: { color: ['rgba(118,118,128,0.04)', 'transparent'] } },
            axisLine: { lineStyle: { color: 'rgba(60,60,67,0.18)' } },
            splitLine: { lineStyle: { color: 'rgba(60,60,67,0.18)' } },
        },
        series: [
            {
                type: 'radar',
                name: t('dash.radar'),
                symbolSize: 4,
                lineStyle: { width: 2, color: '#0A6ECB' },
                itemStyle: { color: '#0A6ECB' },
                areaStyle: { color: 'rgba(10,110,203,0.14)' },
                data: [
                    {
                        value: sections.map((section) => section.mean),
                        name: t('dash.radar'),
                    },
                ],
            },
        ],
    }
})

/**
 * Drill into the reports list rather than into the panel.
 *
 * That screen already filters, searches and paginates properly, and its
 * filters live in the URL — so a band or a month is a link to a question
 * somebody can bookmark, rather than a second implementation of filtering that
 * has to be kept in step with the first.
 */
function showLevel(level: number): void {
    void router.push({ name: 'admin-reports', query: { level: String(level) } })
}

function showMonth(month: string): void {
    const [year, index] = month.split('-').map(Number)
    const last = new Date(Date.UTC(year!, index!, 0)).getUTCDate()

    void router.push({
        name: 'admin-reports',
        query: { from: `${month}-01`, to: `${month}-${String(last).padStart(2, '0')}` },
    })
}

function showAssessment(id: string): void {
    void router.push({ name: 'admin-report', params: { id } })
}

/** Weakest first, so the bar is a ranking rather than a rainbow. */
function sectionTone(mean: number): string {
    if (mean < 60) {
        return 'var(--color-no)'
    }

    return mean < 80 ? 'var(--color-partial)' : 'var(--color-yes)'
}

onMounted(load)

// Band and section names are rendered by the server in the language asked for.
// App strings re-render on a locale change by themselves; these do not.
watch(locale, () => load())
</script>

<template>
    <AdminShell
        :eyebrow="t('dash.eyebrow')"
        :title="t('dash.title')"
        :subtitle="t('dash.subtitle')"
    >
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <template v-else-if="summary !== null && totals !== null">
            <!-- Headline counts -->
            <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <p class="eyebrow text-label-3">{{ t('dash.assessments') }}</p>
                    <p class="tnum mt-2 text-[34px] font-semibold leading-none tracking-[-0.02em]">
                        {{ totals.assessments }}
                    </p>
                </div>

                <div class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <p class="eyebrow text-label-3">{{ t('dash.sites') }}</p>
                    <p class="tnum mt-2 text-[34px] font-semibold leading-none tracking-[-0.02em]">
                        {{ totals.sites }}
                    </p>
                    <p class="mt-2 text-[13px] text-label-3">
                        {{ t('dash.ofRegistry', { total: totals.known_sites }) }}
                    </p>
                </div>

                <div class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <p class="eyebrow text-label-3">{{ t('dash.facilities') }}</p>
                    <p class="tnum mt-2 text-[34px] font-semibold leading-none tracking-[-0.02em]">
                        {{ totals.facilities }}
                    </p>
                </div>

                <div class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <p class="eyebrow text-label-3">{{ t('dash.drafts') }}</p>
                    <p class="tnum mt-2 text-[34px] font-semibold leading-none tracking-[-0.02em]">
                        {{ totals.drafts }}
                    </p>
                </div>
            </div>

            <div class="mb-4 grid gap-4 lg:grid-cols-2">
                <!-- Certification levels, over three horizons -->
                <section class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                        {{ t('dash.levels') }}
                    </h2>

                    <p v-if="scored === 0" class="mt-3 text-[14px] text-label-2">
                        {{ t('dash.nothing') }}
                    </p>

                    <template v-else>
                        <div class="mt-4 flex flex-col gap-3">
                            <div
                                v-for="horizon in [
                                    { key: 'all', label: t('dash.allTime'), rows: summary.levels, total: scored },
                                    { key: 'trend', label: t('dash.trend'), rows: summary.trend, total: trendScored },
                                    { key: 'recent', label: t('dash.levelsRecent'), rows: summary.recent, total: recentScored },
                                ]"
                                :key="horizon.key"
                            >
                                <div class="mb-1 flex items-baseline justify-between gap-3">
                                    <span class="text-[12px] text-label-3">{{ horizon.label }}</span>
                                    <span class="tnum text-[12px] text-label-3">{{ horizon.total }}</span>
                                </div>

                                <div
                                    v-if="horizon.total > 0"
                                    class="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full"
                                >
                                    <button
                                        v-for="band in horizon.rows.filter((row) => row.count > 0)"
                                        :key="band.level"
                                        type="button"
                                        class="h-full first:rounded-l-full last:rounded-r-full"
                                        :style="{
                                            width: `${share(band.count, horizon.total)}%`,
                                            background: TONE[band.level]!.fill,
                                            opacity: TONE[band.level]!.alpha,
                                        }"
                                        :title="`${bandLabel(band.level)}: ${band.count}`"
                                        @click="showLevel(band.level)"
                                    ></button>
                                </div>
                                <div v-else class="h-2.5 w-full rounded-full bg-track"></div>
                            </div>
                        </div>

                        <ul class="mt-5">
                            <li
                                v-for="band in summary.levels"
                                :key="band.level"
                                class="border-b border-hairline last:border-0"
                            >
                                <button
                                    type="button"
                                    class="-mx-2 flex w-[calc(100%+1rem)] items-center gap-2.5 rounded-card px-2 py-2 text-left text-[14px] hover:bg-accent-soft"
                                    :class="band.count === 0 ? 'text-label-3' : ''"
                                    :disabled="band.count === 0"
                                    @click="showLevel(band.level)"
                                >
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :style="{
                                            background: TONE[band.level]!.fill,
                                            opacity: band.count === 0 ? 0.25 : TONE[band.level]!.alpha,
                                        }"
                                    ></span>
                                    <span class="flex-1">{{ bandLabel(band.level) }}</span>
                                    <span class="tnum font-medium">{{ band.count }}</span>
                                </button>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- Section profile -->
                <section class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <h2 class="text-[15px] font-semibold tracking-[-0.01em]">{{ t('dash.radar') }}</h2>
                    <p class="mt-1 text-[13px] text-label-3">{{ t('dash.radarHelp') }}</p>

                    <p v-if="summary.sections.length < 3" class="mt-3 text-[14px] text-label-2">
                        {{ t('dash.noSections') }}
                    </p>

                    <EChart
                        v-else
                        :option="radar"
                        height="300px"
                        :aria-label="t('dash.radar')"
                        class="mt-2"
                    />
                </section>
            </div>

            <!-- Assessments by month -->
            <section class="mb-4 rounded-surface border border-hairline bg-surface px-5 py-5">
                <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                    {{ t('dash.overTime') }}
                </h2>

                <div class="mt-5 flex items-end gap-2" style="height: 132px">
                    <button
                        v-for="month in months"
                        :key="month.month"
                        type="button"
                        class="flex h-full flex-1 flex-col justify-end rounded-t disabled:cursor-default"
                        :disabled="month.count === 0"
                        :title="
                            month.count === 0
                                ? `${month.month}: 0`
                                : `${month.month}: ${month.count} · ${t('dash.mean', { value: month.mean ?? 0 })}`
                        "
                        @click="showMonth(month.month)"
                    >
                        <span
                            v-if="month.count > 0"
                            class="tnum mb-1.5 text-center text-[12px] font-medium text-label-2"
                        >
                            {{ month.count }}
                        </span>
                        <span
                            class="block rounded-md"
                            :class="month.count === 0 ? 'bg-track' : 'bg-accent'"
                            :style="{
                                height: month.count === 0 ? '4px' : `${columnHeight(month.count)}%`,
                            }"
                        ></span>
                    </button>
                </div>

                <div class="mt-2 flex gap-2 border-t border-hairline pt-2">
                    <span
                        v-for="month in months"
                        :key="month.month"
                        class="flex-1 text-center text-[11px] text-label-3"
                    >
                        {{ monthLabel(month.month) }}
                    </span>
                </div>
            </section>

            <!-- Where the visits happened -->
            <section class="mb-4 rounded-surface border border-hairline bg-surface px-5 py-5">
                <h2 class="text-[15px] font-semibold tracking-[-0.01em]">{{ t('dash.map') }}</h2>

                <p v-if="summary.map.length === 0" class="mt-3 text-[14px] text-label-2">
                    {{ t('dash.mapEmpty') }}
                </p>

                <template v-else>
                    <AuditMap class="mt-4" :points="summary.map" @pick="showAssessment" />

                    <div class="mt-3 flex flex-wrap items-center gap-4 text-[12px] text-label-3">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2.5 rounded-full bg-label-3"></span>
                            {{ t('map.device') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="size-2.5 rounded-full border-2 border-label-3"
                            ></span>
                            {{ t('map.facility') }}
                        </span>
                    </div>
                </template>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- Weakest sections -->
                <section class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                        {{ t('dash.sections') }}
                    </h2>
                    <p class="mt-1 text-[13px] text-label-3">{{ t('dash.sectionsHelp') }}</p>

                    <p v-if="summary.sections.length === 0" class="mt-3 text-[14px] text-label-2">
                        {{ t('dash.noSections') }}
                    </p>

                    <ul v-else class="mt-4 flex flex-col gap-3.5">
                        <li v-for="section in summary.sections" :key="section.code">
                            <div class="mb-1.5 flex items-baseline gap-3 text-[14px]">
                                <span class="min-w-0 flex-1 truncate">{{ section.name }}</span>
                                <span class="tnum font-semibold">{{ section.mean }}%</span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-track">
                                <div
                                    class="h-full rounded-full"
                                    :style="{
                                        width: `${section.mean}%`,
                                        background: sectionTone(section.mean),
                                    }"
                                ></div>
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Latest -->
                <section class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                            {{ t('dash.latest') }}
                        </h2>
                        <RouterLink
                            :to="{ name: 'admin-reports' }"
                            class="text-[13px] font-medium text-accent"
                        >
                            {{ t('dash.viewAll') }}
                        </RouterLink>
                    </div>

                    <p v-if="summary.latest.length === 0" class="mt-3 text-[14px] text-label-2">
                        {{ t('dash.nothing') }}
                    </p>

                    <ul v-else class="mt-2">
                        <li
                            v-for="row in summary.latest"
                            :key="row.id"
                            class="border-b border-hairline last:border-0"
                        >
                            <RouterLink
                                :to="{ name: 'admin-report', params: { id: row.id } }"
                                class="-mx-2 flex items-center gap-3 rounded-card px-2 py-2.5 hover:bg-accent-soft"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px]">
                                        {{ row.site ?? row.facility }}
                                    </span>
                                    <span class="block text-[12px] text-label-3">
                                        {{ assessedOn(row.assessed_on) }}
                                    </span>
                                </span>
                                <span
                                    v-if="row.percentage !== null"
                                    class="tnum text-[14px] font-semibold"
                                >
                                    {{ row.percentage }}%
                                </span>
                                <ScoreBadge :level="row.level" />
                            </RouterLink>
                        </li>
                    </ul>
                </section>
            </div>
        </template>
    </AdminShell>
</template>
