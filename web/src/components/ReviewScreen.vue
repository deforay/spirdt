<script setup lang="ts">
import { computed, ref } from 'vue'

import SignatureSection from '@/components/SignatureSection.vue'
import type { StoredFinding } from '@/db/database'
import { formatNumber, formatPercent, t, text } from '@/i18n'
import type { ScoreResult, Template } from '@/scoring/types'

/**
 * Review, record the gaps, submit.
 *
 * Two things happen here that happen nowhere else.
 *
 * The completeness gate, which is two rules rather than one. Every expected
 * question needs an answer, and every Partial, No and Not applicable needs a
 * note — the template says which responses oblige one, and all fifty-nine
 * questions oblige all three. A gap nobody described is a gap the site cannot
 * act on, which is the whole reason the visit is made.
 *
 * The server refuses on the same two. That is deliberate: a gate only the
 * device enforces is a property of one build of one client, not of the record.
 *
 * The gaps. The User's Guide has the site debriefed on findings and actions
 * before the assessor leaves, so they are written here, with the site in the
 * room, rather than reconstructed from scores afterwards. Responsibility is
 * asked for because many gaps are not the site's to fix, and one filed against
 * a site that cannot act on it never closes.
 */

const props = defineProps<{
    template: Template
    result: ScoreResult
    /** Several per question, keyed by `${questionCode}|${pathogen}`. */
    findings: Map<string, StoredFinding[]>
    /** Response by `${questionCode}|${pathogen}`. Passed in rather than re-derived. */
    answersByKey: Map<string, string | null>
    siteName: string
    /** The visit this review belongs to, and its Part A answers, for signatures. */
    assessmentId: string
    context: Record<string, unknown>
    submitting: boolean
    submitError: string
}>()

const emit = defineEmits<{
    back: []
    jump: [sectionCode: string, pathogen: string | null]
    /** Edit one finding, by its own id. */
    finding: [key: string, patch: Partial<Omit<StoredFinding, 'key' | 'assessmentId'>>]
    /** Start another one against this answer. */
    addFinding: [questionCode: string, pathogen: string | null]
    removeFinding: [key: string]
    submit: []
}>()

const open = ref<string | null>(null)

const URGENCY = [
    { key: 'immediate', label: 'urgency.immediate' },
    { key: 'follow_up', label: 'urgency.followUp' },
] as const

const RESPONSIBILITY = [
    { key: 'site', label: 'responsibility.site' },
    { key: 'facility', label: 'responsibility.facility' },
    { key: 'district', label: 'responsibility.district' },
    { key: 'regional', label: 'responsibility.regional' },
    { key: 'national', label: 'responsibility.national' },
] as const

/** Question text by code, so a gap reads as a sentence rather than a number. */
const questionText = computed(() => {
    const index = new Map<string, string>()

    for (const section of props.template.sections) {
        for (const question of section.questions) {
            index.set(question.code, text(question.text))
        }
    }

    return index
})

interface Gap {
    key: string
    questionCode: string
    pathogen: string | null
    response: 'P' | 'N'
}

/**
 * Every Partial and No, in question order.
 *
 * Derived from the answers rather than from the findings, so a gap with nothing
 * written against it still appears. A gap the assessor has not described is the
 * one worth showing.
 */
const gaps = computed<Gap[]>(() => {
    const found: Gap[] = []

    for (const section of props.template.sections) {
        const tally = props.result.sections.find((entry) => entry.code === section.code)

        if (tally?.applicable === false) {
            continue
        }

        const instances =
            section.scope === 'pathogen' ? props.result.pathogens.map((p) => p.key) : [null]

        for (const question of section.questions) {
            for (const pathogen of instances) {
                const key = `${question.code}|${pathogen ?? ''}`
                const answer = props.answersByKey.get(key)

                if (answer === 'P' || answer === 'N') {
                    found.push({ key, questionCode: question.code, pathogen, response: answer })
                }
            }
        }
    }

    return found
})

const described = computed(
    () =>
        gaps.value.filter((gap) =>
            (props.findings.get(gap.key) ?? []).some((finding) => finding.gap.trim() !== ''),
        ).length,
)

/**
 * Questions grouped by section, for whichever list is being shown.
 *
 * Unanswered and unexplained are two different lists with the same shape, and
 * they are two different things to fix: one needs an answer, the other needs
 * words against an answer already given.
 */
function groupBySection(keys: string[]) {
    const bySection = new Map<string, { code: string; title: string; count: number }>()

    for (const key of keys) {
        const code = key.split('|')[0] ?? ''
        const section = props.template.sections.find((entry) =>
            entry.questions.some((question) => question.code === code),
        )

        if (section === undefined) {
            continue
        }

        const existing = bySection.get(section.code)

        if (existing) {
            existing.count += 1
        } else {
            bySection.set(section.code, {
                code: section.code,
                title: text(section.title),
                count: 1,
            })
        }
    }

    return [...bySection.values()]
}

