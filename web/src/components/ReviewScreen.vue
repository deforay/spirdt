<script setup lang="ts">
import { computed } from 'vue'

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

/** Same ramp as ScoreBadge and the checklist footer. See web/DESIGN.md. */
const LEVEL_TONES: Record<number, string> = {
    0: 'bg-level-0 text-level-0-ink',
    1: 'bg-level-1 text-level-1-ink',
    2: 'bg-level-2 text-level-2-ink',
    3: 'bg-level-3 text-level-3-ink',
    4: 'bg-level-4 text-level-4-ink',
}

const props = defineProps<{
    template: Template
    result: ScoreResult
    /** Several per question, keyed by `${questionCode}|${pathogen}`. */
    findings: Map<string, StoredFinding[]>
    /** Response by `${questionCode}|${pathogen}`. Passed in rather than re-derived. */
    answersByKey: Map<string, string | null>
    siteName: string
    /** Which round this audit belongs to. Blank when the programme runs none. */
    auditRound?: string
    /** The visit this review belongs to, and its Part A answers, for signatures. */
    assessmentId: string
    context: Record<string, unknown>
    submitting: boolean
    submitError: string
}>()

const emit = defineEmits<{
    back: []
    /** Open Part A, so a wrong facility detail can be corrected from here too. */
    editSetup: []
    jump: [sectionCode: string, pathogen: string | null]
    submit: []
}>()

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

