<script setup lang="ts">
import { PhArrowLeft, PhArrowRight } from '@phosphor-icons/vue'
import { computed, onMounted, ref } from 'vue'

import rawTemplate from '@resources/templates/spi-rdt-1.0.0.json'

import type { Site } from '@/api/sites'
import { session } from '@/auth/session'
import ContextForm from '@/components/ContextForm.vue'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import PathogenSetup from '@/components/PathogenSetup.vue'
import QuestionRow from '@/components/QuestionRow.vue'
import ReviewScreen from '@/components/ReviewScreen.vue'
import SitePicker, { type DraftSummary } from '@/components/SitePicker.vue'
import StorageNotice from '@/components/StorageNotice.vue'
import SyncBadge from '@/components/SyncBadge.vue'
import { useAssessment } from '@/composables/useAssessment'
import { getAssessment, listAssessments, loadAnswers } from '@/db/assessments'
import type { StoredPathogen, StoredResponse } from '@/db/database'
import { formatPercent, formatTime, locale, t, text } from '@/i18n'
import { expectedQuestions } from '@/scoring/engine'
import { validateContext } from '@/validation/context'
import type { Context, ResponseCode, Template } from '@/scoring/types'
import { startSync, syncAll } from '@/sync/engine'

/**
 * Running a visit: choose a site, set it up, work the checklist, review,
 * submit.
 *
 * Everything before this — signing in, replacing a password somebody else set
 * — belongs to the shell in App.vue, which does not render this view until
 * there is a session that the server will actually answer.
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


/** Part A fields whose value decides whether a section applies. */
const applicabilityFields = computed(() =>
    template.sections
        .map((section) => section.applicability_field)
        .filter((code): code is string => typeof code === 'string'),
)

const draftContext = ref<Context>({})
const draftPathogens = ref<StoredPathogen[]>([])

/**
 * Visits begun and not submitted.
 *
 * The device writes every answer as it is given, so a draft survives a
 * refresh, a crash and a flat battery. What it could not survive was having
 * nowhere to be listed: the app opened on the site picker every time, and an
 * assessor with no way back to yesterday's visit starts it again.
 */
const drafts = ref<DraftSummary[]>([])

async function loadDrafts() {
    const rows = (await listAssessments()).filter((row) => row.status !== 'submitted')

    // Counted rather than carried on the row. A stored count is a second copy
    // of the answers, and a second copy drifts.
    //
    // The total is per visit, not a fixed 59: Section 4 repeats once per
    // pathogen and Section 5 drops out entirely when the site refers no
    // specimens, so each draft is measured against its own instrument.
    drafts.value = await Promise.all(
        rows.map(async (row) => ({
            id: row.id,
            siteName: row.siteName,
            assessedOn: row.assessedOn,
            answered: (await loadAnswers(row.id)).filter((answer) => answer.response !== null)
                .length,
            total: expectedQuestions(
                template,
                row.context,
                row.pathogens.map((pathogen) => pathogen.name),
            ).length,
            updatedAt: row.updatedAt,
        })),
    )
}

onMounted(async () => {
    // The shell has already established that there is a usable session; this
    // view is not reachable without one.
    startSync()
    await loadDrafts()
})