/**
 * Every section, whether or not anything is wrong with it.
 *
 * This replaced three lists — unanswered, unexplained, and the score tally —
 * which between them showed only the sections with a problem. A section that
 * was finished simply vanished, so the one thing the screen could not tell an
 * assessor was how much of the visit was done.
 *
 * Progress first, score second. Mid-visit the question is what is left; the
 * score is not a fact yet, because an unanswered question counts as zero
 * against the visit until it is answered.
 */
const overview = computed(() => {
    const missing = new Map<string, number>()
    const unexplained = new Map<string, number>()

    for (const entry of groupBySection(props.result.missing)) {
        missing.set(entry.code, entry.count)
    }

    for (const entry of groupBySection(props.result.missingNotes)) {
        unexplained.set(entry.code, entry.count)
    }

    return props.result.sections.map((tally) => {
        const answered = tally.answered + tally.excluded
        const outstanding = missing.get(tally.code) ?? 0

        return {
            code: tally.code,
            number: tally.number,
            title: text(props.template.sections.find((s) => s.code === tally.code)?.title),
            applicable: tally.applicable,
            answered,
            expected: answered + outstanding,
            outstanding,
            needsNote: unexplained.get(tally.code) ?? 0,
            score: tally.score,
            possible: tally.possible,
        }
    })
})

/**
 * Both gates, in one place, matching what the server refuses on.
 *
 * The device disabling its own button was the only thing enforcing this until
 * now, which made the rule a property of one build rather than of the record.
 * The server checks the same two things; this is what keeps the assessor from
 * meeting that refusal at the end of a long day.
 */
const submittable = computed(
    () => props.result.isComplete && props.result.missingNotes.length === 0,
)

function findingsOf(gap: Gap): StoredFinding[] {
    return props.findings.get(gap.key) ?? []
}

/** The first line of each described gap, for the collapsed row. */
function summaryOf(gap: Gap): string {
    return findingsOf(gap)
        .map((finding) => finding.gap.trim())
        .filter((text) => text !== '')
        .join(' · ')
}
</script>

