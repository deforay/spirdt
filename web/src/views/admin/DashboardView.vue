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
 * THE CHARTS ARE HAND-DRAWN SVG rather than a library, and that is a size
 * decision rather than a purist one. Two shapes are needed — a stacked bar and
 * a column series — and the smallest capable library is around seventy
 * kilobytes. This is one application, so that weight would also land on the
 * assessor's phone, which is the half that has to arrive over the connection
 * it exists to cope with. If the panels ever need drill-down, zoom or export,
 * a library becomes the right answer and this is easy to replace.
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
 */
const TONE: Record<number, string> = {
    0: 'var(--color-no)',
    1: 'var(--color-no)',
    2: 'var(--color-partial)',
    3: 'var(--color-yes)',
    4: 'var(--color-yes)',
}

/** Level 1 and 0 share a colour, so opacity separates them without a sixth hue. */
const FADE: Record<number, string> = { 0: '1', 1: '0.55', 2: '1', 3: '0.6', 4: '1' }

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

/** Width as a percentage of the stacked bar. Zero-count bands take no space. */
function share(count: number, total: number): number {
    return total === 0 ? 0 : (count / total) * 100
}

const months = computed(() => summary.value?.months ?? [])

const busiestMonth = computed(() =>
    months.value.reduce((most, month) => Math.max(most, month.count), 0),
)

function columnHeight(count: number): number {
    // A month with one visit still has to be visible, so an occupied month
    // never draws shorter than a couple of pixels.
    return busiestMonth.value === 0 ? 0 : Math.max(count === 0 ? 0 : 2, (count / busiestMonth.value) * 100)
}

function monthLabel(month: string): string {
    const [year, index] = month.split('-')

    return new Date(Number(year), Number(index) - 1, 1)
        .toLocaleDateString(locale.value, { month: 'short' })
}

function assessedOn(value: string): string {
    const parsed = new Date(value + 'T00:00:00')

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString()
}

onMounted(load)

// The band and section names come from the instrument, so they are rendered by
// the server in the language asked for. App strings re-render on a locale
// change by themselves; these do not, and would sit in the previous language
// until the next navigation. ReportView reloads for the same reason.
watch(locale, () => load())
</script>

