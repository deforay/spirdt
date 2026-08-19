<script setup lang="ts">
import { PhArrowLeft, PhArrowRight, PhBuildings, PhCheck } from '@phosphor-icons/vue'
import { computed, onMounted, ref, watch } from 'vue'

import rawTemplate from '@resources/templates/spi-rdt-1.0.0.json'

import type { Site } from '@/api/sites'
import { session } from '@/auth/session'
import AssessorShell from '@/components/AssessorShell.vue'
import ContextForm from '@/components/ContextForm.vue'
import PathogenSetup from '@/components/PathogenSetup.vue'
import QuestionRow from '@/components/QuestionRow.vue'
import SectionActions from '@/components/SectionActions.vue'
import ReviewScreen from '@/components/ReviewScreen.vue'
import SitePicker, { type DraftSummary } from '@/components/SitePicker.vue'
import { useAssessment } from '@/composables/useAssessment'
import { getAssessment, listAssessments, loadAnswers } from '@/db/assessments'
import type { StoredPathogen, StoredResponse } from '@/db/database'
import { formatPercent, formatTime, locale, t, text } from '@/i18n'
import { expectedQuestions } from '@/scoring/engine'
import { validateContext } from '@/validation/context'
import type { Context, ResponseCode, Template } from '@/scoring/types'
import { startSync } from '@/sync/engine'
import { useRoute, useRouter } from 'vue-router'

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

/**
 * Changing a password on purpose, rather than because the server insisted.
 *
 * The same screen serves both. App.vue shows it when must_change_password is
 * set and there is no way past it; here it is opened from the account menu and
 * can be closed again, which is the difference between a gate and a setting.
 */


/** Part A fields whose value decides whether a section applies. */
const applicabilityFields = computed(() =>
    template.sections
        .map((section) => section.applicability_field)
        .filter((code): code is string => typeof code === 'string'),
)

const draftContext = ref<Context>({})
const draftPathogens = ref<StoredPathogen[]>([])

/**
 * Whether setup is being revisited rather than filled in for the first time.
 *
 * It changes two things. The button says where it goes back to instead of
 * offering to start something already started, and the checklist is left on
 * the section the assessor was working — sending them back to Section 1 after
 * correcting a phone number is its own small insult.
 */
const revisitingSetup = ref(false)

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

/**
 * Where the assessor is, kept in the URL.
 *
 * A visit is one route and four refs — the stage, the section, the pathogen —
 * and a refresh reset all of them to the site list. On a bench, a browser
 * reloading a backgrounded tab is not an unusual event, and losing your place
 * in a fifty-nine question form because of it is the kind of thing that makes
 * people stop trusting the app.
 *
 * The query string rather than a stored blob: it describes what is actually on
 * screen, so it cannot claim a visit that has since been submitted without the
 * restore below noticing. replace() rather than push() because every section
 * change would otherwise be a history entry, and Back would walk backwards
 * through a form rather than leaving it.
 */
const route = useRoute()
const router = useRouter()

/**
 * True while the URL is being applied TO the state.
 *
 * The two watchers below point in opposite directions, so without this the
 * first would trigger the second and a Back press would immediately push the
 * entry it had just left.
 */
const followingRoute = ref(false)

function queryFor(): Record<string, string> {
    const current = assessment.assessment.value

    if (current === null || stage.value === 'site') {
        return {}
    }

    return {
        visit: current.id,
        stage: stage.value,
        ...(stage.value === 'checklist' ? { section: activeSection.value } : {}),
        ...(activePathogen.value === null ? {} : { pathogen: activePathogen.value }),
    }
}

function sameAsRoute(query: Record<string, string>): boolean {
    const keys = new Set([...Object.keys(query), ...Object.keys(route.query)])

    return [...keys].every((key) => (route.query[key] ?? '') === (query[key] ?? ''))
}

/**
 * State to URL, as a history entry.
 *
 * push rather than replace, so Back means what it means everywhere else on the
 * web: the screen before this one. Moving between sections, opening Site
 * details and going to review are all deliberate steps somebody took, and a
 * step you cannot take back is not a step.
 *
 * The cost is that leaving the app takes as many presses as steps taken. That
 * is the ordinary bargain of a browser, and the app has its own way out — the
 * site name in the header — for anyone who wants to leave in one.
 */
