<script setup lang="ts">
import {
    PhFlagCheckered,
    PhPaperPlaneTilt,
    PhPlay,
    PhPrinter,
    PhSealCheck,
    PhSignature,
    PhWarningCircle,
} from '@phosphor-icons/vue'
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import { useRoute } from 'vue-router'

import { apiBlob } from '@/api/client'
import {
    fetchReport,
    type Report,
    type ReportAnswer,
    type ReportQuestion,
    type ReportSection,
    type SectionScore,
} from '@/api/reports'
import ReportPhotographs from '@/components/ReportPhotographs.vue'
import AdminShell from '@/components/admin/AdminShell.vue'
import PdfDownload from '@/components/admin/PdfDownload.vue'
import ScoreBadge from '@/components/admin/ScoreBadge.vue'
import { formatDate, formatPercent, locale, t, type MessageKey } from '@/i18n'

/**
 * One visit, in full — the thing that gets handed to the site.
 *
 * It shows what was RECORDED rather than a summary of it. Every question in
 * the instrument appears whether or not it was answered, because a report that
 * quietly omits what was skipped is the one way this screen could mislead the
 * people it is written for. An unanswered question is a fact about the visit.
 *
 * IT IS A DOCUMENT, NOT A SCREEN, and it is laid out as one. A laboratory
 * manager is handed this and has to find, in order: how they did, which
 * sections dragged it down, what has to be fixed, and then the detail behind
 * all three. So the page reads top to bottom in that order, and each answer is
 * carried by a shape as well as by a word — a bar that is nearly empty says
 * "section 3" before anybody reads the number beside it.
 *
 * Colour is never the only carrier. These are printed, photocopied and read by
 * people who do not see red and green apart, so every band is written out and
 * every meter has its figure next to it.
 *
 * It prints. That is not a nicety: a copy is left behind at the end of a visit
 * and signed, and in most of the places this runs that copy is paper. The print
 * rules live at the bottom of this file rather than in a stylesheet somebody
 * has to know to look for.
 */

const route = useRoute()

const report = ref<Report | null>(null)
const loading = ref(true)
const error = ref('')

const scored = computed(() => {
    const score = report.value?.score

    return score !== undefined && score.scored ? score : null
})

const immediate = computed(
    () => report.value?.findings.filter((finding) => finding.urgency === 'immediate') ?? [],
)

const later = computed(
    () => report.value?.findings.filter((finding) => finding.urgency !== 'immediate') ?? [],
)

/**
 * What a question was answered as, once per instance it applies to.
 *
 * Section 4 is asked again for every pathogen the site tests for, so a
 * question answered for HIV and skipped for malaria comes back with ONE
 * answer. Listed as it arrives, that question reads as answered — while the
 * missing one sits in the denominator costing the site points nobody reading
 * this page can account for. Every question appears whether or not it was
 * answered, and for Section 4 "every question" means every pathogen.
 */
function instancesOf(
    section: ReportSection,
    question: ReportQuestion,
): { pathogen: string | null; answer: ReportAnswer | null }[] {
    if (section.scope !== 'pathogen') {
        return [{ pathogen: null, answer: question.answers[0] ?? null }]
    }

    const named = report.value?.assessment.pathogens.map((entry) => entry.name) ?? []

    // A visit that named no pathogens has nothing to lay the answers against,
    // and what was recorded is still what happened.
    if (named.length === 0) {
        return question.answers.length === 0
            ? [{ pathogen: null, answer: null }]
            : question.answers.map((answer) => ({ pathogen: answer.pathogen, answer }))
    }

    return named.map((name) => ({
        pathogen: name,
        answer: question.answers.find((answer) => answer.pathogen === name) ?? null,
    }))
}

/** Written out rather than shown raw: "draft" in lowercase is a database value. */
const STATUS_LABELS: Record<string, MessageKey> = {
    draft: 'reports.statusDraft',
    submitted: 'reports.statusSubmitted',
    reviewed: 'reports.statusReviewed',
    finalised: 'reports.statusFinalised',
}

/** The same, for a signature's slot: `assessor_1` is a column, not a caption. */
const ROLE_LABELS: Record<string, MessageKey> = {
    assessor_1: 'signature.assessor',
    assessor_2: 'signature.secondAssessor',
    site_representative: 'signature.siteRepresentative',
}

