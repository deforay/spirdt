<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import rawTemplate from '@resources/templates/spi-rdt-1.0.0.json'

import type { Site } from '@/api/sites'
import { session } from '@/auth/session'
import ContextForm from '@/components/ContextForm.vue'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import PathogenSetup from '@/components/PathogenSetup.vue'
import QuestionRow from '@/components/QuestionRow.vue'
import ReviewScreen from '@/components/ReviewScreen.vue'
import SignIn from '@/components/SignIn.vue'
import SitePicker from '@/components/SitePicker.vue'
import StorageNotice from '@/components/StorageNotice.vue'
import SyncBadge from '@/components/SyncBadge.vue'
import { useAssessment } from '@/composables/useAssessment'
import type { StoredPathogen, StoredResponse } from '@/db/database'
import { formatPercent, formatTime, locale, t, text } from '@/i18n'
import type { Context, ResponseCode, Template } from '@/scoring/types'
import { startSync, syncAll } from '@/sync/engine'

/**
 * The shell: sign in, choose a site, set the visit up, work the checklist,
 * review, submit.
 *
 * The stages are ordered the way a visit is: Part A is asked first because one
 * of its answers decides whether Section 5 applies at all, and the pathogens
 * are named before the checklist because Section 4 repeats once for each.
 * Getting either wrong after answering changes what counts as complete.
 */

// Cast rather than let TypeScript infer a literal type for a 96 KB document.
const template = rawTemplate as unknown as Template

type Stage = 'site' | 'setup' | 'checklist' | 'review'

const assessment = useAssessment(template)
const stage = ref<Stage>('site')
const activeSection = ref(template.sections[0]?.code ?? '1')
const activePathogen = ref<string | null>(null)
const submitting = ref(false)
const submitError = ref('')

const signedIn = computed(() => session.value !== null)

/** Part A fields whose value decides whether a section applies. */
const applicabilityFields = computed(() =>
    template.sections
        .map((section) => section.applicability_field)
        .filter((code): code is string => typeof code === 'string'),
)

const draftContext = ref<Context>({})
const draftPathogens = ref<StoredPathogen[]>([])

onMounted(() => {
    if (signedIn.value) {
        startSync()
    }
})

function onSignedIn() {
    startSync()
}

async function onSiteChosen(site: Site) {
    await assessment.start({
        organizationId: session.value?.user.organizationId ?? 0,
        siteId: site.id,
        siteName: site.name,
        facilityId: site.facility_id,
        pathogens: [],
        context: {},
    })

    draftContext.value = { assessment_date: new Date().toISOString().slice(0, 10) }
    draftPathogens.value = []
    stage.value = 'setup'
}

/**
 * Required Part A fields, unanswered.
 *
 * Checked before the checklist rather than at submission. A missing
 * `refers_specimens` is not a blank on a form — it is nine questions that never
 * appeared, and finding that out at the end means going back through the site.
 */
const missingContext = computed(() =>
    (template.context_fields ?? []).filter((field) => {
        if (field.required !== true) {
            return false
        }

        const value = draftContext.value[field.code]

        return value === undefined || value === null || value === ''
    }),
)

const setupReady = computed(
    () => missingContext.value.length === 0 && draftPathogens.value.length > 0,
)

async function startChecklist() {
    if (!setupReady.value) {
        return
    }

    await assessment.updateContext(draftContext.value)
    await assessment.updatePathogens(draftPathogens.value)

    activePathogen.value = draftPathogens.value[0]?.name ?? null
    activeSection.value = template.sections[0]?.code ?? '1'
    stage.value = 'checklist'
}

const section = computed(
    () => template.sections.find((s) => s.code === activeSection.value) ?? template.sections[0]!,
)

/** Sections whose applicability field rules them out are not shown at all. */
const visibleSections = computed(() =>
    template.sections.filter((entry) => {
        const tally = assessment.result.value.sections.find((t) => t.code === entry.code)

        return tally?.applicable !== false
    }),
)

/** Section 4 repeats per pathogen; every other section is answered once. */
const instance = computed(() => (section.value.scope === 'pathogen' ? activePathogen.value : null))

const sectionTally = computed(() =>
    assessment.result.value.sections.find((s) => s.code === section.value.code),
)

const answeredHere = computed(
    () =>
        section.value.questions.filter((q) => assessment.responseFor(q.code, instance.value) !== null)
            .length,
)

/** Flat map of every response, for the review screen. */
const answersByKey = computed(() => {
    const map = new Map<string, string | null>()

    for (const answer of assessment.answers.value) {
        map.set(`${answer.question_code}|${answer.pathogen ?? ''}`, answer.response)
    }

    return map
})