function rememberPosition() {
    if (followingRoute.value) {
        return
    }

    const query = queryFor()

    if (!sameAsRoute(query)) {
        void router.push({ query })
    }
}

watch([stage, activeSection, activePathogen], rememberPosition)

/**
 * URL to state, which is what makes Back and Forward do anything.
 *
 * Without this the address changed and the screen did not, which is worse than
 * Back leaving the app: the app would claim to be somewhere it was not.
 */
watch(
    () => route.query,
    async (query) => {
        if (sameAsRoute(queryFor())) {
            return
        }

        followingRoute.value = true

        try {
            const restored = await restorePosition()

            // Back past the first step of a visit lands on the site list,
            // which is where that visit began.
            if (!restored && typeof query.visit !== 'string') {
                stage.value = 'site'
                await loadDrafts()
            }
        } finally {
            followingRoute.value = false
        }
    },
)

async function restorePosition(): Promise<boolean> {
    const id = typeof route.query.visit === 'string' ? route.query.visit : ''
    const wanted = typeof route.query.stage === 'string' ? route.query.stage : ''

    if (id === '' || !['setup', 'checklist', 'review'].includes(wanted)) {
        return false
    }

    const existing = await getAssessment(id)

    // Gone, or finished since. Falling through to the site list is right:
    // a URL is a claim about the past, not an instruction.
    if (existing === undefined || existing.status === 'submitted') {
        return false
    }

    // Already the open visit, which is every Back press within one assessment.
    // Re-reading it from the database each time would be wasted work and would
    // discard answers written since it was loaded.
    if (assessment.assessment.value?.id !== existing.id) {
        await assessment.resume(existing)
    }

    const section = typeof route.query.section === 'string' ? route.query.section : ''
    const pathogen = typeof route.query.pathogen === 'string' ? route.query.pathogen : ''

    activeSection.value =
        template.sections.some((entry) => entry.code === section)
            ? section
            : (template.sections[0]?.code ?? '1')

    activePathogen.value =
        existing.pathogens.find((entry) => entry.name === pathogen)?.name ??
        existing.pathogens[0]?.name ??
        null

    // Setup needs its drafts filled from what was stored, and a checklist with
    // no pathogens named has nothing to show for Section 4.
    if (wanted === 'setup' || existing.pathogens.length === 0) {
        draftContext.value = { ...existing.context }
        draftPathogens.value = [...existing.pathogens]
        stage.value = 'setup'

        return true
    }

    stage.value = wanted as Stage

    return true
}

