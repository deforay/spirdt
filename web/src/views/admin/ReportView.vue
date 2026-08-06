<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import { fetchReport, type Report } from '@/api/reports'
import AdminShell from '@/components/admin/AdminShell.vue'
import ScoreBadge from '@/components/admin/ScoreBadge.vue'
import { formatPercent, locale, t } from '@/i18n'

/**
 * One visit, in full — the thing that gets handed to the site.
 *
 * It shows what was RECORDED rather than a summary of it. Every question in
 * the instrument appears whether or not it was answered, because a report that
 * quietly omits what was skipped is the one way this screen could mislead the
 * people it is written for. An unanswered question is a fact about the visit.
 *
 * It prints. That is not a nicety: a copy is left behind at the end of a visit
 * and signed, and in most of the places this runs that copy is paper. The
 * print rules live at the bottom of this file rather than in a stylesheet
 * somebody has to know to look for.
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

/** Bound rather than called inline: the template scope has no `window`. */
function print(): void {
    window.print()
}

/** The tone of a single answer, so a page of Ys and Ns can be read at a glance. */
function tone(response: string): string {
    switch (response) {
        case 'Y':
            return 'text-yes'
        case 'P':
            return 'text-partial'
        case 'N':
            return 'text-no'
        default:
            return 'text-label-3'
    }
}

