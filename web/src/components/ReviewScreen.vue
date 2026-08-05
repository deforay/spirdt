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
 * The completeness gate. A running percentage is computed only over answered
 * questions, so a half-finished assessment reads HIGH, not low — 12 of 12
 * answered Yes is 100% whether or not the other 47 exist. That is correct while
 * working and dangerous the moment it reaches a certificate, so submission is
 * refused until every expected question has an answer.
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
    findings: Map<string, StoredFinding>
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
    finding: [
        questionCode: string,
        pathogen: string | null,
        patch: Partial<Omit<StoredFinding, 'key' | 'assessmentId'>>,
    ]
    submit: []
}>()

const open = ref<string | null>(null)

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
    () => gaps.value.filter((gap) => (props.findings.get(gap.key)?.gap ?? '').trim() !== '').length,
)

/** Missing questions grouped by section, so the list is navigable rather than long. */
const missingBySection = computed(() => {
    const bySection = new Map<string, { code: string; title: string; count: number }>()

    for (const key of props.result.missing) {
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
})

function findingOf(gap: Gap): Partial<StoredFinding> {
    return props.findings.get(gap.key) ?? {}
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground">
        <header class="px-4 pb-3 pt-4">
            <button type="button" class="mb-2 text-[15px] text-accent" @click="emit('back')">
                {{ t('review.back') }}
            </button>
            <h1 class="text-[30px] font-bold tracking-tight">{{ t('review.title') }}</h1>
            <p class="mt-0.5 text-[13px] text-label-2">{{ siteName }}</p>
        </header>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
            <!-- The score, stated once and plainly. -->
            <div class="mb-4 flex items-end justify-between rounded-card bg-surface px-4 py-4">
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

            <!-- Unanswered questions block submission, so they come first. -->
            <section v-if="missingBySection.length > 0" class="mb-4">
                <h2 class="px-1 pb-1.5 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                    {{ t('review.unanswered') }}
                </h2>
                <div class="overflow-hidden rounded-card bg-surface">
                    <button
                        v-for="(section, index) in missingBySection"
                        :key="section.code"
                        type="button"
                        class="flex w-full items-center justify-between px-3.5 py-3 text-left"
                        :class="index > 0 ? 'border-t border-hairline' : ''"
                        @click="emit('jump', section.code, null)"
                    >
                        <span class="text-[17px]">{{ section.title }}</span>
                        <span class="tnum text-[15px] font-semibold text-no">
                            {{ formatNumber(section.count) }}
                        </span>
                    </button>
                </div>
                <p class="px-1 pt-1.5 text-[13px] text-label-2">
                    {{ t('review.unansweredNote') }}
                </p>
            </section>

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
                                    v-if="(findingOf(gap).gap ?? '').trim() !== ''"
                                    class="mt-1 block text-[13px] text-label-2"
                                >
                                    {{ findingOf(gap).gap }}
                                </span>
                                <span v-else class="mt-1 block text-[13px] font-medium text-accent">
                                    {{ t('question.describeGap') }}
                                </span>
                            </span>
                        </button>

                        <div v-if="open === gap.key" class="flex flex-col gap-2.5 px-3.5 pb-3.5">
                            <textarea
                                :value="findingOf(gap).gap ?? ''"
                                rows="2"
                                :placeholder="t('review.gapPlaceholder')"
                                class="scroll-thin w-full resize-none rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                                @change="
                                    emit('finding', gap.questionCode, gap.pathogen, {
                                        gap: ($event.target as HTMLTextAreaElement).value,
                                    })
                                "
                            ></textarea>

                            <textarea
                                :value="findingOf(gap).recommendation ?? ''"
                                rows="2"
                                :placeholder="t('review.recommendationPlaceholder')"
                                class="scroll-thin w-full resize-none rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                                @change="
                                    emit('finding', gap.questionCode, gap.pathogen, {
                                        recommendation: ($event.target as HTMLTextAreaElement).value,
                                    })
                                "
                            ></textarea>

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
                                            (findingOf(gap).responsibilityLevel ?? 'site') === level.key
                                                ? 'bg-accent text-white'
                                                : 'bg-ground text-label-2'
                                        "
                                        @click="
                                            emit('finding', gap.questionCode, gap.pathogen, {
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
                                    :value="findingOf(gap).responsiblePerson ?? ''"
                                    type="text"
                                    :placeholder="t('review.responsiblePerson')"
                                    class="w-full rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
                                    @change="
                                        emit('finding', gap.questionCode, gap.pathogen, {
                                            responsiblePerson: ($event.target as HTMLInputElement).value,
                                        })
                                    "
                                />
                                <input
                                    :value="findingOf(gap).dueDate ?? ''"
                                    type="date"
                                    class="tnum shrink-0 rounded-lg bg-ground px-3 py-2 text-[15px] outline-none"
                                    @change="
                                        emit('finding', gap.questionCode, gap.pathogen, {
                                            dueDate: ($event.target as HTMLInputElement).value || null,
                                        })
                                    "
                                />
                            </div>
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

            <!-- Sections, for the debrief. -->
            <section class="mb-4">
                <h2 class="px-1 pb-1.5 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                    {{ t('review.bySection') }}
                </h2>
                <div class="overflow-hidden rounded-card bg-surface">
                    <div
                        v-for="(tally, index) in result.sections"
                        :key="tally.code"
                        class="flex items-center justify-between px-3.5 py-2.5"
                        :class="index > 0 ? 'border-t border-hairline' : ''"
                    >
                        <span class="text-[15px]" :class="tally.applicable ? '' : 'text-label-3'">
                            {{ text(template.sections.find((s) => s.code === tally.code)?.title) }}
                        </span>
                        <span v-if="!tally.applicable" class="text-[13px] text-label-3">
                            {{ t('review.notApplicable') }}
                        </span>
                        <span v-else class="tnum text-[15px] font-semibold">
                            {{ formatNumber(tally.score) }} / {{ formatNumber(tally.possible) }}
                        </span>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-hairline bg-surface px-4 pb-4 pt-3">
            <p v-if="submitError !== ''" class="pb-2 text-[13px] font-medium text-no">
                {{ submitError }}
            </p>

            <button
                type="button"
                class="w-full rounded-card bg-accent py-3.5 text-[17px] font-semibold text-white transition-opacity disabled:opacity-40"
                :disabled="!result.isComplete || submitting"
                @click="emit('submit')"
            >
                {{ submitting ? t('review.submitting') : t('review.submit') }}
            </button>

            <p v-if="!result.isComplete" class="tnum pt-2 text-center text-[13px] text-label-2">
                {{ t('review.stillNeeded', { count: result.missing.length }) }}
            </p>
        </footer>
    </div>
</template>