<template>
    <!--
        Two columns from 900px, and the split follows the existing order rather
        than rearranging it: what is already at the top goes left, the rest goes
        right. So the phone reads exactly as it did.

        The left column is sticky. Describing a gap changes the score, and on a
        long list that feedback is otherwise several screens away from the thing
        causing it.
    -->
    <div
        class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground min-[900px]:max-w-[1100px] min-[900px]:px-6"
    >
        <header class="px-4 pb-3 pt-4 min-[900px]:px-0 min-[900px]:pt-6">
            <button type="button" class="mb-2 text-[15px] text-accent" @click="emit('back')">
                {{ t('review.back') }}
            </button>
            <h1 class="text-[30px] font-bold tracking-tight">{{ t('review.title') }}</h1>
            <p class="mt-0.5 text-[13px] text-label-2">{{ siteName }}</p>
        </header>

        <main
            class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 min-[900px]:grid min-[900px]:grid-cols-[19rem_minmax(0,1fr)] min-[900px]:items-start min-[900px]:gap-6 min-[900px]:px-0"
        >
          <div class="min-[900px]:sticky min-[900px]:top-0">
            <!-- The score, stated once and plainly. -->
            <div
                class="mb-4 flex items-end justify-between rounded-card bg-surface px-4 py-4 min-[900px]:rounded-surface min-[900px]:shadow-surface"
            >
                <div>
                    <div class="tnum text-[34px] font-bold leading-none tracking-tight">
                        {{
                            result.percentage === null
                                ? '—'
                                : formatPercent(result.percentage, result.roundDp)
                        }}
                    </div>
                    <div class="tnum mt-1 text-[13px] text-label-2">
                        {{
                            t('review.points', {
                                score: result.totalScore,
                                possible: result.totalPossible,
                            })
                        }}
                    </div>
                </div>
                <span
                    class="rounded-full px-3 py-1.5 text-[15px] font-semibold"
                    :class="
                        result.level === null
                            ? 'bg-track text-label-2'
                            : result.level >= 4
                              ? 'bg-yes-soft text-yes'
                              : result.level === 3
                                ? 'bg-accent-soft text-accent'
                                : result.level === 2
                                  ? 'bg-partial-soft text-partial'
                                  : 'bg-no-soft text-no'
                    "
                >
                    {{
                        result.level === null
                            ? t('score.notScorable')
                            : t('score.level', { level: result.level })
                    }}
                </span>
            </div>

            <!--
                Every section, done or not. Tapping one goes there.
            -->
            <section class="mb-4">
                <h2 class="px-1 pb-1.5 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                    {{ t('review.overview') }}
                </h2>
                <div class="overflow-hidden rounded-card bg-surface">
                    <button
                        v-for="(row, index) in overview"
                        :key="row.code"
                        type="button"
                        class="flex w-full items-center gap-3 px-3.5 py-3 text-left"
                        :class="index > 0 ? 'border-t border-hairline' : ''"
                        @click="emit('jump', row.code, null)"
                    >
                        <span class="tnum shrink-0 text-[13px] font-semibold text-label-3">
                            {{ row.number }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[15px]"
                                :class="row.applicable ? '' : 'text-label-3'"
                            >
                                {{ row.title }}
                            </span>

                            <span v-if="!row.applicable" class="block text-[13px] text-label-3">
                                {{ t('review.notApplicable') }}
                            </span>
                            <span v-else class="tnum block text-[13px] text-label-2">
                                {{ t('checklist.answered', { answered: row.answered, total: row.expected }) }}
                                <template v-if="row.outstanding === 0">
                                    · {{ formatNumber(row.score) }}/{{ formatNumber(row.possible) }}
                                </template>
                            </span>
                        </span>

                        <!-- Only what is outstanding gets a colour. A finished
                             section needs no mark; that it is finished is said
                             by the count beside it. -->
                        <span class="flex shrink-0 flex-col items-end gap-0.5">
                            <span
                                v-if="row.outstanding > 0"
                                class="tnum rounded-full bg-no-soft px-2 py-0.5 text-[12px] font-semibold text-no"
                            >
                                {{ t('review.outstanding', { count: row.outstanding }) }}
                            </span>
                            <span
                                v-if="row.needsNote > 0"
                                class="tnum rounded-full bg-partial-soft px-2 py-0.5 text-[12px] font-semibold text-partial"
                            >
                                {{ t('review.noteCount', { count: row.needsNote }) }}
                            </span>
                        </span>
                    </button>
                </div>
            </section>

          </div>

          <div>
            <!-- Gaps: every Partial and No, described for the site. -->
            <section class="mb-4">
                <h2
                    class="flex items-baseline justify-between px-1 pb-1.5 text-[13px] font-semibold uppercase tracking-wide text-label-2"
                >
                    <span>{{ t('review.gaps') }}</span>
                    <span class="tnum normal-case">
                        {{ t('review.described', { described, total: gaps.length }) }}
                    </span>
                </h2>

                <div v-if="gaps.length > 0" class="overflow-hidden rounded-card bg-surface">
                    <div
                        v-for="(gap, index) in gaps"
                        :key="gap.key"
                        :class="index > 0 ? 'border-t border-hairline' : ''"
                    >
                        <button
                            type="button"
                            class="flex w-full items-start gap-2.5 px-3.5 py-3 text-left"
                            @click="open = open === gap.key ? null : gap.key"
                        >
                            <span
                                class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold"
                                :class="
                                    gap.response === 'N'
                                        ? 'bg-no-soft text-no'
                                        : 'bg-partial-soft text-partial'
                                "
                            >
                                {{ gap.response }}
                            </span>
                            <span class="flex-1">
                                <span class="tnum text-[13px] text-label-2">
                                    {{ gap.questionCode }}<template v-if="gap.pathogen">
                                        · {{ gap.pathogen }}</template
                                    >
                                </span>
                                <span class="block text-[15px] leading-snug">
                                    {{ questionText.get(gap.questionCode) }}
                                </span>
                                <span
                                    v-if="summaryOf(gap) !== ''"
                                    class="mt-1 block text-[13px] text-label-2"
                                >
                                    {{ summaryOf(gap) }}
                                </span>
                                <span v-else class="mt-1 block text-[13px] font-medium text-accent">
                                    {{ t('question.describeGap') }}
                                </span>
                            </span>
                        </button>

                        <!-- One block per finding. A single No can hide more
                             than one problem, and each needs its own action,
                             owner and date. -->
                        <div v-if="open === gap.key" class="flex flex-col gap-4 px-3.5 pb-3.5">
                            <div
                                v-for="(finding, index) in findingsOf(gap)"
                                :key="finding.key"
                                class="flex flex-col gap-2.5"
                                :class="index > 0 ? 'border-t border-hairline pt-3.5' : ''"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="text-[12px] font-semibold uppercase tracking-wide text-label-2">
                                        {{ t('review.gapNumber', { number: index + 1 }) }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-[13px] text-no"
                                        @click="emit('removeFinding', finding.key)"
                                    >
                                        {{ t('action.remove') }}
                                    </button>
                                </div>

                                <textarea
                                    :value="finding.gap"
                                    rows="2"
                                    :placeholder="t('review.gapPlaceholder')"
                                    class="scroll-thin w-full resize-none rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                                    @change="
                                        emit('finding', finding.key, {
                                            gap: ($event.target as HTMLTextAreaElement).value,
                                        })
                                    "
                                ></textarea>

                                <textarea
                                    :value="finding.recommendation"
                                    rows="2"
                                    :placeholder="t('review.recommendationPlaceholder')"
                                    class="scroll-thin w-full resize-none rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                                    @change="
                                        emit('finding', finding.key, {
                                            recommendation: ($event.target as HTMLTextAreaElement).value,
                                        })
                                    "
                                ></textarea>

                                <!-- When. A separate axis from who, and from the
                                     due date: "immediate" means before the
                                     assessor leaves, which a date cannot say.
                                     Nothing is preselected, because a default
                                     would invent a judgement. -->
                                <div>
                                    <span class="mb-1 block px-0.5 text-[13px] text-label-2">
                                        {{ t('review.howUrgent') }}
                                    </span>
                                    <div class="flex gap-1.5">
                                        <button
                                            v-for="option in URGENCY"
                                            :key="option.key"
                                            type="button"
                                            class="rounded-full px-3 py-1.5 text-[13px] font-medium"
                                            :class="
                                                finding.urgency === option.key
                                                    ? 'bg-accent text-white'
                                                    : 'bg-ground text-label-2'
                                            "
                                            @click="
                                                emit('finding', finding.key, {
                                                    urgency:
                                                        finding.urgency === option.key ? null : option.key,
                                                })
                                            "
                                        >
                                            {{ t(option.label) }}
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <span class="mb-1 block px-0.5 text-[13px] text-label-2">
                                        {{ t('review.whoActs') }}
                                    </span>
                                    <div class="scroll-thin flex gap-1.5 overflow-x-auto">
                                        <button
                                            v-for="level in RESPONSIBILITY"
                                            :key="level.key"
                                            type="button"
                                            class="shrink-0 rounded-full px-3 py-1.5 text-[13px] font-medium"
                                            :class="
                                                finding.responsibilityLevel === level.key
                                                    ? 'bg-accent text-white'
                                                    : 'bg-ground text-label-2'
                                            "
                                            @click="
                                                emit('finding', finding.key, {
                                                    responsibilityLevel: level.key,
                                                })
                                            "
                                        >
                                            {{ t(level.label) }}
                                        </button>
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <input
                                        :value="finding.responsiblePerson"
                                        type="text"
                                        :placeholder="t('review.responsiblePerson')"
                                        class="w-full rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                                        @change="
                                            emit('finding', finding.key, {
                                                responsiblePerson: ($event.target as HTMLInputElement).value,
                                            })
                                        "
                                    />
                                    <input
                                        :value="finding.dueDate ?? ''"
                                        type="date"
                                        class="tnum shrink-0 rounded-lg bg-ground px-3 py-2 text-[15px] outline-none"
                                        @change="
                                            emit('finding', finding.key, {
                                                dueDate: ($event.target as HTMLInputElement).value || null,
                                            })
                                        "
                                    />
                                </div>
                            </div>

                            <button
                                type="button"
                                class="rounded-lg bg-ground py-2.5 text-[14px] font-medium text-accent"
                                @click="emit('addFinding', gap.questionCode, gap.pathogen)"
                            >
                                {{
                                    findingsOf(gap).length === 0
                                        ? t('review.describeThisGap')
                                        : t('review.addAnotherGap')
                                }}
                            </button>
                        </div>
                    </div>
                </div>

                <p v-else class="px-1 text-[15px] text-label-2">{{ t('review.noGaps') }}</p>
            </section>

            <!-- Signed after the gaps have been read out, not before. -->
            <SignatureSection
                v-if="assessmentId !== ''"
                :assessment-id="assessmentId"
                :context="context"
            />

          </div>
        </main>

        <footer
            class="border-t border-hairline bg-surface px-4 pb-4 pt-3 min-[900px]:rounded-t-surface min-[900px]:border-x min-[900px]:px-6"
        >
            <p v-if="submitError !== ''" class="pb-2 text-[13px] font-medium text-no">
                {{ submitError }}
            </p>

            <button
                type="button"
                class="w-full rounded-card bg-accent py-3.5 text-[17px] font-semibold text-white transition-opacity disabled:opacity-40 min-[900px]:mx-auto min-[900px]:block min-[900px]:w-auto min-[900px]:px-16"
                :disabled="!submittable || submitting"
                @click="emit('submit')"
            >
                {{ submitting ? t('review.submitting') : t('review.submit') }}
            </button>

            <p v-if="!result.isComplete" class="tnum pt-2 text-center text-[13px] text-label-2">
                {{ t('review.stillNeeded', { count: result.missing.length }) }}
            </p>
            <p
                v-else-if="result.missingNotes.length > 0"
                class="tnum pt-2 text-center text-[13px] text-label-2"
            >
                {{ t('review.notesNeeded', { count: result.missingNotes.length }) }}
            </p>
        </footer>
    </div>
</template>
