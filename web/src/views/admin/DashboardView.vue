<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { type DashboardSummary, loadDashboard } from '@/api/admin'
import { type GeoTree, listGeoUnits } from '@/api/registry'
import AdminShell from '@/components/admin/AdminShell.vue'
import EChart from '@/components/admin/EChart.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'

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
const tree = ref<GeoTree>({ units: [], paths: {} })
const loading = ref(true)
const error = ref('')

/**
 * The period, as a named window rather than two dates.
 *
 * "Last 90 days" is the question people actually ask, and making them pick two
 * dates to express it is how a filter goes unused. The two dates are still
 * there for the case a preset cannot express — a quarter, a campaign, the
 * window somebody is writing a report about.
 */
const RANGES: Array<{ key: string; days: number | null }> = [
    { key: 'dash.rangeAll', days: null },
    { key: 'dash.range30', days: 30 },
    { key: 'dash.range90', days: 90 },
    { key: 'dash.range180', days: 180 },
    { key: 'dash.range365', days: 365 },
]

const range = ref('dash.rangeAll')
const from = ref('')
const to = ref('')
const place = ref<number | null>(null)

function applyRange(key: string): void {
    range.value = key

    const days = RANGES.find((entry) => entry.key === key)?.days ?? null

    if (days === null) {
        from.value = ''
        to.value = ''

        return
    }

    const start = new Date()
    start.setDate(start.getDate() - days)

    from.value = start.toISOString().slice(0, 10)
    to.value = new Date().toISOString().slice(0, 10)
}

const filtered = computed(() => from.value !== '' || to.value !== '' || place.value !== null)

function clearFilters(): void {
    range.value = 'dash.rangeAll'
    from.value = ''
    to.value = ''
    place.value = null
}

/** Which load is current, so a slow one cannot overwrite a newer answer. */
let generation = 0

