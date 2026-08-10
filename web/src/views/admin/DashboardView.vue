<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { type DashboardSummary, loadDashboard } from '@/api/admin'
import AdminShell from '@/components/admin/AdminShell.vue'
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
 * THE CHARTS ARE HAND-DRAWN rather than a library, and that is a size decision
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
                <!-- Certification levels -->
                <section class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                        {{ t('dash.levels') }}
                    </h2>

                    <p v-if="scored === 0" class="mt-3 text-[14px] text-label-2">
                        {{ t('dash.nothing') }}
                    </p>

                    <template v-else>
                        <div class="mt-4 flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full">
                            <div
                                v-for="band in summary.levels.filter((row) => row.count > 0)"
                                :key="band.level"
                                class="h-full first:rounded-l-full last:rounded-r-full"
                                :style="{
                                    width: `${share(band.count, scored)}%`,
                                    background: TONE[band.level]!.fill,
                                    opacity: TONE[band.level]!.alpha,
                                }"
                                :title="`${bandLabel(band.level)}: ${band.count}`"
                            ></div>
                        </div>

                        <ul class="mt-4">
                            <li
                                v-for="band in summary.levels"
                                :key="band.level"
                                class="flex items-center gap-2.5 border-b border-hairline py-2 text-[14px] last:border-0"
                                :class="band.count === 0 ? 'text-label-3' : ''"
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
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- The same, recently -->
                <section class="rounded-surface border border-hairline bg-surface px-5 py-5">
                    <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                        {{ t('dash.levelsRecent') }}
                    </h2>

                    <p v-if="recentScored === 0" class="mt-3 text-[14px] text-label-2">
                        {{ t('dash.nothingRecent') }}
                    </p>

                    <template v-else>
                        <div class="mt-4 flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full">
                            <div
                                v-for="band in summary.recent.filter((row) => row.count > 0)"
                                :key="band.level"
                                class="h-full first:rounded-l-full last:rounded-r-full"
                                :style="{
                                    width: `${share(band.count, recentScored)}%`,
                                    background: TONE[band.level]!.fill,
                                    opacity: TONE[band.level]!.alpha,
                                }"
                                :title="`${bandLabel(band.level)}: ${band.count}`"
                            ></div>
                        </div>

                        <ul class="mt-4">
                            <li
                                v-for="band in summary.recent.filter((row) => row.count > 0)"
                                :key="band.level"
                                class="flex items-center gap-2.5 border-b border-hairline py-2 text-[14px] last:border-0"
                            >
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :style="{
                                        background: TONE[band.level]!.fill,
                                        opacity: TONE[band.level]!.alpha,
                                    }"
                                ></span>
                                <span class="flex-1">{{ bandLabel(band.level) }}</span>
                                <span class="tnum font-medium">{{ band.count }}</span>
                            </li>
                        </ul>
                    </template>
                </section>
            </div>

            <!-- Assessments by month -->
            <section class="mb-4 rounded-surface border border-hairline bg-surface px-5 py-5">
                <h2 class="text-[15px] font-semibold tracking-[-0.01em]">
                    {{ t('dash.overTime') }}
                </h2>

                <div class="mt-5 flex items-end gap-2" style="height: 132px">
                    <div
                        v-for="month in months"
                        :key="month.month"
                        class="group flex h-full flex-1 flex-col justify-end"
                        :title="
                            month.count === 0
                                ? `${month.month}: 0`
                                : `${month.month}: ${month.count} · ${t('dash.mean', { value: month.mean ?? 0 })}`
                        "
                    >
                        <span
                            v-if="month.count > 0"
                            class="tnum mb-1.5 text-center text-[12px] font-medium text-label-2"
                        >
                            {{ month.count }}
                        </span>
                        <div
                            class="rounded-md"
                            :class="month.count === 0 ? 'bg-track' : 'bg-accent'"
                            :style="{ height: month.count === 0 ? '4px' : `${columnHeight(month.count)}%` }"
                        ></div>
                    </div>
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