async function onResume(id: string) {
    const existing = await getAssessment(id)

    if (existing === undefined) {
        await loadDrafts()

        return
    }

    await assessment.resume(existing)

    activePathogen.value = existing.pathogens[0]?.name ?? null
    activeSection.value = template.sections[0]?.code ?? '1'

    // Back to setup when the visit never got past it. Sending someone into a
    // checklist whose pathogens were never named shows Section 4 with nothing
    // in it and no way to say why.
    stage.value = existing.pathogens.length === 0 ? 'setup' : 'checklist'

    if (stage.value === 'setup') {
        draftContext.value = { ...existing.context }
        draftPathogens.value = [...existing.pathogens]
    }
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

/**
 * Part A answers outside the limits the template declares.
 *
 * Separate from `missingContext`, which is about fields left empty. Empty and
 * wrong are different problems with different fixes, and a count that adds them
 * together tells the assessor neither.
 */
const contextProblems = computed(() => validateContext(template, draftContext.value))

const setupReady = computed(
    () =>
        missingContext.value.length === 0 &&
        contextProblems.value.length === 0 &&
        draftPathogens.value.length > 0,
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

/**
 * Moving on, from where the assessor actually finishes.
 *
 * The section list is at the top of the screen, and a section is fifteen
 * questions long. Reaching the end of one and having to scroll back up to
 * choose the next is the whole visit, fifty-nine times.
 */
const sectionIndex = computed(() =>
    visibleSections.value.findIndex((entry) => entry.code === activeSection.value),
)

const previousSection = computed(() =>
    sectionIndex.value > 0 ? (visibleSections.value[sectionIndex.value - 1] ?? null) : null,
)

const nextSection = computed(() => visibleSections.value[sectionIndex.value + 1] ?? null)

/**
 * Section 4 is answered once per pathogen, so "next" means the next PATHOGEN
 * until they are all done. Advancing to section 5 with a pathogen unanswered
 * is how a visit reaches the review screen looking complete and is not.
 */
const nextPathogen = computed(() => {
    if (section.value.scope !== 'pathogen') {
        return null
    }

    const list = assessment.assessment.value?.pathogens ?? []
    const at = list.findIndex((entry) => entry.name === activePathogen.value)

    return at >= 0 ? (list[at + 1] ?? null) : null
})

/** The scroll container, so moving section starts at the top of the new one. */
const questionList = ref<HTMLElement | null>(null)

function toTop(): void {
    questionList.value?.scrollTo({ top: 0, behavior: 'auto' })
    window.scrollTo({ top: 0, behavior: 'auto' })
}

function goToSection(code: string): void {
    activeSection.value = code

    const target = template.sections.find((entry) => entry.code === code)

    if (target?.scope === 'pathogen') {
        activePathogen.value = assessment.assessment.value?.pathogens[0]?.name ?? null
    }

    toTop()
}

function goToPathogen(name: string): void {
    activePathogen.value = name
    toTop()
}

/**
 * How much of the instrument has been answered.
 *
 * Needed because the percentage beside it is computed over ANSWERED questions
 * only — see docs/scoring.md. That is right for a running score, and it means
 * a half-finished visit of all Yes reads 100%. Saying so is the difference
 * between a number the assessor can use and one that misleads them.
 */
const progress = computed(() => {
    const result = assessment.result.value

    // Excluded counts as answered: N/A is a response the assessor gave, and it
    // leaves the denominator rather than the questionnaire.
    const answered = result.sections.reduce(
        (total, tally) => total + tally.answered + tally.excluded,
        0,
    )

    return {
        answered,
        total: answered + result.missing.length,
        isComplete: result.isComplete,
    }
})

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

    // A certification band claimed off eight of fifty-nine questions is a
    // stronger statement than the percentage beside it, and colour is what
    // makes it read as settled. Until the visit is complete it stays neutral.
    if (!assessment.result.value.isComplete) return 'bg-track text-label-2'

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

/**
 * Leave the visit without ending it.
 *
 * Nothing is discarded and nothing needs saving first — every answer is
 * already on the device. The visit reappears under Unfinished, which is why
 * this needs no confirmation: there is nothing to lose by tapping it.
 */
async function leaveVisit() {
    await assessment.flush()
    await loadDrafts()
    stage.value = 'site'
}

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

        return
    }

    // A submitted visit stops being unfinished, so it leaves the list. Done
    // here rather than on the next mount because the assessor may go straight
    // back to choose the next site.
    await loadDrafts()
}
</script>