function roleLabel(role: string | null): string {
    const key = role === null ? undefined : ROLE_LABELS[role]

    return key === undefined ? (role ?? '') : t(key)
}

const status = computed(() => report.value?.assessment.status ?? '')

const statusLabel = computed(() => {
    const key = STATUS_LABELS[status.value]

    return key === undefined ? status.value : t(key)
})

/**
 * A draft is flagged rather than merely labelled.
 *
 * It is the one state where the figures on this page are not final, and a
 * printed copy carries no other clue — somebody handed the paper has no way to
 * tell it apart from the version that was signed off.
 */
const isDraft = computed(() => status.value === 'draft')

/** The band's colour, used by the meters. Agrees with ScoreBadge by construction. */
/**
 * The bar under the score, on the level ramp.
 *
 * Red for Level 1 and green for Level 4 is the reading everybody expects and
 * it is the one this instrument cannot afford: those three colours are the
 * three responses, and this page prints the responses two sections below. The
 * ramp deepens instead, and the level is written out beside it either way.
 */
function bandTone(level: number | null): string {
    switch (level) {
        case 0:
            return 'bg-level-0'
        case 1:
            return 'bg-level-1'
        case 2:
            return 'bg-level-2'
        case 3:
            return 'bg-level-3'
        case 4:
            return 'bg-level-4'
        default:
            return 'bg-label-3'
    }
}

/**
 * A section's colour, from its own percentage rather than the visit's band.
 *
 * The thresholds are the instrument's own band minimums in spirit — under half
 * is failing, under four fifths is partial — and they are only ever a tint
 * behind a figure that is also printed. Nothing is decided by them.
 */
function sectionTone(percentage: number | null): string {
    if (percentage === null) {
        return 'bg-label-3'
    }

    if (percentage < 50) {
        return 'bg-no'
    }

    return percentage < 80 ? 'bg-partial' : 'bg-yes'
}

/** The scored row for a section, so its header can carry its own figure. */
const sectionScores = computed(() => {
    const map = new Map<number, SectionScore>()

    for (const section of scored.value?.sections ?? []) {
        map.set(section.number, section)
    }

    return map
})

/** The tone of a single answer, so a page of Ys and Ns can be read at a glance. */
function pill(response: string): string {
    switch (response) {
        case 'Y':
            return 'bg-yes-soft text-yes'
        case 'P':
            return 'bg-partial-soft text-partial'
        case 'N':
            return 'bg-no-soft text-no'
        default:
            return 'bg-na-soft text-na'
    }
}

/** Bound rather than called inline: the template scope has no `window`. */
function print(): void {
    window.print()
}

/**
 * The life of this visit, in order.
 *
 * Every one of these moments was already stored and none of them was shown:
 * when the assessor started, when they stopped, when it reached the server,
 * when it was scored, who signed it. Between a draft and a finalised record
 * the only thing on the page that changed was a word in the corner, which is
 * thin evidence for a document that certifies a laboratory.
 *
 * Only moments that happened are drawn. A timeline with placeholder rows for
 * things that have not occurred reads as missing data rather than as a visit
 * still in progress.
 */
const history = computed(() => {
    const value = report.value

    if (value === null) return []

    const entries: { key: string; label: string; at: string | null; icon: Component }[] = [
        { key: 'started', label: t('report.started'), at: value.assessment.started_at, icon: PhPlay },
        { key: 'ended', label: t('report.ended'), at: value.assessment.ended_at, icon: PhFlagCheckered },
        {
            key: 'submitted',
            label: t('report.submittedAt'),
            at: value.assessment.submitted_at,
            icon: PhPaperPlaneTilt,
        },
        {
            key: 'scored',
            label: t('report.scoredAt'),
            at: value.score.scored ? value.score.scored_at : null,
            icon: PhSealCheck,
        },
        ...value.signatures.map((signature) => ({
            key: `signature-${signature.id}`,
            label: t('report.signedBy', { name: signature.signed_name ?? signature.role ?? '' }),
            at: signature.uploaded_at,
            icon: PhSignature,
        })),
    ]

    return entries.filter((entry) => entry.at !== null && entry.at !== '')
})

/** A stored date, or an em dash — never an empty cell somebody reads as zero. */
function date(value: string | null): string {
    return value === null || value === '' ? '—' : formatDate(value)
}