onMounted(async () => {
    // The shell has already established that there is a usable session; this
    // view is not reachable without one.
    startSync()
    await loadDrafts()

    // Guarded, so restoring does not push an entry of its own. Without this the
    // first thing a visit did on load was duplicate the address it had just
    // been opened at — and Back then landed on an identical URL, so the
    // watcher saw no change and the screen did not move.
    followingRoute.value = true

    try {
        await restorePosition()
    } finally {
        followingRoute.value = false
    }
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
    revisitingSetup.value = false
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

    // Returning from a correction keeps the assessor where they were. Only a
    // visit being set up for the first time starts at the beginning.
    if (!revisitingSetup.value) {
        activePathogen.value = draftPathogens.value[0]?.name ?? null
        activeSection.value = template.sections[0]?.code ?? '1'
    } else if (!draftPathogens.value.some((entry) => entry.name === activePathogen.value)) {
        // Unless the pathogen they were on has just been removed.
        activePathogen.value = draftPathogens.value[0]?.name ?? null
    }

    revisitingSetup.value = false
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

/** How far through the whole visit, for the bar on the score tile. */
const visitFilled = computed(() =>
    progress.value.total === 0
        ? '0%'
        : `${Math.min(100, Math.round((progress.value.answered / progress.value.total) * 100))}%`,
)

/** Where the section on screen sits in the visit, for the eyebrow above it. */
const sectionNumber = computed(
    () => visibleSections.value.findIndex((entry) => entry.code === activeSection.value) + 1,
)

/**
 * How full a section's bar is, as a width.
 *
 * A section nobody has opened has an expected count of zero until the engine
 * has seen it, and dividing by that is how a bar ends up full before a
 * question is answered.
 */
function sectionFilled(code: string): string {
    const tally = sectionProgress.value.get(code)

    if (tally === undefined || tally.expected === 0) return '0%'

    return `${Math.min(100, Math.round((tally.answered / tally.expected) * 100))}%`
}

/** Section 4 repeats per pathogen; every other section is answered once. */
const instance = computed(() => (section.value.scope === 'pathogen' ? activePathogen.value : null))

/**
 * How much of each section is answered, for the rail.
 *
 * The rail is the map of the visit and could only say where the assessor was
 * standing. What is left is the question it gets looked at to answer, and
 * answering it there saves a trip to the review screen to find out.
 *
 * Counts and one navy bar, never the response colours. Green, amber and red
 * mean a response in this application, and a navigation list lit up in them
 * would be the loudest thing on a screen whose actual content is the
 * questions — including for a finished section, which is why the bar stays
 * navy when it fills rather than turning green. The review screen carries the
 * badges; this carries the arithmetic.
 */
const sectionProgress = computed(() => {
    const outstanding = new Map<string, number>()

    for (const key of assessment.result.value.missing) {
        const code = template.sections.find((entry) =>
            entry.questions.some((question) => question.code === key.split('|')[0]),
        )?.code

        if (code !== undefined) {
            outstanding.set(code, (outstanding.get(code) ?? 0) + 1)
        }
    }

    const progress = new Map<string, { answered: number; expected: number; done: boolean }>()

    for (const tally of assessment.result.value.sections) {
        const answered = tally.answered + tally.excluded
        const expected = answered + (outstanding.get(tally.code) ?? 0)

        progress.set(tally.code, {
            answered,
            expected,
            // A section nobody has started is not finished. Without this an
            // inapplicable section and an untouched one both read as done.
            done: expected > 0 && answered === expected,
        })
    }

    return progress
})

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

/**
 * The band, on the sequential navy ramp rather than red to green.
 *
 * Same ramp as ScoreBadge, and for the same reason: red, amber and green are
 * spoken for. They mean No, Partial and Yes on a question, and a Level 1 site
 * wearing the red of a failed answer says something the instrument does not.
 * A level is a position on a scale, so it deepens.
 */
const LEVEL_TONES: Record<number, string> = {
    0: 'bg-level-0 text-level-0-ink',
    1: 'bg-level-1 text-level-1-ink',
    2: 'bg-level-2 text-level-2-ink',
    3: 'bg-level-3 text-level-3-ink',
    4: 'bg-level-4 text-level-4-ink',
}

const levelTone = computed(() => {
    const level = assessment.result.value.level

    // A certification band claimed off eight of fifty-nine questions is a
    // stronger statement than the percentage beside it, and colour is what
    // makes it read as settled. Until the visit is complete it stays neutral.
    if (!assessment.result.value.isComplete) return 'bg-track text-label-2'

    if (level === null) return 'bg-track text-label-2'

    return LEVEL_TONES[level] ?? 'bg-track text-label-2'
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
/**
 * Back to Part A, mid-visit.
 *
 * The facility details and the pathogen list are answered before the checklist
 * because one of them decides whether Section 5 applies at all — and that made
 * them a door that only opened one way. A name typed wrongly, a pathogen
 * forgotten, a site that turns out to refer specimens after all: all of it is
 * discovered while working, and none of it was reachable.
 *
 * The drafts are refilled from what was stored rather than from whatever the
 * refs happen to hold, because on a resumed visit they hold nothing.
 */
function editSetup() {
    const current = assessment.assessment.value

    if (current === null) {
        return
    }

    draftContext.value = { ...current.context }
    draftPathogens.value = [...current.pathogens]
    revisitingSetup.value = true
    stage.value = 'setup'
}

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
  <AssessorShell
    :storage="assessment.storage.value"
    :save-state="assessment.saveState.value"
    :save-error="assessment.saveError.value"
    :back-label="
        stage === 'checklist'
            ? (assessment.assessment.value?.siteName ?? t('checklist.loading'))
            : undefined
    "
    @back="leaveVisit"
  >

    <SitePicker v-if="stage === 'site'" :drafts="drafts" @chosen="onSiteChosen" @resume="onResume" />

    <!-- Part A and the pathogens, before a single question is answered. -->
    <div
        v-else-if="stage === 'setup'"
        class="mx-auto flex min-h-screen w-full flex-col bg-ground sm:max-w-[680px] md:max-w-[1536px] md:px-6"
    >

        <header class="flex items-start justify-between gap-3 px-4 pb-3 pt-4 md:px-0 md:pt-6">
            <div>
                <!--
                    A way out at the top, because this form is long and the
                    only other one was the button past the end of it. Where it
                    goes depends on how the assessor got here: back to the
                    checklist when they came to correct something, back to the
                    site list when the visit has not started.
                -->
                <button
                    type="button"
                    class="-ml-1 flex min-h-11 items-center gap-1 pr-1 text-left text-[14px] text-accent"
                    @click="revisitingSetup ? startChecklist() : leaveVisit()"
                >
                    <PhArrowLeft :size="14" class="shrink-0" aria-hidden="true" />
                    <span class="truncate">
                        {{
                            revisitingSetup
                                ? t('setup.backToChecklist')
                                : (assessment.assessment.value?.siteName ?? '')
                        }}
                    </span>
                </button>
                <h1 class="text-[32px] font-bold tracking-tight">
                    {{ revisitingSetup ? t('checklist.editSetup') : t('setup.title') }}
                </h1>
                <p v-if="instrumentUntranslated" class="mt-1 text-[13px] leading-snug text-label-2">
                    {{ t('locale.instrumentNote') }}
                </p>
            </div>
        </header>

        <!--
            Stacked on a phone, side by side once there is room. The two halves
            are independent — naming the pathogens and answering Part A — so
            putting them level means the second is visible while the first is
            being filled in, rather than a scroll away.
        -->
        <main
            class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 md:grid md:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] md:items-start md:gap-7 md:px-0"
        >
            <!--
                The tests are four chips and a box; Part A is twenty fields. An
                even split gave the short half a column of its own and left two
                thirds of it empty down the length of the long one, so the panel
                is now sized to its content and sticks while the form scrolls
                past it — it is the thing the form's last section depends on.
            -->
            <section class="rounded-surface border border-hairline bg-surface p-5 md:sticky md:top-4">
                <h2 class="eyebrow pb-3 text-label-3">
                    {{ t('setup.pathogensHeading') }}
                </h2>
                <PathogenSetup
                    v-model="draftPathogens"
                    :repeating-section="repeating.number"
                    :questions-per-pathogen="repeating.questions"
                />
            </section>

            <section class="mt-5 rounded-surface border border-hairline bg-surface p-5 md:mt-0 md:p-6">
                <h2 class="eyebrow pb-4 text-label-3">
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
                class="w-full rounded-card bg-accent py-3.5 text-[17px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40 md:mx-auto md:block md:w-auto md:px-12"
                :disabled="!setupReady"
                @click="startChecklist"
            >
                {{ revisitingSetup ? t('setup.backToChecklist') : t('setup.start') }}
            </button>
            <p v-if="draftPathogens.length === 0" class="pt-2 text-center text-[14px] text-label-2">
                {{ t('setup.needPathogen') }}
            </p>
            <p v-else-if="missingContext.length > 0" class="pt-2 text-center text-[14px] text-label-2">
                {{ t('setup.missingFields', { count: missingContext.length }) }}
            </p>
            <!-- Last, because an empty field is the commoner reason to be
                 stopped here and the assessor should be told about that first.
                 The field itself carries the detail; this only says why the
                 button will not move. -->
            <p v-else-if="contextProblems.length > 0" class="pt-2 text-center text-[14px] font-medium text-no">
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
        @edit-setup="editSetup"
        @jump="jumpTo"
        @submit="onSubmit"
    />

    <!--
        One column on a phone, two from 768px up.

        The section list is a horizontal pill row when there is no width for
        anything else, and a rail down the side when there is. Both are the
        same list; only one is in the accessibility tree at a time, because two
        copies of the same navigation is two things to tab through.
    -->
    <!--
        Fluid until there is a reason not to be.

        The column used to stop at 430px, which is a phone held in the hand and
        nothing else. Anything between that and 640px — a small tablet, a phone
        turned sideways, a browser window pulled narrow — got a 430px strip of
        questions with an empty margin down both sides, while the bar above it
        ran the full width. The caps that matter are the ones that keep a line
        of text readable, and those start at 640px.
    -->
    <div v-else class="mx-auto flex min-h-screen w-full flex-col bg-ground sm:max-w-[680px] md:max-w-[1536px] md:px-6">

        <!--
            On a phone this is a bar, not a run of headings.

            Everything above the questions is fixed furniture — the questions
            scroll under it — so what used to be four bands floating on the
            grey page was four objects, each taking its own slice of a screen
            that has none to spare, on every section, all visit. Now it is two:
            the section, and the row for changing it. White and edged, it reads
            as one piece of chrome the way the top bar above it does, and the
            questions below are plainly the page.

            The way out went up into that top bar, where the brand was — a
            phone has no room for a logo above a working screen — and the
            eyebrow saying which of five this is went with it, because the
            numbered row below says the same thing in larger type and with the
            other four to compare against. Two rows saved, and what is left can
            have some air around it.
        -->
        <header class="flex flex-col border-b border-hairline bg-surface px-4 pb-3 pt-3 md:gap-0.5 md:border-b-0 md:bg-transparent md:px-0 md:pb-2 md:pt-6">
            <!-- Out of the visit, without ending it. Everything is already
                 written to the device, so leaving costs nothing and the visit
                 is waiting under Unfinished when they come back. Without this
                 the checklist is a room with no door. On a phone this door is
                 in the top bar; this is the one for a desk, where the bar
                 keeps the brand. -->
            <div class="hidden items-center justify-between gap-3 md:flex">
                <button
                    type="button"
                    class="-ml-1 flex min-h-11 flex-1 items-center gap-1 truncate pr-1 text-left text-[14px] text-accent"
                    @click="leaveVisit"
                >
                    <PhArrowLeft :size="14" class="shrink-0" aria-hidden="true" />
                    <span class="truncate">
                        {{ assessment.assessment.value?.siteName ?? t('checklist.loading') }}
                    </span>
                </button>
            </div>

            <div class="flex items-end justify-between gap-4">
                <div class="flex min-w-0 flex-1 flex-col gap-0.5 md:flex-none">
                    <span class="eyebrow hidden text-brass md:block">
                        {{
                            t('checklist.sectionOf', {
                                number: sectionNumber,
                                total: visibleSections.length,
                            })
                        }}
                    </span>

                    <!--
                        The rule runs the column on a phone rather than
                        stopping wherever the words happen to stop. Cut to the
                        text it looked like a mistake — worse on a title that
                        wraps, where it measured the longer of two lines — and
                        a mark that carries the institution cannot look
                        accidental. At a desk the title sits beside the
                        progress chip, so there the rule still belongs to the
                        words.

                        Nothing shares this line. "8 of 8 answered" set beside
                        the title pushed most section names onto a second line,
                        which is a whole line of screen spent on a fact the
                        switcher underneath already draws: the bar under the
                        current number is that fraction. The exact figure for
                        the visit is in the bar across the foot of the screen,
                        where it is the one somebody looks up.
                    -->
                    <h1 class="rule-brass self-stretch pb-2 text-[25px] font-extrabold leading-tight md:self-start md:pb-1.5 md:text-[32px]">
                        {{ text(section.title) }}
                    </h1>
                </div>

                <div
                    class="hidden shrink-0 items-center gap-3 rounded-full bg-surface px-4 py-2.5 shadow-pick md:flex"
                >
                    <span class="tnum text-[14px] font-semibold">
                        {{
                            t('checklist.answered', {
                                answered: answeredHere,
                                total: section.questions.length,
                            })
                        }}
                    </span>
                    <span
                        aria-hidden="true"
                        class="block h-1.5 w-24 overflow-hidden rounded-full bg-track"
                    >
                        <span
                            class="block h-full rounded-full bg-accent transition-[width] duration-200"
                            :style="{ width: sectionFilled(section.code) }"
                        ></span>
                    </span>
                </div>
            </div>

            <p v-if="instrumentUntranslated" class="text-[13px] leading-snug text-label-2">
                {{ t('locale.instrumentNote') }}
            </p>

            <!--
                The jumper, for going out of order. Moving on in order is a
                full-width button at the end of the section, where the assessor
                finishes; this row is for the other case — the section that was
                skipped because the store room was locked, being come back to.

                One groove with the current section filled in it, which is
                the shape this app already uses on a phone for a set of
                exclusive choices — it is the response switch under every
                question, in smaller clothes. Five separate cards floating on
                the page said five separate things; a switch says one of these,
                and you are on it. The current one is filled rather than merely
                lifted, because this is the row an assessor glances at from
                arm's length on a bench, and a white chip on a pale groove is
                not a glance's worth of difference.

                Numbers only, because a phone has room for numbers only. But a
                number on its own answers "which section is this" and not
                "which ones are still owed", which is the question somebody
                scanning this row is actually asking. Each carries the fill bar
                the desk rail draws under its title, so a full bar means a
                finished section and the row says where the work is left at a
                glance.

                The sections share the width evenly, because five sections of
                an even width read as the whole of the checklist — which is
                what they are — and the width goes into the bars, which is the
                part worth reading. They stop shrinking at 40px and the groove
                scrolls instead, for an instrument with more sections than this
                one.
            -->
            <nav
                class="scroll-thin mt-3 flex gap-1 overflow-x-auto rounded-[12px] bg-track p-1 md:hidden"
                :aria-label="t('checklist.sections')"
            >
                <!-- Part A sits before Section 1 in the visit, so it sits
                     before Section 1 here. An icon rather than a word: this
                     row has room for numbers only, which is why the numbers
                     are alone. It has no bar because it is not a section — it
                     is answered before the checklist starts or the checklist
                     does not start. -->
                <button
                    type="button"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[8px] text-label-3 transition-colors hover:text-label"
                    :aria-label="t('checklist.editSetup')"
                    @click="editSetup"
                >
                    <PhBuildings :size="16" aria-hidden="true" />
                </button>

                <button
                    v-for="item in visibleSections"
                    :key="item.code"
                    type="button"
                    :aria-current="item.code === activeSection ? 'true' : undefined"
                    :aria-label="`${text(item.title)} — ${sectionFilled(item.code)}`"
                    :class="[
                        'flex h-10 min-w-10 flex-1 basis-0 flex-col items-center justify-center gap-1.5 rounded-[8px] px-2',
                        'text-[14px] font-semibold transition-[background-color,color,box-shadow] duration-150',
                        item.code === activeSection
                            ? 'bg-accent text-accent-ink shadow-pick'
                            : 'text-label-2 hover:text-label',
                    ]"
                    @click="activeSection = item.code"
                >
                    <span class="tnum leading-none">{{ item.number }}</span>

                    <!-- Hidden from assistive technology: how far through the
                         section is, is already in the button's own label. -->
                    <span
                        aria-hidden="true"
                        :class="[
                            'block h-1 w-full overflow-hidden rounded-full',
                            item.code === activeSection ? 'bg-accent-ink/30' : 'bg-surface',
                        ]"
                    >
                        <span
                            :class="[
                                'block h-full rounded-full transition-[width] duration-200',
                                item.code === activeSection ? 'bg-accent-ink' : 'bg-accent',
                            ]"
                            :style="{ width: sectionFilled(item.code) }"
                        ></span>
                    </span>
                </button>
            </nav>
        </header>

        <div class="flex min-h-0 flex-1 flex-col md:grid md:grid-cols-[17rem_minmax(0,1fr)] md:gap-7">
            <!--
                The rail holds the whole visit: what is being assessed, the
                sections of it, and where it stands. The score used to be a bar
                across the foot of the page, which put the state of the visit
                as far from the list of sections as the layout allowed and
                spent a row of the working area on it.
            -->
            <div class="hidden min-h-0 md:flex md:flex-col md:pb-6">
                <div class="mb-3 rounded-surface bg-accent-soft px-3.5 py-3">
                    <span class="eyebrow text-accent">{{ t('checklist.currentVisit') }}</span>
                    <p class="mt-1 text-[15px] font-semibold leading-snug">
                        {{ assessment.assessment.value?.siteName ?? t('checklist.loading') }}
                    </p>
                </div>

                <span class="eyebrow px-3 pb-1.5 text-label-3">
                    {{ t('checklist.sections') }}
                </span>

            <nav
                class="scroll-thin min-h-0 flex-1 md:overflow-y-auto"
                :aria-label="t('checklist.sections')"
            >
                <!-- Before the sections, because that is where it comes in the
                     visit. It was a chip in the top corner, which on a wide
                     screen is the furthest point from this list — and this list
                     is where somebody looks for where a visit can be. -->
                <button
                    type="button"
                    class="mb-1 flex w-full items-center gap-2.5 rounded-card px-3 py-2.5 text-left text-[15px] text-label-2 transition-colors hover:bg-surface hover:text-label"
                    @click="editSetup"
                >
                    <PhBuildings :size="16" class="shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">{{ t('checklist.editSetup') }}</span>
                </button>

                <button
                    v-for="item in visibleSections"
                    :key="item.code"
                    type="button"
                    :aria-current="item.code === activeSection ? 'true' : undefined"
                    :class="[
                        'relative flex w-full items-baseline gap-2.5 rounded-card px-3 py-2.5 text-left',
                        'text-[15px] transition-colors',
                        item.code === activeSection
                            ? 'bg-surface font-semibold text-accent shadow-pick'
                            : 'text-label-2 hover:bg-surface/70 hover:text-label',
                    ]"
                    @click="activeSection = item.code"
                >
                    <span class="tnum shrink-0 font-bold">{{ item.number }}</span>

                    <span class="min-w-0 flex-1">
                        {{ text(item.title) }}

                        <!--
                            How far through this section is, drawn rather than
                            counted. The count is still there and still exact;
                            the bar is what makes a rail of five sections
                            answerable at a glance, which a column of fractions
                            never was. Hidden from assistive technology because
                            the fraction beside it says the same thing in words.
                        -->
                        <span
                            aria-hidden="true"
                            class="mt-1.5 block h-1 w-full overflow-hidden rounded-full bg-track"
                        >
                            <span
                                class="block h-full rounded-full bg-accent transition-[width] duration-200"
                                :style="{ width: sectionFilled(item.code) }"
                            ></span>
                        </span>
                    </span>

                    <!-- A tick when there is nothing left, the count when there
                         is. Both in the faintest label colour: this is the
                         answer to a question the assessor asked, not something
                         demanding to be read. -->
                    <PhCheck
                        v-if="sectionProgress.get(item.code)?.done"
                        :size="14"
                        class="mt-0.5 shrink-0 self-start text-accent"
                        :aria-label="t('checklist.sectionDone')"
                    />
                    <span v-else class="tnum shrink-0 self-start text-[13px] text-label-3">
                        {{ sectionProgress.get(item.code)?.answered ?? 0 }}/{{
                            sectionProgress.get(item.code)?.expected ?? 0
                        }}
                    </span>
                </button>
            </nav>

                <!--
                    Where the visit stands, at the foot of the rail. Dark on a
                    light page because it is the one figure somebody looks up
                    rather than reads past, and a tile that dark is found
                    without being hunted for.
                -->
                <div class="mt-3 rounded-surface bg-chrome px-4 py-3.5 text-chrome-ink">
                    <span class="eyebrow text-chrome-ink/55">
                        {{ progress.isComplete ? t('checklist.runningScore') : t('checklist.progress2') }}
                    </span>

                    <div class="mt-1.5 flex items-baseline gap-2.5">
                        <span class="tnum text-[30px] font-extrabold leading-none">
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
                            <template v-else>{{ progress.answered }}/{{ progress.total }}</template>
                        </span>

                        <span
                            v-if="progress.isComplete"
                            :class="['tnum rounded-full px-2 py-0.5 text-[12px] font-semibold', levelTone]"
                        >
                            {{
                                assessment.result.value.level === null
                                    ? t('score.notScorable')
                                    : t('score.level', { level: assessment.result.value.level })
                            }}
                        </span>
                    </div>

                    <span
                        aria-hidden="true"
                        class="mt-3 block h-1.5 w-full overflow-hidden rounded-full bg-white/15"
                    >
                        <span
                            class="block h-full rounded-full bg-brass-fill transition-[width] duration-200"
                            :style="{ width: visitFilled }"
                        ></span>
                    </span>

                    <p class="tnum mt-2.5 truncate text-[12.5px] text-chrome-ink/60">
                        {{ savedLabel }}
                    </p>
                </div>
            </div>

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
                            'shrink-0 rounded-full px-3 py-1.5 text-[14px] font-medium transition-colors',
                            pathogen.name === activePathogen
                                ? 'bg-accent text-accent-ink shadow-pick'
                                : 'bg-surface text-label-2 hover:text-label',
                        ]"
                        @click="activePathogen = pathogen.name"
                    >
                        {{ pathogen.name }}
                    </button>
                </nav>

                <!-- Same 16px gutter as the header above and the bar below.
                     At 12px the cards sat four pixels proud of everything else
                     on the screen, which is not a difference anybody can name
                     and is exactly the sort nobody can stop seeing. -->
                <main ref="questionList" class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 pt-3 md:px-0 md:pt-0">
                    <!--
                        A card per question rather than one grouped list.
                        Fifty-nine rows separated by hairlines is a table, and a
                        table is what an assessor reads down rather than works
                        through; the gap between cards is what makes the current
                        question the object on screen rather than a line in a
                        ledger.
                    -->
                    <div class="flex flex-col gap-3 md:gap-4">
                        <div
                            v-for="question in section.questions"
                            :key="question.code"
                            class="rounded-card border-hairline bg-surface md:rounded-surface md:border md:shadow-surface"
                        >
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
                            class="flex justify-between rounded-card border border-hairline bg-surface px-5 py-4 text-[15px] text-label-2 md:rounded-surface"
                        >
                            <span>{{ t('checklist.sectionScore') }}</span>
                            <strong class="tnum font-semibold text-label">
                                {{ sectionTally?.score ?? 0 }} / {{ sectionTally?.possible ?? 0 }}
                            </strong>
                        </div>
                    </div>

                    <!-- What is to be done about this section's gaps, asked
                         where the predecessor asked and where the debrief
                         happens: at the end of the section, with the site in
                         the room. -->
                    <SectionActions
                        :section="section"
                        :pathogen="instance"
                        :response-for="assessment.responseFor"
                        :findings-for="assessment.findingsFor"
                        @add="(code, pathogen) => assessment.newFinding(code, pathogen)"
                        @update="(key, patch) => assessment.setFinding(key, patch)"
                        @remove="(key) => assessment.dropFinding(key)"
                    />

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
                            class="flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-card border border-hairline bg-surface px-3 py-2.5 text-[16px] font-medium text-label-2 transition-colors hover:bg-accent-soft hover:text-accent"
                            @click="goToSection(previousSection.code)"
                        >
                            <PhArrowLeft :size="16" aria-hidden="true" />
                            <span class="truncate">{{ t('checklist.previousSection') }}</span>
                        </button>

                        <button
                            v-if="nextPathogen"
                            type="button"
                            class="flex min-h-11 flex-[2] items-center justify-center gap-1.5 rounded-card bg-label px-3 py-2.5 text-[16px] font-semibold text-ground transition-opacity hover:opacity-90"
                            @click="goToPathogen(nextPathogen.name)"
                        >
                            <span class="truncate">{{ nextPathogen.name }}</span>
                            <PhArrowRight :size="16" aria-hidden="true" />
                        </button>

                        <button
                            v-else-if="nextSection"
                            type="button"
                            class="flex min-h-11 flex-[2] items-center justify-center gap-1.5 rounded-card bg-accent px-3 py-2.5 text-[16px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover"
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
                            class="flex min-h-11 flex-[2] items-center justify-center gap-1.5 rounded-card bg-accent px-3 py-2.5 text-[16px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover"
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
        <!--
            The phone gets a bar it can reach, not a copy of the desktop rail.
            Pinned to the bottom because that is where a thumb is while the
            other hand holds a specimen rack, padded past the home indicator,
            and carrying only the two things worth a tap from anywhere in a
            section: how far through the visit is, and the way out of it.
        -->
        <footer
            class="sticky bottom-0 z-20 flex items-center justify-between gap-3 border-t border-hairline bg-surface px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 md:hidden"
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
                <div class="tnum text-[24px] font-bold tracking-tight">
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
                    :class="['rounded-full px-3 py-1.5 text-[14px] font-semibold', levelTone]"
                >
                    {{
                        assessment.result.value.level === null
                            ? t('score.notScorable')
                            : t('score.level', { level: assessment.result.value.level })
                    }}
                </span>
                <button
                    type="button"
                    class="min-h-12 rounded-card bg-accent px-5 text-[15px] font-semibold text-accent-ink"
                    @click="stage = 'review'"
                >
                    {{ t('checklist.review') }}
                </button>
            </div>
        </footer>
    </div>
  </AssessorShell>
</template>