const levelTone = computed(() => {
    const level = assessment.result.value.level
    if (level === null) return 'bg-track text-label-2'
    if (level >= 4) return 'bg-yes-soft text-yes'
    if (level === 3) return 'bg-accent-soft text-accent'
    if (level === 2) return 'bg-partial-soft text-partial'
    return 'bg-no-soft text-no'
})

const savedLabel = computed(() => {
    if (assessment.saveState.value === 'error') return t('save.error')
    if (assessment.saveState.value === 'saving') return t('save.saving')
    if (assessment.lastSavedAt.value === null) return t('save.nothing')

    return t('save.saved', { time: formatTime(assessment.lastSavedAt.value) })
})

/**
 * The app is being read in a language the instrument was not published in.
 *
 * Said once on each screen where questions are about to appear, because an
 * assessor who switches to French and still sees English questions will
 * otherwise go looking for a setting that does not exist.
 */
const instrumentUntranslated = computed(() => !template.locales.includes(locale.value))

/** The section that repeats per pathogen, and its size. Used to size the setup screen. */
const repeating = computed(() => {
    const found = template.sections.find((entry) => entry.scope === 'pathogen')

    return { number: found?.number ?? 0, questions: found?.questions.length ?? 0 }
})

function jumpTo(sectionCode: string) {
    activeSection.value = sectionCode
    stage.value = 'checklist'
}

async function onSubmit() {
    submitting.value = true
    submitError.value = ''

    const outcome = await assessment.submit()

    submitting.value = false

    if (!outcome.ok) {
        submitError.value = outcome.reason ?? t('submit.failed')
    }
}
</script>