/** Which section a question belongs to, so a row can jump to it. */
function sectionOf(questionCode: string): string {
    return (
        props.template.sections.find((section) =>
            section.questions.some((question) => question.code === questionCode),
        )?.code ?? ''
    )
}

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
        class="mx-auto flex min-h-screen w-full flex-col bg-ground sm:max-w-[680px] min-[900px]:max-w-[1536px] min-[900px]:px-6"
    >
        <header class="px-4 pb-3 pt-4 min-[900px]:px-0 min-[900px]:pt-6">
            <div class="mb-2 flex items-center gap-3">
                <button type="button" class="text-[16px] text-accent" @click="emit('back')">
                    {{ t('review.back') }}
                </button>
                <!-- Part A is reachable from here as well. A wrong interviewee
                     name is noticed while reading the review, which is the one
                     screen that shows the visit as a whole. -->
                <button
                    type="button"
                    class="text-[16px] text-accent"
                    @click="emit('editSetup')"
                >
                    {{ t('checklist.editSetup') }}
                </button>
            </div>
            <h1 class="text-[32px] font-bold tracking-tight">{{ t('review.title') }}</h1>
            <p class="mt-0.5 text-[14px] text-label-2">
                {{ siteName }}
                <!-- Said on the last screen before submission, because this is
                     the one field on the setup form that nothing downstream can
                     infer or correct: a wrong date is visible in the record, a
                     wrong round is only visible to whoever knows which pass
                     this was. -->
                <span v-if="auditRound">
                    · {{ t('review.auditRound') }} {{ auditRound }}
                </span>
            </p>
        </header>

        <main
            class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 min-[900px]:grid min-[900px]:grid-cols-[21rem_minmax(0,1fr)] min-[900px]:items-start min-[900px]:gap-6 min-[900px]:px-0"
        >
          <div class="min-[900px]:sticky min-[900px]:top-0">
            <!-- The score, stated once and plainly. -->
            <div
                class="mb-5 flex items-end justify-between rounded-surface border border-hairline bg-surface p-5"
            >
                <div>
                    <div class="tnum text-[36px] font-bold leading-none tracking-tight">
                        {{
                            result.percentage === null
                                ? '—'
                                : formatPercent(result.percentage, result.roundDp)
                        }}
                    </div>
                    <div class="tnum mt-1 text-[14px] text-label-2">
                        {{
                            t('review.points', {
                                score: result.totalScore,
                                possible: result.totalPossible,
                            })
                        }}
                    </div>
                </div>
                <!-- The level ramp, not the response palette. Green for a
                     Level 4 and red for a Level 0 spends the three colours
                     that mean Yes, Partial and No on the questions this very
                     screen is reviewing. -->
                <span
                    class="tnum rounded-full px-3.5 py-1.5 text-[15px] font-semibold"
                    :class="LEVEL_TONES[result.level ?? -1] ?? 'bg-track text-label-2'"
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
                <h2 class="eyebrow pb-2.5 text-label-3">
                    {{ t('review.overview') }}
                </h2>
                <div class="overflow-hidden rounded-surface border border-hairline bg-surface">
                    <button
                        v-for="(row, index) in overview"
                        :key="row.code"
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors hover:bg-surface-2"
                        :class="index > 0 ? 'border-t border-hairline' : ''"
                        @click="emit('jump', row.code, null)"
                    >
                        <span class="tnum shrink-0 text-[14px] font-semibold text-label-3">
                            {{ row.number }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[16px]"
                                :class="row.applicable ? '' : 'text-label-3'"
                            >
                                {{ row.title }}
                            </span>

                            <span v-if="!row.applicable" class="block text-[14px] text-label-3">
                                {{ t('review.notApplicable') }}
                            </span>
                            <span v-else class="tnum block text-[14px] text-label-2">
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
                                class="chip tnum bg-no-soft text-no"
                            >
                                {{ t('review.outstanding', { count: row.outstanding }) }}
                            </span>
                            <span
                                v-if="row.needsNote > 0"
                                class="chip tnum bg-partial-soft text-partial"
                            >
                                {{ t('review.noteCount', { count: row.needsNote }) }}
                            </span>
                        </span>
                    </button>
                </div>
            </section>

          </div>

          <div>
            <!--
                What was found, read-only.

                The gaps are described at the end of each section now, where
                the assessor is standing when they find them. This screen used
                to collect them, which meant the same shortfall was written
                twice — once as a comment under the question and again here —
                and meant a visit with fifty gaps arrived at a page carrying
                fifty editors. Reading them back before signing is a different
                job from writing them, and it is the one this screen has.
            -->
            <section class="mb-4">
                <h2 class="flex items-baseline justify-between gap-3 pb-2.5">
                    <span class="eyebrow text-label-3">{{ t('review.gaps') }}</span>
                    <span class="tnum text-[13px] text-label-3">
                        {{ t('review.described', { described, total: gaps.length }) }}
                    </span>
                </h2>

                <div v-if="gaps.length > 0" class="overflow-hidden rounded-surface border border-hairline bg-surface">
                    <button
                        v-for="(gap, index) in gaps"
                        :key="gap.key"
                        type="button"
                        class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-surface-2"
                        :class="index > 0 ? 'border-t border-hairline' : ''"
                        @click="emit('jump', sectionOf(gap.questionCode), gap.pathogen)"
                    >
                        <span
                            class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[12px] font-semibold"
                            :class="gap.response === 'N' ? 'bg-no-soft text-no' : 'bg-partial-soft text-partial'"
                        >
                            {{ gap.response }}
                        </span>
                        <span class="tnum shrink-0 pt-0.5 font-mono text-xs text-label-3">
                            {{ gap.questionCode }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-[16px] leading-snug">
                                {{ questionText.get(gap.questionCode) }}
                            </span>

                            <!-- The action itself, or the absence of one. An
                                 undescribed gap is the thing worth showing. -->
                            <span
                                v-if="summaryOf(gap) !== ''"
                                class="mt-0.5 block text-[14px] text-label-2"
                            >
                                {{ summaryOf(gap) }}
                            </span>
                            <span v-else class="mt-0.5 block text-[14px] font-medium text-no">
                                {{ t('review.notDescribed') }}
                            </span>
                        </span>
                    </button>
                </div>

                <p v-else class="px-1 text-[16px] text-label-2">{{ t('review.noGaps') }}</p>
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
            <p v-if="submitError !== ''" class="pb-2 text-[14px] font-medium text-no">
                {{ submitError }}
            </p>

            <button
                type="button"
                class="w-full rounded-card bg-accent py-3.5 text-[17px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40 min-[900px]:mx-auto min-[900px]:block min-[900px]:w-auto min-[900px]:px-12"
                :disabled="!submittable || submitting"
                @click="emit('submit')"
            >
                {{ submitting ? t('review.submitting') : t('review.submit') }}
            </button>

            <p v-if="!result.isComplete" class="tnum pt-2 text-center text-[14px] text-label-2">
                {{ t('review.stillNeeded', { count: result.missing.length }) }}
            </p>
            <p
                v-else-if="result.missingNotes.length > 0"
                class="tnum pt-2 text-center text-[14px] text-label-2"
            >
                {{ t('review.notesNeeded', { count: result.missingNotes.length }) }}
            </p>
        </footer>
    </div>
</template>
