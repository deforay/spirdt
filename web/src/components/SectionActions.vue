<script setup lang="ts">
import { PhPlus, PhTrash } from '@phosphor-icons/vue'
import { computed } from 'vue'

import type { StoredFinding } from '@/db/database'
import { t, text as localised } from '@/i18n'
import type { Section } from '@/scoring/types'

/**
 * Corrective actions, at the end of the section they belong to.
 *
 * This is where the predecessor asked for them and where the User's Guide has
 * the debrief happen — the assessor works a section, sees what is missing, and
 * agrees with the site what will be done about it while standing in the room.
 *
 * They used to be collected on the review screen instead, which produced two
 * problems. An assessor described the same shortfall twice, once as a comment
 * under the question and again as a gap at the end of the visit. And the
 * review screen grew a form the length of the whole visit: fifty gaps there is
 * fifty editors on one page, at the end of a long day.
 *
 * Each action still names its question, which the predecessor's did not. A
 * site's action list saying "2.1 — no designated collection area" is worth
 * chasing; one saying "Section 2" is not. It is also what lets an action
 * disappear on its own when the answer it hangs on stops being a gap.
 *
 * Urgency is per action rather than per block. The old form had two lists,
 * Immediate and Follow-up, which is a layout rather than a data model: it
 * cannot say that one gap in a section is urgent and another is not.
 */

const props = defineProps<{
    section: Section
    /** The pathogen instance being answered, for a section that repeats. */
    pathogen: string | null
    /** Response by question code, so this knows which questions are gaps. */
    responseFor: (questionCode: string, pathogen: string | null) => string | null
    /** Findings for one question, in the order they were added. */
    findingsFor: (questionCode: string, pathogen: string | null) => StoredFinding[]
}>()

const emit = defineEmits<{
    add: [questionCode: string, pathogen: string | null]
    update: [key: string, patch: Partial<Omit<StoredFinding, 'key' | 'assessmentId'>>]
    remove: [key: string]
}>()

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

/**
 * The questions in this section that need an action, in question order.
 *
 * Derived from the answers rather than from the findings, so a gap with
 * nothing written against it still appears. That is the one worth showing.
 */
const gaps = computed(() =>
    props.section.questions
        .map((question) => ({
            question,
            response: props.responseFor(question.code, props.pathogen),
        }))
        .filter((entry) => entry.response === 'P' || entry.response === 'N'),
)

const described = computed(
    () =>
        gaps.value.filter((gap) =>
            props.findingsFor(gap.question.code, props.pathogen).some(
                (finding) => finding.gap.trim() !== '',
            ),
        ).length,
)
</script>

<template>
    <!-- Nothing to do when the section has no gaps, and saying so would be a
         heading over an empty box. -->
    <section v-if="gaps.length > 0" class="mt-4">
        <h2 class="flex items-baseline justify-between px-1 pb-1.5 text-[14px] font-semibold uppercase tracking-wide text-label-2">
            <span>{{ t('actions.heading') }}</span>
            <span class="tnum normal-case">
                {{ t('review.described', { described, total: gaps.length }) }}
            </span>
        </h2>

        <div class="overflow-hidden rounded-surface border border-hairline bg-surface">
            <div
                v-for="(gap, index) in gaps"
                :key="gap.question.code"
                :class="index > 0 ? 'border-t border-hairline' : ''"
            >
                <div class="flex items-start gap-2.5 px-3.5 pt-3">
                    <span
                        class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[12px] font-semibold"
                        :class="gap.response === 'N' ? 'bg-no-soft text-no' : 'bg-partial-soft text-partial'"
                    >
                        {{ gap.response }}
                    </span>
                    <span class="tnum shrink-0 pt-0.5 font-mono text-xs text-label-3">
                        {{ gap.question.code }}
                    </span>
                    <span class="flex-1 text-[14px] leading-snug text-label-2">
                        {{ localised(gap.question.text) }}
                    </span>
                </div>

                <div
                    v-for="finding in findingsFor(gap.question.code, pathogen)"
                    :key="finding.key"
                    class="flex flex-col gap-2 px-3.5 py-3"
                >
                    <textarea
                        :value="finding.gap"
                        rows="2"
                        :placeholder="t('review.gapPlaceholder')"
                        class="field"
                        @change="emit('update', finding.key, { gap: ($event.target as HTMLTextAreaElement).value })"
                    ></textarea>

                    <input
                        :value="finding.recommendation"
                        type="text"
                        :placeholder="t('review.recommendationPlaceholder')"
                        class="field"
                        @change="emit('update', finding.key, { recommendation: ($event.target as HTMLInputElement).value })"
                    />

                    <!-- When. Blank stays blank: nobody said is not the same as
                         follow-up, and defaulting would invent a judgement the
                         assessor never made. -->
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="option in URGENCY"
                            :key="option.key"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-[14px] font-medium"
                            :class="finding.urgency === option.key ? 'bg-accent text-accent-ink' : 'bg-ground text-label-2'"
                            @click="emit('update', finding.key, { urgency: finding.urgency === option.key ? null : option.key })"
                        >
                            {{ t(option.label) }}
                        </button>
                    </div>

                    <!-- Who. Many gaps are not the site's to fix, and one filed
                         against a site that cannot act on it never closes. -->
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="level in RESPONSIBILITY"
                            :key="level.key"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-[14px] font-medium"
                            :class="finding.responsibilityLevel === level.key ? 'bg-label text-ground' : 'bg-ground text-label-2'"
                            @click="emit('update', finding.key, { responsibilityLevel: level.key })"
                        >
                            {{ t(level.label) }}
                        </button>
                    </div>

                    <div class="flex gap-2">
                        <input
                            :value="finding.responsiblePerson"
                            type="text"
                            :placeholder="t('review.responsiblePerson')"
                            class="field"
                            @change="emit('update', finding.key, { responsiblePerson: ($event.target as HTMLInputElement).value })"
                        />
                        <input
                            :value="finding.dueDate ?? ''"
                            type="date"
                            class="field tnum w-auto shrink-0"
                            @change="emit('update', finding.key, { dueDate: ($event.target as HTMLInputElement).value || null })"
                        />
                    </div>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 self-start text-[14px] text-no"
                        @click="emit('remove', finding.key)"
                    >
                        <PhTrash :size="14" aria-hidden="true" />
                        {{ t('action.remove') }}
                    </button>
                </div>

                <!-- One No can hide more than one problem: no SOP, and staff
                     untrained on the one that is missing. Each needs its own
                     owner and date. -->
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 px-3.5 pb-3 pt-1 text-left text-[14px] text-accent"
                    @click="emit('add', gap.question.code, pathogen)"
                >
                    <PhPlus :size="14" aria-hidden="true" />
                    {{
                        findingsFor(gap.question.code, pathogen).length === 0
                            ? t('review.describeThisGap')
                            : t('review.addAnotherGap')
                    }}
                </button>
            </div>
        </div>
    </section>
</template>