<template>
    <AdminShell :title="t('dash.title')" :subtitle="t('dash.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <template v-else-if="summary !== null && totals !== null">
            <!-- Headline counts -->
            <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-card bg-surface p-4">
                    <span class="tnum block text-[28px] font-bold leading-tight">
                        {{ totals.assessments }}
                    </span>
                    <span class="block text-[13px] text-label-2">{{ t('dash.assessments') }}</span>
                </div>

                <div class="rounded-card bg-surface p-4">
                    <span class="tnum block text-[28px] font-bold leading-tight">{{ totals.sites }}</span>
                    <span class="block text-[13px] text-label-2">{{ t('dash.sites') }}</span>
                    <span class="mt-0.5 block text-[12px] text-label-3">
                        {{ t('dash.ofRegistry', { total: totals.known_sites }) }}
                    </span>
                </div>

                <div class="rounded-card bg-surface p-4">
                    <span class="tnum block text-[28px] font-bold leading-tight">
                        {{ totals.facilities }}
                    </span>
                    <span class="block text-[13px] text-label-2">{{ t('dash.facilities') }}</span>
                </div>

                <div class="rounded-card bg-surface p-4">
                    <span class="tnum block text-[28px] font-bold leading-tight">{{ totals.drafts }}</span>
                    <span class="block text-[13px] text-label-2">{{ t('dash.drafts') }}</span>
                </div>
            </div>

            <div class="mb-5 grid gap-4 lg:grid-cols-2">
                <!-- Certification levels -->
                <section class="rounded-card bg-surface p-4">
                    <h2 class="mb-3 text-[15px] font-semibold">{{ t('dash.levels') }}</h2>

                    <p v-if="scored === 0" class="text-[14px] text-label-2">{{ t('dash.nothing') }}</p>

                    <template v-else>
                        <div class="flex h-6 w-full overflow-hidden rounded-full bg-fill">
                            <div
                                v-for="band in summary.levels"
                                :key="band.level"
                                class="h-full"
                                :style="{
                                    width: `${share(band.count, scored)}%`,
                                    background: TONE[band.level],
                                    opacity: FADE[band.level],
                                }"
                                :title="`${bandLabel(band.level)}: ${band.count}`"
                            ></div>
                        </div>

                        <ul class="mt-3 flex flex-col gap-1.5">
                            <li
                                v-for="band in summary.levels"
                                :key="band.level"
                                class="flex items-center gap-2 text-[13px]"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{ background: TONE[band.level], opacity: FADE[band.level] }"
                                ></span>
                                <span class="flex-1 text-label-2">{{ bandLabel(band.level) }}</span>
                                <span class="tnum font-medium">{{ band.count }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- The same, recently -->
                <section class="rounded-card bg-surface p-4">
                    <h2 class="mb-3 text-[15px] font-semibold">{{ t('dash.levelsRecent') }}</h2>

                    <p v-if="recentScored === 0" class="text-[14px] text-label-2">
                        {{ t('dash.nothingRecent') }}
                    </p>

                    <template v-else>
                        <div class="flex h-6 w-full overflow-hidden rounded-full bg-fill">
                            <div
                                v-for="band in summary.recent"
                                :key="band.level"
                                class="h-full"
                                :style="{
                                    width: `${share(band.count, recentScored)}%`,
                                    background: TONE[band.level],
                                    opacity: FADE[band.level],
                                }"
                                :title="`${bandLabel(band.level)}: ${band.count}`"
                            ></div>
                        </div>

                        <ul class="mt-3 flex flex-col gap-1.5">
                            <li
                                v-for="band in summary.recent.filter((row) => row.count > 0)"
                                :key="band.level"
                                class="flex items-center gap-2 text-[13px]"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{ background: TONE[band.level], opacity: FADE[band.level] }"
                                ></span>
                                <span class="flex-1 text-label-2">{{ bandLabel(band.level) }}</span>
                                <span class="tnum font-medium">{{ band.count }}</span>
                            </li>
                        </ul>
                    </template>
                </section>
            </div>

            <!-- Assessments by month -->
            <section class="mb-5 rounded-card bg-surface p-4">
                <h2 class="mb-3 text-[15px] font-semibold">{{ t('dash.overTime') }}</h2>

                <div class="flex items-end gap-1.5" style="height: 120px">
                    <div
                        v-for="month in months"
                        :key="month.month"
                        class="flex h-full flex-1 flex-col justify-end"
                        :title="
                            month.mean === null
                                ? `${month.month}: 0`
                                : `${month.month}: ${month.count} · ${t('dash.mean', { value: month.mean })}`
                        "
                    >
                        <span
                            v-if="month.count > 0"
                            class="tnum mb-1 text-center text-[11px] text-label-3"
                        >
                            {{ month.count }}
                        </span>
                        <div
                            class="rounded-t-sm bg-accent"
                            :style="{ height: `${columnHeight(month.count)}%`, opacity: month.count === 0 ? 0.12 : 1 }"
                        ></div>
                    </div>
                </div>

                <div class="mt-1.5 flex gap-1.5">
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
                <section class="rounded-card bg-surface p-4">
                    <h2 class="text-[15px] font-semibold">{{ t('dash.sections') }}</h2>
                    <p class="mb-3 text-[12px] text-label-3">{{ t('dash.sectionsHelp') }}</p>

                    <p v-if="summary.sections.length === 0" class="text-[14px] text-label-2">
                        {{ t('dash.noSections') }}
                    </p>

                    <ul v-else class="flex flex-col gap-2.5">
                        <li v-for="section in summary.sections" :key="section.code">
                            <div class="mb-1 flex items-baseline gap-2 text-[13px]">
                                <span class="flex-1">{{ section.name }}</span>
                                <span class="tnum font-semibold">{{ section.mean }}%</span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-fill">
                                <div
                                    class="h-full rounded-full"
                                    :style="{
                                        width: `${section.mean}%`,
                                        background:
                                            section.mean < 60
                                                ? 'var(--color-no)'
                                                : section.mean < 80
                                                  ? 'var(--color-partial)'
                                                  : 'var(--color-yes)',
                                    }"
                                ></div>
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Latest -->
                <section class="rounded-card bg-surface p-4">
                    <div class="mb-3 flex items-baseline justify-between gap-3">
                        <h2 class="text-[15px] font-semibold">{{ t('dash.latest') }}</h2>
                        <RouterLink :to="{ name: 'admin-reports' }" class="text-[13px] text-accent">
                            {{ t('dash.viewAll') }}
                        </RouterLink>
                    </div>

                    <p v-if="summary.latest.length === 0" class="text-[14px] text-label-2">
                        {{ t('dash.nothing') }}
                    </p>

                    <ul v-else class="flex flex-col">
                        <li
                            v-for="row in summary.latest"
                            :key="row.id"
                            class="border-b border-hairline py-2 last:border-0"
                        >
                            <RouterLink
                                :to="{ name: 'admin-report', params: { id: row.id } }"
                                class="flex items-center gap-3"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px] hover:text-accent">
                                        {{ row.site ?? row.facility }}
                                    </span>
                                    <span class="block text-[12px] text-label-3">
                                        {{ assessedOn(row.assessed_on) }}
                                    </span>
                                </span>
                                <span v-if="row.percentage !== null" class="tnum text-[14px] font-semibold">
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