async function load(): Promise<void> {
    const mine = ++generation

    loading.value = true
    error.value = ''

    try {
        const body = await loadDashboard(locale.value, {
            from: from.value,
            to: to.value,
            place: place.value,
        })

        if (mine !== generation) {
            return
        }

        summary.value = body
    } catch (caught) {
        if (mine !== generation) {
            return
        }

        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')
    } finally {
        if (mine === generation) {
            loading.value = false
        }
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
 * A distribution as a doughnut, which is the shape people arrive expecting.
 *
 * The predecessor put three of these across the top of its dashboard and that
 * is what a country's team has been reading for years. The legend carries the
 * share AND the count, because a percentage on its own is unreadable at small
 * numbers — "20%" of five visits is one laboratory, and a plan made from the
 * percentage alone would be a plan for a fifth of the country.
 *
 * Empty bands are dropped from the ring and kept in the legend beneath it, so
 * the shape stays honest while the scale stays visible.
 */
function donut(rows: Array<{ level: number; count: number }>, total: number) {
    return {
        tooltip: {
            trigger: 'item',
            formatter: (params: { name?: string; value?: number; percent?: number }) =>
                `${params.name ?? ''}<br><strong>${params.value ?? 0}</strong> (${params.percent ?? 0}%)`,
        },
        toolbox: {
            right: 0,
            top: 0,
            feature: { saveAsImage: { title: t('dash.saveImage'), pixelRatio: 2 } },
            iconStyle: { borderColor: 'rgba(110,110,118,0.9)' },
        },
        series: [
            {
                type: 'pie',
                radius: ['54%', '78%'],
                center: ['50%', '52%'],
                avoidLabelOverlap: true,
                label: { show: false },
                labelLine: { show: false },
                itemStyle: { borderColor: 'transparent', borderWidth: 2 },
                data: rows
                    .filter((row) => row.count > 0)
                    .map((row) => ({
                        name: bandLabel(row.level),
                        value: row.count,
                        level: row.level,
                        itemStyle: {
                            color: TONE[row.level]!.fill,
                            opacity: TONE[row.level]!.alpha,
                        },
                    })),
            },
        ],
        // Kept so an empty period draws an empty ring rather than nothing at
        // all, which reads as a chart that failed to load.
        graphic:
            total === 0
                ? [
                      {
                          type: 'circle',
                          left: 'center',
                          top: 'middle',
                          shape: { r: 46 },
                          style: { fill: 'transparent', stroke: 'rgba(118,118,128,0.18)', lineWidth: 14 },
                      },
                  ]
                : [],
    }
}

/**
 * What the panel beside it is actually showing.
 *
 * The predecessor printed the from date, the to date and the number of audits
 * under every distribution, and it is the most quietly useful thing on that
 * screen: a percentage with no denominator and no period is a number somebody
 * will quote in a meeting.
 */
function windowFor(days: number | null): { from: string; to: string } {
    if (days === null) {
        return { from: from.value === '' ? t('dash.beginning') : from.value, to: to.value === '' ? t('dash.today') : to.value }
    }

    const start = new Date()
    start.setDate(start.getDate() - days)

    const floor = from.value === '' ? start : new Date(Math.max(start.getTime(), new Date(from.value).getTime()))

    return {
        from: floor.toISOString().slice(0, 10),
        to: to.value === '' ? t('dash.today') : to.value,
    }
}

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
/** The screen's own filters travel with the drill, or it answers a wider question. */
function drillQuery(extra: Record<string, string>): Record<string, string> {
    const query: Record<string, string> = { ...extra }

    if (place.value !== null) {
        query.place = String(place.value)
    }

    if (from.value !== '' && extra.from === undefined) {
        query.from = from.value
    }

    if (to.value !== '' && extra.to === undefined) {
        query.to = to.value
    }

    return query
}

function showLevel(level: number): void {
    void router.push({ name: 'admin-reports', query: drillQuery({ level: String(level) }) })
}

function showMonth(month: string): void {
    const [year, index] = month.split('-').map(Number)
    const last = new Date(Date.UTC(year!, index!, 0)).getUTCDate()

    void router.push({
        name: 'admin-reports',
        query: drillQuery({
            from: `${month}-01`,
            to: `${month}-${String(last).padStart(2, '0')}`,
        }),
    })
}

function showAssessment(id: string): void {
    void router.push({ name: 'admin-report', params: { id } })
}

/** "View data" on a panel: the same rows, in the screen built to list them. */
function showAll(days: number | null): void {
    const query = drillQuery({})

    if (days !== null) {
        const start = new Date()
        start.setDate(start.getDate() - days)
        query.from = start.toISOString().slice(0, 10)
        query.to = new Date().toISOString().slice(0, 10)
    }

    void router.push({ name: 'admin-reports', query })
}

/** A band's share of its own window, for the legend beside the ring. */
function percent(count: number, total: number): string {
    return total === 0 ? '0%' : `${Math.round((count / total) * 1000) / 10}%`
}

/** Weakest first, so the bar is a ranking rather than a rainbow. */
function sectionTone(mean: number): string {
    if (mean < 60) {
        return 'var(--color-no)'
    }

    return mean < 80 ? 'var(--color-partial)' : 'var(--color-yes)'
}

onMounted(async () => {
    try {
        tree.value = await listGeoUnits()
    } catch {
        // The place filter simply will not offer anything. Every other panel
        // still works, and a dashboard that refuses to draw because one
        // control could not populate is worse than one filter short.
    }

    await load()
})

watch([from, to, place], () => void load())

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
        <!-- Filters. Every panel below answers the question these ask. -->
        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1.5">
                <span class="eyebrow text-label-3">{{ t('dash.range') }}</span>
                <div class="inline-flex rounded-full border border-hairline bg-surface p-0.5">
                    <button
                        v-for="entry in RANGES"
                        :key="entry.key"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-[13px] font-medium"
                        :class="
                            range === entry.key
                                ? 'bg-accent-soft text-accent'
                                : 'text-label-2 hover:text-label'
                        "
                        @click="applyRange(entry.key)"
                    >
                        {{ t(entry.key as 'dash.rangeAll') }}
                    </button>
                </div>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="eyebrow text-label-3">{{ t('audit.from') }}</span>
                <input
                    v-model="from"
                    type="date"
                    class="rounded-card border border-hairline bg-surface px-3 py-2 text-[14px] outline-none"
                    @change="range = 'dash.rangeCustom'"
                />
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="eyebrow text-label-3">{{ t('audit.to') }}</span>
                <input
                    v-model="to"
                    type="date"
                    class="rounded-card border border-hairline bg-surface px-3 py-2 text-[14px] outline-none"
                    @change="range = 'dash.rangeCustom'"
                />
            </label>

            <div class="flex min-w-[240px] flex-col gap-1.5">
                <span class="eyebrow text-label-3">{{ t('dash.place') }}</span>
                <PlacePicker v-model="place" :tree="tree" :placeholder="t('dash.everywhere')" />
            </div>

            <button
                v-if="filtered"
                type="button"
                class="rounded-full px-3 py-2 text-[13px] font-medium text-accent"
                @click="clearFilters"
            >
                {{ t('dash.clear') }}
            </button>
        </div>

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

            <!-- Three horizons, the shape a country's team already reads -->
            <div class="mb-4 grid gap-4 lg:grid-cols-3">
                <section
                    v-for="horizon in [
                        { key: 'all', label: t('dash.allTime'), rows: summary.levels, total: scored, days: null },
                        { key: 'trend', label: t('dash.trend'), rows: summary.trend, total: trendScored, days: 180 },
                        { key: 'recent', label: t('dash.levelsRecent'), rows: summary.recent, total: recentScored, days: 30 },
                    ]"
                    :key="horizon.key"
                    class="flex flex-col rounded-surface border border-hairline bg-surface px-5 py-5"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="text-[15px] font-semibold tracking-[-0.01em]">{{ horizon.label }}</h2>
                        <button
                            type="button"
                            class="text-[13px] font-medium text-accent disabled:text-label-3"
                            :disabled="horizon.total === 0"
                            @click="showAll(horizon.days)"
                        >
                            {{ t('dash.viewData') }}
                        </button>
                    </div>

                    <EChart
                        :option="donut(horizon.rows, horizon.total)"
                        height="190px"
                        :aria-label="horizon.label"
                        class="mt-1"
                    />

                    <ul class="mt-1">
                        <li
                            v-for="band in horizon.rows"
                            :key="band.level"
                            class="flex items-center gap-2 py-[3px] text-[12.5px]"
                            :class="band.count === 0 ? 'text-label-3' : ''"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :style="{
                                    background: TONE[band.level]!.fill,
                                    opacity: band.count === 0 ? 0.25 : TONE[band.level]!.alpha,
                                }"
                            ></span>
                            <button
                                type="button"
                                class="flex-1 truncate text-left hover:text-accent disabled:hover:text-label-3"
                                :disabled="band.count === 0"
                                @click="showLevel(band.level)"
                            >
                                {{ bandLabel(band.level) }}
                            </button>
                            <span class="tnum shrink-0 tabular-nums">
                                {{ percent(band.count, horizon.total) }}
                            </span>
                            <span class="tnum w-8 shrink-0 text-right font-medium">{{ band.count }}</span>
                        </li>
                    </ul>

                    <!-- What this panel is actually showing. A percentage with
                         no denominator and no period gets quoted in a meeting. -->
                    <div
                        class="mt-4 grid grid-cols-3 gap-2 border-t border-hairline pt-3 text-[11px] text-label-3"
                    >
                        <div>
                            <p class="eyebrow">{{ t('dash.fromDate') }}</p>
                            <p class="tnum mt-0.5 truncate">{{ windowFor(horizon.days).from }}</p>
                        </div>
                        <div>
                            <p class="eyebrow">{{ t('dash.toDate') }}</p>
                            <p class="tnum mt-0.5 truncate">{{ windowFor(horizon.days).to }}</p>
                        </div>
                        <div class="text-right">
                            <p class="eyebrow">{{ t('dash.auditCount') }}</p>
                            <p class="tnum mt-0.5 font-semibold text-label">{{ horizon.total }}</p>
                        </div>
                    </div>
                </section>
            </div>

            <div class="mb-4 grid gap-4 lg:grid-cols-2">
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