<template>
    <SitePicker v-if="stage === 'site'" :drafts="drafts" @chosen="onSiteChosen" @resume="onResume" />

    <!-- Part A and the pathogens, before a single question is answered. -->
    <div
        v-else-if="stage === 'setup'"
        class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground md:max-w-[960px] md:px-6"
    >
        <StorageNotice
            :storage="assessment.storage.value"
            :save-state="assessment.saveState.value"
            :save-error="assessment.saveError.value"
        />

        <header class="flex items-start justify-between gap-3 px-4 pb-3 pt-4 md:px-0 md:pt-6">
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

        <!--
            Stacked on a phone, side by side once there is room. The two halves
            are independent — naming the pathogens and answering Part A — so
            putting them level means the second is visible while the first is
            being filled in, rather than a scroll away.
        -->
        <main
            class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 md:grid md:grid-cols-2 md:items-start md:gap-6 md:px-0"
        >
            <section>
                <h2 class="eyebrow px-1 pb-2 text-label-2">
                    {{ t('setup.pathogensHeading') }}
                </h2>
                <PathogenSetup
                    v-model="draftPathogens"
                    :repeating-section="repeating.number"
                    :questions-per-pathogen="repeating.questions"
                />
            </section>

            <section>
                <h2 class="eyebrow px-1 pb-2 pt-6 text-label-2 md:pt-0">
                    {{ t('setup.contextHeading') }}
                </h2>
                <ContextForm
                    v-model="draftContext"
                    :fields="template.context_fields ?? []"
                    :applicability-fields="applicabilityFields"
                    :problems="contextProblems"
                />
            </section>
        </main>

        <footer class="border-t border-hairline bg-surface px-4 pb-4 pt-3 md:rounded-t-surface md:border-x md:px-6">
            <button
                type="button"
                class="w-full rounded-card bg-accent py-3.5 text-[17px] font-semibold text-white transition-opacity disabled:opacity-40 md:mx-auto md:block md:w-auto md:px-16"
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
            <!-- Last, because an empty field is the commoner reason to be
                 stopped here and the assessor should be told about that first.
                 The field itself carries the detail; this only says why the
                 button will not move. -->
            <p v-else-if="contextProblems.length > 0" class="pt-2 text-center text-[13px] font-medium text-no">
                {{ t('setup.invalidFields', { count: contextProblems.length }) }}
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
        :assessment-id="assessment.assessment.value?.id ?? ''"
        :context="assessment.assessment.value?.context ?? {}"
        :submitting="submitting"
        :submit-error="submitError"
        @back="stage = 'checklist'"
        @jump="jumpTo"
        @finding="(key, patch) => assessment.setFinding(key, patch)"
        @add-finding="(code, pathogen) => assessment.newFinding(code, pathogen)"
        @remove-finding="(key) => assessment.dropFinding(key)"
        @submit="onSubmit"
    />

    <!--
        One column on a phone, two from 768px up.

        The section list is a horizontal pill row when there is no width for
        anything else, and a rail down the side when there is. Both are the
        same list; only one is in the accessibility tree at a time, because two
        copies of the same navigation is two things to tab through.
    -->
    <div v-else class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground md:max-w-[1040px] md:px-6">
        <StorageNotice
            :storage="assessment.storage.value"
            :save-state="assessment.saveState.value"
            :save-error="assessment.saveError.value"
        />

        <header class="flex flex-col gap-0.5 px-4 pb-2.5 pt-3 md:px-0 md:pt-6">
            <div class="flex items-center justify-between gap-2">
                <!--
                    Out of the visit, without ending it. Everything is already
                    written to the device, so leaving costs nothing and the
                    visit is waiting under Unfinished when they come back.
                    Without this the checklist is a room with no door.
                -->
                <button
                    type="button"
                    class="-ml-1 flex min-h-11 flex-1 items-center gap-1 truncate pr-1 text-left text-[13px] text-accent"
                    @click="leaveVisit"
                >
                    <PhArrowLeft :size="14" class="shrink-0" aria-hidden="true" />
                    <span class="truncate">
                        {{ assessment.assessment.value?.siteName ?? t('checklist.loading') }}
                    </span>
                </button>
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

        <!-- Numbers only, because a phone has room for numbers only. -->
        <nav
            class="scroll-thin flex gap-1.5 overflow-x-auto px-4 pb-2 md:hidden"
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

        <div class="flex min-h-0 flex-1 flex-col md:grid md:grid-cols-[13rem_minmax(0,1fr)] md:gap-6">
            <!--
                The same navigation with the titles restored. A number alone is
                a thing to count through; a title is a thing to choose. The
                width exists here, so it is spent on saying what the sections
                are.
            -->
            <nav
                class="scroll-thin hidden md:block md:overflow-y-auto md:pb-6"
                :aria-label="t('checklist.sections')"
            >
                <button
                    v-for="item in visibleSections"
                    :key="item.code"
                    type="button"
                    :aria-current="item.code === activeSection ? 'true' : undefined"
                    :class="[
                        'flex w-full items-baseline gap-2.5 rounded-card px-3 py-2.5 text-left',
                        'text-[14px] transition-colors',
                        item.code === activeSection
                            ? 'bg-accent-soft font-semibold text-accent'
                            : 'text-label-2 hover:bg-surface hover:text-label',
                    ]"
                    @click="activeSection = item.code"
                >
                    <span class="tnum shrink-0 font-semibold">{{ item.number }}</span>
                    <span class="min-w-0 flex-1">{{ text(item.title) }}</span>
                </button>
            </nav>

            <div class="flex min-h-0 flex-1 flex-col">
                <!-- Section 4 is answered once per pathogen, so it gets its own
                     row of tabs. Without them the section silently shows one
                     pathogen's answers and the other's look unanswered. -->
                <nav
                    v-if="section.scope === 'pathogen'"
                    class="scroll-thin flex gap-1.5 overflow-x-auto px-4 pb-3 md:px-0"
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

                <main ref="questionList" class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 md:px-0">
                    <div class="overflow-hidden rounded-card bg-surface md:rounded-surface md:shadow-surface">
                        <div v-for="(question, index) in section.questions" :key="question.code">
                            <div v-if="index > 0" class="ml-[49px] border-t border-hairline"></div>
                            <QuestionRow
                                :question="question"
                                :response="
                                    assessment.responseFor(question.code, instance) as ResponseCode | null
                                "
                                :comment="assessment.commentFor(question.code, instance)"
                                @update:response="
                                    assessment.setResponse(
                                        question.code,
                                        instance,
                                        $event as StoredResponse | null,
                                    )
                                "
                                @update:comment="
                                    assessment.setComment(question.code, instance, $event)
                                "
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

                    <!--
                        Moving on, where the assessor finishes rather than where
                        the list of sections happens to live. Full-width targets
                        on a phone: this is tapped at the end of every section,
                        standing up, sometimes gloved.
                    -->
                    <nav
                        class="mt-4 flex items-stretch gap-2"
                        :aria-label="t('checklist.sections')"
                    >
                        <button
                            v-if="previousSection"
                            type="button"
                            class="flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-card bg-surface px-3 py-2.5 text-[15px] font-medium text-label-2 transition-colors hover:text-label"
                            @click="goToSection(previousSection.code)"
                        >
                            <PhArrowLeft :size="16" aria-hidden="true" />
                            <span class="truncate">{{ t('checklist.previousSection') }}</span>
                        </button>

                        <button
                            v-if="nextPathogen"
                            type="button"
                            class="flex min-h-11 flex-[2] items-center justify-center gap-1.5 rounded-card bg-label px-3 py-2.5 text-[15px] font-semibold text-ground transition-opacity hover:opacity-90"
                            @click="goToPathogen(nextPathogen.name)"
                        >
                            <span class="truncate">{{ nextPathogen.name }}</span>
                            <PhArrowRight :size="16" aria-hidden="true" />
                        </button>

                        <button
                            v-else-if="nextSection"
                            type="button"
                            class="flex min-h-11 flex-[2] items-center justify-center gap-1.5 rounded-card bg-accent px-3 py-2.5 text-[15px] font-semibold text-white transition-opacity hover:opacity-90"
                            @click="goToSection(nextSection.code)"
                        >
                            <span class="truncate">
                                {{ nextSection.number }}. {{ text(nextSection.title) }}
                            </span>
                            <PhArrowRight :size="16" aria-hidden="true" />
                        </button>

                        <!-- Last section. The only thing left is to review. -->
                        <button
                            v-else
                            type="button"
                            class="flex min-h-11 flex-[2] items-center justify-center gap-1.5 rounded-card bg-accent px-3 py-2.5 text-[15px] font-semibold text-white transition-opacity hover:opacity-90"
                            @click="stage = 'review'"
                        >
                            <span class="truncate">{{ t('checklist.review') }}</span>
                            <PhArrowRight :size="16" aria-hidden="true" />
                        </button>
                    </nav>
                </main>
            </div>
        </div>

        <!-- Full width under both columns. The running score and the level are
             about the whole visit, not the section on screen, so pinning them
             to the content column would say otherwise. -->
        <footer
            class="flex items-center justify-between gap-3 border-t border-hairline bg-surface px-4 pb-4 pt-3 md:rounded-t-surface md:border-x md:px-6"
        >
            <div class="min-w-0">
                <!--
                    No percentage until the visit is complete.

                    It is computed over ANSWERED questions only — see
                    docs/scoring.md — so eight questions of all Yes reads 100%
                    and eight with one Partial reads 93.75%. Neither number
                    describes the site. Labelling it provisional was not
                    enough: a large figure is read before the word beside it,
                    and the one thing worse than no score is a confident wrong
                    one shown to somebody being debriefed.

                    Progress takes its place, which is the question actually
                    being asked mid-visit — how much is left.
                -->
                <div class="tnum text-[22px] font-bold tracking-tight">
                    <template v-if="progress.isComplete">
                        {{
                            assessment.result.value.percentage === null
                                ? '—'
                                : formatPercent(
                                      assessment.result.value.percentage,
                                      assessment.result.value.roundDp,
                                  )
                        }}
                    </template>
                    <template v-else>{{ progress.answered }} / {{ progress.total }}</template>
                </div>
                <div
                    :class="[
                        'tnum truncate text-xs',
                        assessment.saveState.value === 'error' ? 'font-semibold text-no' : 'text-label-2',
                    ]"
                >
                    <template v-if="!progress.isComplete">{{ t('checklist.answeredLabel') }} · </template>
                    {{ savedLabel }}
                </div>
            </div>

            <div class="flex items-center gap-2">
                <!-- A certification band is a claim about the site. It waits
                     until there is a finished assessment behind it. -->
                <span
                    v-if="progress.isComplete"
                    :class="['rounded-full px-3 py-1.5 text-[13px] font-semibold', levelTone]"
                >
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