/** The clock time beside a history entry, in the reader's locale. */
function clock(value: string | null): string {
    if (value === null || value === '') return ''

    const parsed = new Date(value)

    return Number.isNaN(parsed.getTime())
        ? ''
        : parsed.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}

/**
 * The visit's images, fetched with the session's token and held as object URLs.
 *
 * NOT `<img src="/api/attachments/…">`, however much the payload's `url` looks
 * like an invitation to. These files sit outside the document root and are
 * served by the application precisely so the organisation scope is what stands
 * between one tenant's evidence and another's — and a browser asked to load
 * that URL from an `img` tag sends no Authorization header, gets a 401, and
 * draws a broken icon where a signature should be.
 *
 * Keyed by attachment id. Absent means still on its way; null means it was
 * asked for and did not arrive, which the page says out loud rather than
 * leaving a box that looks like it is still loading.
 */
const images = ref(new Map<string, string | null>())

async function loadImages(value: Report): Promise<void> {
    const ids = [
        ...value.site_photographs.map((photo) => photo.id),
        ...value.sections.flatMap((section) => section.photographs.map((photo) => photo.id)),
        ...value.signatures.map((signature) => signature.id),
    ]

    // All at once. These are a few dozen resized images on a desktop screen,
    // and fetching them in series would draw the report top to bottom over
    // several seconds while somebody waits to print it.
    await Promise.all(
        ids.map(async (id) => {
            if (images.value.has(id)) {
                return
            }

            try {
                const blob = await apiBlob(`/attachments/${encodeURIComponent(id)}`, {
                    method: 'GET',
                })

                images.value.set(id, URL.createObjectURL(blob))
            } catch {
                // One picture that will not load is not a reason to fail the
                // report around it. The page draws the gap and carries on.
                images.value.set(id, null)
            }
        }),
    )
}

/** Object URLs are references the document keeps alive until they are let go. */
onBeforeUnmount(() => {
    for (const url of images.value.values()) {
        if (url !== null) {
            URL.revokeObjectURL(url)
        }
    }

    images.value.clear()
})

async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        report.value = await fetchReport(String(route.params.id ?? ''), locale.value)
        // The ids do not change with the locale, so a language switch redraws
        // the words around images that are already here.
        await loadImages(report.value)
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
        report.value = null
    }

    loading.value = false
}

// Re-fetched on a language change rather than translated here: the question
// text, the section titles and the band descriptions all live in the template
// and only the server has it.
watch(locale, load)

onMounted(load)
</script>