async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        report.value = await fetchReport(String(route.params.id ?? ''), locale.value)
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
    <AdminShell :title="t('report.title')" :subtitle="report?.assessment.site.name ?? ''">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>
        <p v-else-if="loading" class="text-[14px] text-label-2">{{ t('admin.loading') }}</p>

        <template v-else-if="report">
            <div class="mb-4 flex justify-end print:hidden">
                <button
                    type="button"
                    class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
                    @click="print"
                >
                    {{ t('report.print') }}
                </button>
            </div>

            <!-- Where and when -->
            <section class="mb-4 rounded-card bg-surface p-4">
                <h2 class="text-[17px] font-semibold">
                    {{ report.assessment.facility.name }}
                </h2>
                <p class="text-[14px] text-label-2">
                    {{ report.assessment.site.name }}
                    <template v-if="report.assessment.site.location">
                        · {{ report.assessment.site.location }}
                    </template>
                </p>
                <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-[13px] sm:grid-cols-3">
                    <div v-if="report.assessment.facility.place">
                        <dt class="text-label-3">{{ t('report.place') }}</dt>
                        <dd>{{ report.assessment.facility.place }}</dd>
                    </div>
                    <div v-if="report.assessment.facility.code">
                        <dt class="text-label-3">{{ t('report.facilityCode') }}</dt>
                        <dd class="tnum">{{ report.assessment.facility.code }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-3">{{ t('report.assessedOn') }}</dt>
                        <dd class="tnum">{{ report.assessment.assessed_on }}</dd>
                    </div>
                    <div v-if="report.assessment.previous_assessed_on">
                        <dt class="text-label-3">{{ t('report.previousVisit') }}</dt>
                        <dd class="tnum">{{ report.assessment.previous_assessed_on }}</dd>
                    </div>
                    <div v-if="report.assessment.pathogens.length > 0">
                        <dt class="text-label-3">{{ t('report.pathogens') }}</dt>
                        <dd>
                            {{ report.assessment.pathogens.map((p) => p.name).join(', ') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-label-3">{{ t('report.status') }}</dt>
                        <dd>{{ report.assessment.status }}</dd>
                    </div>
                </dl>
            </section>

            <!-- The score -->
            <section v-if="scored" class="mb-4 rounded-card bg-surface p-4">
                <div class="flex flex-wrap items-baseline gap-3">
                    <span v-if="scored.percentage !== null" class="tnum text-[34px] font-semibold">
                        {{ formatPercent(scored.percentage, 2) }}
                    </span>
                    <span v-else class="text-[17px] text-label-2">{{ t('score.notScorable') }}</span>
                    <ScoreBadge :level="scored.level" />
                    <span class="tnum text-[13px] text-label-2">
                        {{ t('report.outOf', { score: scored.total_score, possible: scored.total_possible }) }}
                    </span>
                </div>
                <p v-if="scored.band?.description" class="mt-1 text-[14px] text-label-2">
                    {{ scored.band.description }}
                </p>

                <!-- Per section. Counts as well as a percentage: a section can
                     only be compared with another as a proportion, and can only
                     be checked as a pair of numbers. -->
                <table class="mt-4 w-full text-[13px]">
                    <thead class="text-left text-label-3">
                        <tr>
                            <th class="py-1 font-medium">{{ t('report.section') }}</th>
                            <th class="py-1 text-right font-medium">{{ t('report.scored') }}</th>
                            <th class="py-1 text-right font-medium">{{ t('report.excluded') }}</th>
                            <th class="py-1 text-right font-medium">{{ t('report.percent') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="section in scored.sections"
                            :key="section.number"
                            class="border-t border-hairline"
                        >
                            <td class="py-1.5">{{ section.number }}. {{ section.title }}</td>
                            <td class="tnum py-1.5 text-right">
                                {{ section.score }} / {{ section.possible }}
                            </td>
                            <td class="tnum py-1.5 text-right text-label-3">
                                {{ section.excluded > 0 ? section.excluded : '' }}
                            </td>
                            <td class="tnum py-1.5 text-right">
                                <template v-if="section.percentage !== null">
                                    {{ formatPercent(section.percentage, 2) }}
                                </template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p
                    v-if="scored.anomalies.unexpected.length > 0 || scored.anomalies.violations.length > 0"
                    class="mt-3 text-[12px] text-partial"
                >
                    {{ t('report.anomalies') }}
                </p>
            </section>

            <!-- What has to be done -->
            <section v-if="report.findings.length > 0" class="mb-4 rounded-card bg-surface p-4">
                <h3 class="text-[15px] font-semibold">{{ t('report.actionPlan') }}</h3>

                <div v-for="group in [
                    { key: 'immediate', label: t('report.immediate'), rows: immediate },
                    { key: 'later', label: t('report.followUp'), rows: later },
                ]" :key="group.key">
                    <template v-if="group.rows.length > 0">
                        <p class="mt-3 text-[12px] font-semibold uppercase tracking-wide text-label-3">
                            {{ group.label }}
                        </p>
                        <div
                            v-for="finding in group.rows"
                            :key="finding.id"
                            class="border-t border-hairline py-2"
                        >
                            <p class="text-[13px] text-label-3">
                                <span class="tnum">{{ finding.question_code }}</span>
                                <template v-if="finding.pathogen"> · {{ finding.pathogen }}</template>
                                <template v-if="finding.question"> — {{ finding.question }}</template>
                            </p>
                            <p class="text-[14px]">{{ finding.gap }}</p>
                            <p v-if="finding.recommendation" class="text-[14px] text-label-2">
                                {{ finding.recommendation }}
                            </p>
                            <p class="text-[12px] text-label-3">
                                {{ finding.responsibility_level }}
                                <template v-if="finding.responsible_person">
                                    · {{ finding.responsible_person }}
                                </template>
                                <template v-if="finding.due_date"> · {{ finding.due_date }}</template>
                            </p>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Every question, answered or not -->
            <section
                v-for="section in report.sections"
                :key="section.number"
                class="mb-4 rounded-card bg-surface p-4"
            >
                <h3 class="text-[15px] font-semibold">
                    {{ section.number }}. {{ section.title }}
                </h3>

                <div
                    v-for="question in section.questions"
                    :key="question.code"
                    class="border-t border-hairline py-2"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 flex-1 text-[14px]">
                            <span class="tnum text-label-3">{{ question.code }}</span>
                            {{ question.text }}
                        </p>
                        <p class="shrink-0 text-right text-[13px] font-semibold">
                            <template v-if="question.answers.length > 0">
                                <span
                                    v-for="(answer, index) in question.answers"
                                    :key="index"
                                    class="block"
                                    :class="tone(answer.response)"
                                >
                                    {{ answer.label }}
                                    <span v-if="answer.pathogen" class="font-normal text-label-3">
                                        {{ answer.pathogen }}
                                    </span>
                                </span>
                            </template>
                            <span v-else class="font-normal text-label-3">
                                {{ t('report.unanswered') }}
                            </span>
                        </p>
                    </div>

                    <p
                        v-for="(answer, index) in question.answers.filter((a) => a.comment)"
                        :key="`c${index}`"
                        class="text-[13px] text-label-2"
                    >
                        {{ answer.comment }}
                    </p>
                </div>
            </section>

            <!-- Who signed -->
            <section v-if="report.signatures.length > 0" class="mb-4 rounded-card bg-surface p-4">
                <h3 class="text-[15px] font-semibold">{{ t('report.signatures') }}</h3>
                <div class="mt-2 flex flex-wrap gap-6">
                    <div v-for="signature in report.signatures" :key="signature.id">
                        <img
                            :src="signature.url"
                            alt=""
                            class="h-16 w-auto"
                        />
                        <p class="text-[13px]">{{ signature.signed_name }}</p>
                        <p class="text-[12px] text-label-3">{{ signature.role }}</p>
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
 */
@media print {
    .rounded-card {
        background: none !important;
        border: 1px solid #ccc;
        break-inside: avoid;
    }
}
</style>