<template>
    <SignIn v-if="!signedIn" @signed-in="onSignedIn" />

    <SitePicker v-else-if="stage === 'site'" @chosen="onSiteChosen" />

    <!-- Part A and the pathogens, before a single question is answered. -->
    <div
        v-else-if="stage === 'setup'"
        class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground"
    >
        <StorageNotice
            :storage="assessment.storage.value"
            :save-state="assessment.saveState.value"
            :save-error="assessment.saveError.value"
        />

        <header class="flex items-start justify-between gap-3 px-4 pb-3 pt-4">
            <div>
                <span class="text-[13px] text-accent">
                    {{ assessment.assessment.value?.siteName }}
                </span>
                <h1 class="text-[30px] font-bold tracking-tight">{{ t('setup.title') }}</h1>
                <p v-if="instrumentUntranslated" class="mt-1 text-[12px] leading-snug text-label-2">
                    {{ t('locale.instrumentNote') }}
                </p>
            </div>
            <div class="mt-1"><LocaleSwitcher /></div>
        </header>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
            <h2 class="px-1 pb-2 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                {{ t('setup.pathogensHeading') }}
            </h2>
            <PathogenSetup
                v-model="draftPathogens"
                :repeating-section="repeating.number"
                :questions-per-pathogen="repeating.questions"
            />

            <h2 class="px-1 pb-2 pt-6 text-[13px] font-semibold uppercase tracking-wide text-label-2">
                {{ t('setup.contextHeading') }}
            </h2>
            <ContextForm
                v-model="draftContext"
                :fields="template.context_fields ?? []"
                :applicability-fields="applicabilityFields"
            />
        </main>

        <footer class="border-t border-hairline bg-surface px-4 pb-4 pt-3">
            <button
                type="button"
                class="w-full rounded-card bg-accent py-3.5 text-[17px] font-semibold text-white transition-opacity disabled:opacity-40"
                :disabled="!setupReady"
                @click="startChecklist"
            >
                {{ t('setup.start') }}
            </button>
            <p v-if="draftPathogens.length === 0" class="pt-2 text-center text-[13px] text-label-2">
                {{ t('setup.needPathogen') }}
            </p>
            <p v-else-if="missingContext.length > 0" class="pt-2 text-center text-[13px] text-label-2">
                {{ t('setup.missingFields', { count: missingContext.length }) }}
            </p>
        </footer>
    </div>

    <ReviewScreen
        v-else-if="stage === 'review'"
        :template="template"
        :result="assessment.result.value"
        :findings="assessment.findings"
        :answers-by-key="answersByKey"
        :site-name="assessment.assessment.value?.siteName ?? ''"
        :submitting="submitting"
        :submit-error="submitError"
        @back="stage = 'checklist'"
        @jump="jumpTo"
        @finding="(code, pathogen, patch) => assessment.setFinding(code, pathogen, patch)"
        @submit="onSubmit"
    />

    <div v-else class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground">
        <StorageNotice
            :storage="assessment.storage.value"
            :save-state="assessment.saveState.value"
            :save-error="assessment.saveError.value"
        />

        <header class="flex flex-col gap-0.5 px-4 pb-2.5 pt-3">
            <div class="flex items-center justify-between gap-2">
                <span class="flex-1 truncate text-[13px] text-accent">
                    {{ assessment.assessment.value?.siteName ?? t('checklist.loading') }}
                </span>
                <LocaleSwitcher />
                <SyncBadge @retry="syncAll()" />
            </div>
            <h1 class="text-[30px] font-bold tracking-tight">{{ text(section.title) }}</h1>
            <span class="tnum text-[13px] text-label-2">
                {{
                    t('checklist.answered', {
                        answered: answeredHere,
                        total: section.questions.length,
                    })
                }}
            </span>
            <p v-if="instrumentUntranslated" class="text-[12px] leading-snug text-label-2">
                {{ t('locale.instrumentNote') }}
            </p>
        </header>

        <nav
            class="scroll-thin flex gap-1.5 overflow-x-auto px-4 pb-2"
            :aria-label="t('checklist.sections')"
        >
            <button
                v-for="item in visibleSections"
                :key="item.code"
                type="button"
                :aria-current="item.code === activeSection ? 'true' : undefined"
                :class="[
                    'shrink-0 rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors',
                    item.code === activeSection
                        ? 'bg-accent text-white'
                        : 'bg-surface text-label-2 hover:text-label',
                ]"
                @click="activeSection = item.code"
            >
                {{ item.number }}
            </button>
        </nav>

        <!-- Section 4 is answered once per pathogen, so it gets its own row of
             tabs. Without them the section silently shows one pathogen's
             answers and the other's look unanswered. -->
        <nav
            v-if="section.scope === 'pathogen'"
            class="scroll-thin flex gap-1.5 overflow-x-auto px-4 pb-3"
            :aria-label="t('checklist.pathogens')"
        >
            <button
                v-for="pathogen in assessment.assessment.value?.pathogens ?? []"
                :key="pathogen.key"
                type="button"
                :aria-current="pathogen.name === activePathogen ? 'true' : undefined"
                :class="[
                    'shrink-0 rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors',
                    pathogen.name === activePathogen
                        ? 'bg-label text-ground'
                        : 'bg-surface text-label-2',
                ]"
                @click="activePathogen = pathogen.name"
            >
                {{ pathogen.name }}
            </button>
        </nav>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
            <div class="overflow-hidden rounded-card bg-surface">
                <div v-for="(question, index) in section.questions" :key="question.code">
                    <div v-if="index > 0" class="ml-[49px] border-t border-hairline"></div>
                    <QuestionRow
                        :question="question"
                        :response="assessment.responseFor(question.code, instance) as ResponseCode | null"
                        :comment="assessment.commentFor(question.code, instance)"
                        @update:response="
                            assessment.setResponse(question.code, instance, $event as StoredResponse | null)
                        "
                        @update:comment="assessment.setComment(question.code, instance, $event)"
                    />
                </div>

                <div
                    class="flex justify-between border-t border-hairline px-3.5 py-3 text-[13px] text-label-2"
                >
                    <span>{{ t('checklist.sectionScore') }}</span>
                    <strong class="tnum font-semibold text-label">
                        {{ sectionTally?.score ?? 0 }} / {{ sectionTally?.possible ?? 0 }}
                    </strong>
                </div>
            </div>
        </main>

        <footer
            class="flex items-center justify-between gap-3 border-t border-hairline bg-surface px-4 pb-4 pt-3"
        >
            <div>
                <div class="tnum text-[22px] font-bold tracking-tight">
                    {{
                        assessment.result.value.percentage === null
                            ? '—'
                            : formatPercent(
                                  assessment.result.value.percentage,
                                  assessment.result.value.roundDp,
                              )
                    }}
                </div>
                <div
                    :class="[
                        'tnum text-xs',
                        assessment.saveState.value === 'error' ? 'font-semibold text-no' : 'text-label-2',
                    ]"
                >
                    {{ savedLabel }}
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span :class="['rounded-full px-3 py-1.5 text-[13px] font-semibold', levelTone]">
                    {{
                        assessment.result.value.level === null
                            ? t('score.notScorable')
                            : t('score.level', { level: assessment.result.value.level })
                    }}
                </span>
                <button
                    type="button"
                    class="rounded-full bg-accent px-3.5 py-1.5 text-[13px] font-semibold text-white"
                    @click="stage = 'review'"
                >
                    {{ t('checklist.review') }}
                </button>
            </div>
        </footer>
    </div>
</template>