<template>
    <AdminShell
        :eyebrow="t('report.title')"
        :title="report?.assessment.facility.name ?? t('report.title')"
        :subtitle="report?.assessment.site.name ?? ''"
    >
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>
        <p v-else-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <template v-else-if="report">
            <div class="mb-5 flex flex-wrap items-center gap-3 print:hidden">
                <span
                    class="rounded-full px-2.5 py-1 text-[13px] font-semibold"
                    :class="isDraft ? 'bg-brass-soft text-brass' : 'bg-accent-soft text-accent'"
                >
                    {{ statusLabel }}
                </span>

                <span class="flex-1"></span>

                <!-- Printing leaves a copy on the bench; the file is what
                     gets emailed, filed, and asked for two years later by
                     somebody who was never given a login. Both, then, and the
                     quieter treatment on the one that needs a printer. -->
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full border border-hairline bg-surface px-4 py-2 text-[15px] font-semibold text-label-2 transition-colors hover:border-accent hover:text-accent"
                    @click="print"
                >
                    <PhPrinter :size="16" aria-hidden="true" />
                    {{ t('report.print') }}
                </button>

                <PdfDownload :assessment-id="route.params.id as string" />
            </div>

            <!-- On paper the status has no chip beside it, so it is stated. -->
            <p v-if="isDraft" class="mb-4 hidden text-[13px] font-semibold uppercase tracking-wide text-partial print:block">
                {{ t('report.draftWarning') }}
            </p>

            <!-- Where and when. A strip of labelled facts rather than a
                 paragraph: this is the part somebody checks, not reads.

                 It opens with what the sheet is. Everywhere else in the
                 application the brass rule is spent on nothing; here it sits
                 under the name of the site being certified, which is the one
                 thing on any screen that is genuinely a matter of record. -->
            <section class="mb-4 rounded-surface bg-surface p-6 shadow-surface print:shadow-none">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="eyebrow mb-2 text-brass">{{ t('report.record') }}</p>
                        <h2 class="rule-brass inline-block pb-2 text-[24px] font-bold tracking-[-0.02em]">
                            {{ report.assessment.facility.name }}
                        </h2>
                        <p class="mt-2.5 text-[15px] text-label-2">
                            {{ report.assessment.site.name }}
                            <template v-if="report.assessment.site.location">
                                · {{ report.assessment.site.location }}
                            </template>
                        </p>
                    </div>

                    <span
                        class="hidden shrink-0 rounded-full px-2.5 py-1 text-[13px] font-semibold print:inline-block"
                        :class="isDraft ? 'bg-brass-soft text-brass' : 'bg-accent-soft text-accent'"
                    >
                        {{ statusLabel }}
                    </span>
                </div>

                <!--
                    The four figures somebody checks before reading anything
                    else, in one bordered strip. They were spread over two
                    panels and a list: the score had its own card, the section
                    count had to be counted, and the number of findings was
                    only discoverable by scrolling to the findings. A strip
                    divided into cells says these belong together and are of
                    equal weight, which they are — each is a fact about this
                    visit, not a claim about the site.
                -->
                <dl
                    v-if="scored"
                    class="mt-5 grid grid-cols-2 divide-y divide-hairline rounded-card border border-hairline sm:grid-cols-4 sm:divide-x sm:divide-y-0"
                >
                    <div class="px-5 py-4">
                        <dt class="text-[14px] text-label-2">{{ t('report.overall') }}</dt>
                        <dd class="tnum mt-1.5 text-[26px] font-bold leading-none tracking-[-0.02em]">
                            {{
                                scored.percentage === null
                                    ? '—'
                                    : formatPercent(scored.percentage, 2)
                            }}
                        </dd>
                    </div>

                    <div class="px-5 py-4">
                        <dt class="text-[14px] text-label-2">{{ t('report.level') }}</dt>
                        <dd class="mt-1.5">
                            <span
                                v-if="scored.level !== null"
                                class="tnum text-[26px] font-bold leading-none tracking-[-0.02em]"
                            >
                                {{ scored.level }}
                            </span>
                            <span v-else class="text-[16px] text-label-2">
                                {{ t('score.notScorable') }}
                            </span>
                        </dd>
                    </div>

                    <div class="px-5 py-4">
                        <dt class="text-[14px] text-label-2">{{ t('report.sections') }}</dt>
                        <dd class="tnum mt-1.5 text-[26px] font-bold leading-none tracking-[-0.02em]">
                            {{ scored.sections.filter((section) => section.applicable).length }}
                        </dd>
                    </div>

                    <div class="px-5 py-4">
                        <dt class="text-[14px] text-label-2">{{ t('report.findings') }}</dt>
                        <dd class="tnum mt-1.5 text-[26px] font-bold leading-none tracking-[-0.02em]">
                            {{ report.findings.length }}
                        </dd>
                    </div>
                </dl>

                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-hairline pt-4 text-[15px] sm:grid-cols-3 lg:grid-cols-5">
                    <div v-if="report.assessment.facility.place">
                        <dt class="eyebrow text-label-3">{{ t('report.place') }}</dt>
                        <dd class="mt-0.5">{{ report.assessment.facility.place }}</dd>
                    </div>
                    <div v-if="report.assessment.facility.code">
                        <dt class="eyebrow text-label-3">{{ t('report.facilityCode') }}</dt>
                        <dd class="tnum mt-0.5">{{ report.assessment.facility.code }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow text-label-3">{{ t('report.assessedOn') }}</dt>
                        <dd class="tnum mt-0.5">{{ date(report.assessment.assessed_on) }}</dd>
                    </div>
                    <!-- Beside the date because it is the other half of
                         when: the date says which day, the round says which
                         pass through the programme, and a report that is
                         compared with another is compared within a round. -->
                    <div v-if="report.assessment.audit_round">
                        <dt class="eyebrow text-label-3">{{ t('report.auditRound') }}</dt>
                        <dd class="mt-0.5">{{ report.assessment.audit_round }}</dd>
                    </div>
                    <div v-if="report.assessment.previous_assessed_on">
                        <dt class="eyebrow text-label-3">{{ t('report.previousVisit') }}</dt>
                        <dd class="tnum mt-0.5">
                            {{ date(report.assessment.previous_assessed_on) }}
                        </dd>
                    </div>
                    <div v-if="report.assessment.pathogens.length > 0">
                        <dt class="eyebrow text-label-3">{{ t('report.pathogens') }}</dt>
                        <dd class="mt-0.5">
                            {{ report.assessment.pathogens.map((p) => p.name).join(', ') }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- The score. The one panel somebody reads from across a desk. -->
            <section v-if="scored" class="mb-4 rounded-surface bg-surface p-5 shadow-surface print:shadow-none">
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div>
                        <p class="eyebrow mb-1 text-label-3">{{ t('report.overall') }}</p>
                        <div class="flex flex-wrap items-baseline gap-3">
                            <span
                                v-if="scored.percentage !== null"
                                class="tnum text-[46px] font-bold leading-none tracking-[-0.03em]"
                            >
                                {{ formatPercent(scored.percentage, 2) }}
                            </span>
                            <span v-else class="text-[20px] text-label-2">
                                {{ t('score.notScorable') }}
                            </span>
                            <ScoreBadge :level="scored.level" />
                        </div>
                    </div>

                    <p class="tnum text-[15px] text-label-2">
                        {{ t('report.outOf', { score: scored.total_score, possible: scored.total_possible }) }}
                    </p>
                </div>

                <!-- The figure again, as a length. A number tells you where you
                     are; a bar tells you how far that is from anywhere else. -->
                <div v-if="scored.percentage !== null" class="mt-4 h-2.5 overflow-hidden rounded-full bg-track print:border print:border-hairline">
                    <div
                        class="h-full rounded-full"
                        :class="bandTone(scored.level)"
                        :style="{ width: `${scored.percentage}%` }"
                    ></div>
                </div>

                <p v-if="scored.band?.description" class="mt-3 text-[16px] leading-relaxed text-label-2">
                    {{ scored.band.description }}
                </p>

                <!-- Per section. Counts as well as a percentage: a section can
                     only be compared with another as a proportion, and can only
                     be checked as a pair of numbers. The bar is what makes the
                     comparison possible without reading five rows of digits. -->
                <div class="mt-6">
                    <p class="eyebrow mb-3 text-label-3">{{ t('report.bySection') }}</p>

                    <div
                        v-for="section in scored.sections"
                        :key="section.number"
                        class="border-t border-hairline py-2.5"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <p class="min-w-0 flex-1 text-[15px]">
                                <span class="tnum text-label-3">{{ section.number }}.</span>
                                {{ section.title }}
                            </p>
                            <p class="tnum shrink-0 text-[14px] text-label-2">
                                {{ section.score }} / {{ section.possible }}
                                <span v-if="section.excluded > 0" class="text-label-3">
                                    · {{ t('report.excludedCount', { count: section.excluded }) }}
                                </span>
                            </p>
                            <p class="tnum w-[72px] shrink-0 text-right text-[15px] font-semibold">
                                <template v-if="section.percentage !== null">
                                    {{ formatPercent(section.percentage, 2) }}
                                </template>
                                <template v-else>—</template>
                            </p>
                        </div>

                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-track print:border print:border-hairline">
                            <div
                                class="h-full rounded-full"
                                :class="sectionTone(section.percentage)"
                                :style="{ width: `${section.percentage ?? 0}%` }"
                            ></div>
                        </div>
                    </div>
                </div>

                <p
                    v-if="scored.anomalies.unexpected.length > 0 || scored.anomalies.violations.length > 0"
                    class="mt-4 flex items-start gap-2 rounded-card bg-partial-soft px-3 py-2 text-[14px] text-partial"
                >
                    <PhWarningCircle :size="16" class="mt-0.5 shrink-0" aria-hidden="true" />
                    {{ t('report.anomalies') }}
                </p>
            </section>

            <!--
                What happened to this visit, in order. The circles and the line
                between them are doing a job a table of four timestamps does
                not: they say these are one sequence, and that it has an end.
            -->
            <section
                v-if="history.length > 0"
                class="mb-4 rounded-surface bg-surface p-6 shadow-surface print:shadow-none"
            >
                <h2 class="text-[17px] font-semibold">{{ t('report.history') }}</h2>

                <ol class="mt-5">
                    <li
                        v-for="(entry, index) in history"
                        :key="entry.key"
                        class="flex gap-4"
                    >
                        <!-- The node and the thread below it. The thread is
                             drawn by the row rather than between rows, so the
                             last entry simply has none. -->
                        <div class="flex flex-col items-center">
                            <span
                                class="flex size-11 shrink-0 items-center justify-center rounded-full border border-hairline text-label-2"
                            >
                                <component :is="entry.icon" :size="18" aria-hidden="true" />
                            </span>
                            <span
                                v-if="index < history.length - 1"
                                class="w-px flex-1 border-l border-dashed border-rule"
                                aria-hidden="true"
                            ></span>
                        </div>

                        <div class="flex flex-1 flex-wrap items-baseline justify-between gap-x-4 pb-7">
                            <p class="text-[16px] font-semibold">{{ entry.label }}</p>
                            <p class="tnum text-right text-[14px] text-label-2">
                                <span class="block">{{ clock(entry.at) }}</span>
                                <span class="block text-label-3">{{ date(entry.at) }}</span>
                            </p>
                        </div>
                    </li>
                </ol>
            </section>

            <!-- What has to be done. Ahead of the questions on purpose: it is
                 the half of the report anybody acts on. -->
            <section
                v-if="report.findings.length > 0"
                class="mb-4 rounded-surface bg-surface p-5 shadow-surface print:shadow-none"
            >
                <h3 class="text-[18px] font-semibold">{{ t('report.actionPlan') }}</h3>

                <div v-for="group in [
                    { key: 'immediate', label: t('report.immediate'), rows: immediate, urgent: true },
                    { key: 'later', label: t('report.followUp'), rows: later, urgent: false },
                ]" :key="group.key">
                    <template v-if="group.rows.length > 0">
                        <p class="eyebrow mt-5 mb-2" :class="group.urgent ? 'text-no' : 'text-label-3'">
                            {{ group.label }} · {{ group.rows.length }}
                        </p>

                        <div
                            v-for="finding in group.rows"
                            :key="finding.id"
                            class="mb-2 rounded-card bg-ground p-3.5 print:border print:border-hairline print:bg-transparent"
                        >
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="tnum rounded bg-surface px-1.5 py-0.5 text-[13px] font-semibold text-label-2 print:border print:border-hairline">
                                    {{ finding.question_code }}
                                </span>
                                <span v-if="finding.pathogen" class="text-[13px] text-label-3">
                                    {{ finding.pathogen }}
                                </span>
                                <span v-if="finding.question" class="min-w-0 flex-1 text-[14px] text-label-3">
                                    {{ finding.question }}
                                </span>
                            </div>

                            <p class="mt-2 text-[16px] leading-relaxed">{{ finding.gap }}</p>
                            <p
                                v-if="finding.recommendation"
                                class="mt-1 border-l-2 border-accent pl-3 text-[15px] leading-relaxed text-label-2"
                            >
                                {{ finding.recommendation }}
                            </p>

                            <p class="mt-2 flex flex-wrap gap-x-3 text-[13px] text-label-3">
                                <span>{{ finding.responsibility_level }}</span>
                                <span v-if="finding.responsible_person">
                                    {{ finding.responsible_person }}
                                </span>
                                <span v-if="finding.due_date" class="tnum">
                                    {{ t('report.due') }} {{ date(finding.due_date) }}
                                </span>
                            </p>
                        </div>
                    </template>
                </div>
            </section>

            <!-- The site itself, photographed on the setup screen before the
                 first section — so it sits here, ahead of them, in the order
                 the assessor worked. -->
            <section
                v-if="report.site_photographs.length > 0"
                class="mb-4 rounded-surface bg-surface p-5 shadow-surface print:shadow-none"
            >
                <h3 class="pb-4 text-[18px] font-semibold">{{ t('report.sitePhotographs') }}</h3>
                <ReportPhotographs :photographs="report.site_photographs" :images="images" />
            </section>

            <!-- Every question, answered or not -->
            <section
                v-for="section in report.sections"
                :key="section.number"
                class="mb-4 rounded-surface bg-surface p-5 shadow-surface print:shadow-none"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-hairline pb-3">
                    <h3 class="min-w-0 flex-1 text-[18px] font-semibold">
                        <span class="tnum mr-1.5 inline-block rounded bg-accent-soft px-1.5 text-[15px] font-bold text-accent">
                            {{ section.number }}
                        </span>
                        {{ section.title }}
                    </h3>

                    <p
                        v-if="sectionScores.get(section.number)"
                        class="tnum shrink-0 text-[15px] text-label-2"
                    >
                        {{ sectionScores.get(section.number)!.score }} /
                        {{ sectionScores.get(section.number)!.possible }}
                        <span
                            v-if="sectionScores.get(section.number)!.percentage !== null"
                            class="ml-1 font-semibold text-label"
                        >
                            {{ formatPercent(sectionScores.get(section.number)!.percentage!, 2) }}
                        </span>
                    </p>
                </div>

                <div
                    v-for="question in section.questions"
                    :key="question.code"
                    class="border-b border-hairline py-2.5 last:border-b-0"
                >
                    <div class="flex items-start justify-between gap-4">
                        <p class="min-w-0 flex-1 text-[15px] leading-relaxed">
                            <span class="tnum mr-1 text-label-3">{{ question.code }}</span>
                            {{ question.text }}
                        </p>

                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span
                                v-for="(row, index) in instancesOf(section, question)"
                                :key="index"
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[13px] font-semibold print:border print:border-hairline"
                                :class="
                                    row.answer === null ? 'bg-na-soft text-na' : pill(row.answer.response)
                                "
                            >
                                <span v-if="row.pathogen" class="font-normal opacity-70">
                                    {{ row.pathogen }}
                                </span>
                                {{ row.answer === null ? t('report.unanswered') : row.answer.label }}
                            </span>
                        </div>
                    </div>

                    <!-- A comment is the assessor's own words about that answer,
                         so it is set off from the question rather than run on
                         beneath it. -->
                    <p
                        v-for="(answer, index) in question.answers.filter((a) => a.comment)"
                        :key="`c${index}`"
                        class="mt-1.5 border-l-2 border-hairline pl-3 text-[14px] leading-relaxed text-label-2"
                    >
                        {{ answer.comment }}
                    </p>
                </div>

                <!-- What the assessor was standing in front of, under the
                     questions it is evidence for. A 0 says a thing was
                     missing; the photograph of the empty shelf is what
                     somebody argues from a year later. -->
                <div
                    v-if="section.photographs.length > 0"
                    class="mt-4 border-t border-hairline pt-4"
                >
                    <h4 class="eyebrow pb-3 text-label-3">{{ t('report.photographs') }}</h4>
                    <ReportPhotographs :photographs="section.photographs" :images="images" />
                </div>
            </section>

            <!-- Who signed -->
            <section
                v-if="report.signatures.length > 0"
                class="mb-4 rounded-surface bg-surface p-5 shadow-surface print:shadow-none"
            >
                <h3 class="text-[18px] font-semibold">{{ t('report.signatures') }}</h3>

                <div class="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="signature in report.signatures" :key="signature.id">
                        <img
                            v-if="images.get(signature.id)"
                            :src="images.get(signature.id)!"
                            alt=""
                            class="h-16 w-auto"
                        />
                        <p v-else class="flex h-16 items-center text-[14px] text-label-3">
                            {{ images.has(signature.id) ? t('report.imageUnavailable') : '' }}
                        </p>
                        <p class="mt-1 border-t border-hairline pt-1.5 text-[15px] font-medium">
                            {{ signature.signed_name }}
                        </p>
                        <p class="text-[13px] text-label-3">{{ roleLabel(signature.role) }}</p>
                    </div>
                </div>
            </section>
        </template>
    </AdminShell>
</template>

<style>
/**
 * A copy is left behind at the end of a visit, and in most places that runs
 * this, the copy is paper. Backgrounds and shadows are dropped so it does not
 * cost a cartridge, and a section is kept off a page boundary so a score never
 * ends up split from the questions that produced it.
 *
 * The meters are the one thing that must survive the drop. A bar printed
 * without its fill is a row of empty boxes, so they are forced to print their
 * colour — which is why every one of them also has its figure beside it, for
 * the printer that ignores this and the photocopier that comes after.
 */
@media print {
    .rounded-surface,
    .rounded-card {
        background: none !important;
        break-inside: avoid;
    }

    .rounded-surface {
        border: 1px solid #ccc;
        padding: 12px !important;
    }

    /* Colour-adjust rather than a background: the fills are the report's only
       at-a-glance content and a printer that drops them loses the comparison. */
    .bg-yes,
    .bg-no,
    .bg-partial,
    .bg-accent,
    .bg-track {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* A section heading at the foot of a page, with its questions overleaf, is
       the one break that makes a printed report hard to follow. */
    h3 {
        break-after: avoid;
    }
}
</style>
