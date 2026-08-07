import { computed, reactive, ref, shallowRef } from 'vue'

import {
    addFinding,
    createAssessment,
    discardFindingsFor,
    type FindingPatch,
    flushWrites,
    loadAnswers,
    loadFindings,
    saveAnswer,
    saveContext,
    questionGroupKey,
    removeFinding,
    saveFinding,
    savePathogens,
    setStatus,
} from '@/db/assessments'
import {
    answerKey,
    type StoredAssessment,
    type StoredFinding,
    type StoredPathogen,
    type StoredResponse,
} from '@/db/database'
import { checkStorage, type StorageReport } from '@/db/storage'
import { t } from '@/i18n'
import { questionKey, score } from '@/scoring/engine'
import type { AnswerInput, Context, Template } from '@/scoring/types'
import { syncAssessment } from '@/sync/engine'

/**
 * An assessment, backed by the local database.
 *
 * The screen reads from memory and writes through on every change. There is no
 * save button, and nothing is held back for a flush on unload — `beforeunload`
 * does not fire when a tab is killed, a browser is force-quit, or a battery
 * dies, and all three happen on a site visit.
 *
 * `saveState` exists because a silent failed write is the worst outcome
 * available: the answer is on screen, so the assessor has no reason to doubt it,
 * and they find out when they get back to the office.
 */

export type SaveState = 'idle' | 'saving' | 'saved' | 'error'

export function useAssessment(template: Template) {
    const assessment = shallowRef<StoredAssessment | null>(null)
    const responses = reactive(new Map<string, StoredResponse | null>())
    const comments = reactive(new Map<string, string>())
    /** Several per question now, keyed by `${questionCode}|${pathogen}`. */
    const findings = reactive(new Map<string, StoredFinding[]>())

    const storage = ref<StorageReport | null>(null)
    const saveState = ref<SaveState>('idle')
    const saveError = ref<string>('')
    const lastSavedAt = ref<Date | null>(null)
    const inFlight = ref(0)

    async function start(input: {
        organizationId: number
        siteId: string
        siteName: string
        facilityId: string
        pathogens: string[]
        context: Context
    }) {
        // Checked before anything is written, not after. A device that is not
        // keeping data has to be found out before fifty-nine questions, not
        // after them.
        storage.value = await checkStorage()

        const created = await createAssessment({
            organizationId: input.organizationId,
            siteId: input.siteId,
            facilityId: input.facilityId,
            siteName: input.siteName,
            templateCode: template.code,
            templateVersion: template.version,
            context: input.context,
            // Key and name are the same string here. Answers reference a
            // pathogen by name and the scoring engine scores by name, so
            // introducing a separate key would mean two identifiers for one
            // thing and a mapping to keep correct on both sides of the sync.
            pathogens: input.pathogens.map((name) => ({ key: name, name })),
        })

        assessment.value = created

        for (const row of await loadAnswers(created.id)) {
            const key = questionKey(row.questionCode, row.pathogen)
            responses.set(key, row.response)
            comments.set(key, row.comment)
        }

        return created
    }

    async function resume(existing: StoredAssessment) {
        storage.value = await checkStorage()
        assessment.value = existing

        responses.clear()
        comments.clear()
        findings.clear()

        for (const row of await loadAnswers(existing.id)) {
            const key = questionKey(row.questionCode, row.pathogen)
            responses.set(key, row.response)
            comments.set(key, row.comment)
        }

        for (const row of await loadFindings(existing.id)) {
            const key = questionGroupKey(row.questionCode, row.pathogen)
            findings.set(key, [...(findings.get(key) ?? []), row])
        }
    }

    async function write(
        questionCode: string,
        pathogen: string | null,
        patch: { response?: StoredResponse | null; comment?: string },
    ) {
        const current = assessment.value

        if (!current) {
            return
        }

        inFlight.value += 1
        saveState.value = 'saving'

        try {
            await saveAnswer(current.id, questionCode, pathogen, patch)
            saveError.value = ''
            lastSavedAt.value = new Date()
        } catch (error) {
            saveState.value = 'error'
            saveError.value =
                error instanceof Error ? error.message : t('storage.answerNotSaved')
            return
        } finally {
            inFlight.value -= 1
        }

        // Driven off saveError rather than saveState: a concurrent write may
        // have failed while this one was in flight, and reporting "saved"
        // while an error is outstanding is the exact lie this is here to
        // prevent.
        if (inFlight.value === 0 && saveError.value === '') {
            saveState.value = 'saved'
        }
    }

    function keyOf(questionCode: string, pathogen: string | null): string {
        return questionKey(questionCode, pathogen)
    }

    function responseFor(questionCode: string, pathogen: string | null): StoredResponse | null {
        return responses.get(keyOf(questionCode, pathogen)) ?? null
    }

    function commentFor(questionCode: string, pathogen: string | null): string {
        return comments.get(keyOf(questionCode, pathogen)) ?? ''
    }

    function setResponse(questionCode: string, pathogen: string | null, value: StoredResponse | null) {
        responses.set(keyOf(questionCode, pathogen), value)
        void write(questionCode, pathogen, { response: value })

        // A finding describes a shortfall. Once the answer no longer records
        // one, the finding has nothing to hang on and must stop being reported
        // against it — the site would otherwise be handed an action for a
        // question the assessment says is fine. The gap text stays in the
        // journal, so a mis-tap is recoverable.
        const current = assessment.value
        const group = questionGroupKey(questionCode, pathogen)

        // ALL of them, not one. A question may carry several, and leaving the
        // rest would hand the site an action list for a shortfall the
        // assessment no longer records.
        if (current && value !== 'P' && value !== 'N' && findings.has(group)) {
            findings.delete(group)
            void discardFindingsFor(current.id, questionCode, pathogen)
        }
    }

    function findingsFor(questionCode: string, pathogen: string | null): StoredFinding[] {
        return findings.get(questionGroupKey(questionCode, pathogen)) ?? []
    }

    /** Add an empty slot for the assessor to type into. */
    async function newFinding(questionCode: string, pathogen: string | null) {
        const current = assessment.value
        const response = responseFor(questionCode, pathogen)

        if (!current || (response !== 'P' && response !== 'N')) {
            return
        }

        const created = await addFinding(current.id, questionCode, pathogen, response)
        const group = questionGroupKey(questionCode, pathogen)

        findings.set(group, [...(findings.get(group) ?? []), created])
    }

    async function setFinding(key: string, patch: FindingPatch) {
        const saved = await saveFinding(key, patch)

        if (saved === null) {
            return
        }

        findings.set(
            saved.questionKey,
            (findings.get(saved.questionKey) ?? []).map((row) => (row.key === key ? saved : row)),
        )
    }

    async function dropFinding(key: string) {
        const group = [...findings.entries()].find(([, rows]) =>
            rows.some((row) => row.key === key),
        )

        await removeFinding(key)

        if (group !== undefined) {
            findings.set(
                group[0],
                group[1].filter((row) => row.key !== key),
            )
        }
    }

    async function updatePathogens(next: StoredPathogen[]) {
        const current = assessment.value

        if (!current) {
            return
        }

        await savePathogens(current.id, next)
        assessment.value = { ...current, pathogens: next }
    }

    /**
     * Mark the visit submitted, then push it.
     *
     * The completeness gate is here rather than in the sync: the server accepts
     * an incomplete draft on purpose, because backing work up mid-visit is the
     * point. What must not happen is a visit being *declared* finished while
     * questions are unanswered, since the percentage of a partial assessment
     * reads high — every unanswered question is absent from the denominator as
     * well as the numerator.
     */
    async function submit(): Promise<{ ok: boolean; reason?: string }> {
        const current = assessment.value

        if (!current) {
            return { ok: false, reason: t('submit.noAssessment') }
        }

        await flushWrites()

        if (!result.value.isComplete) {
            return {
                ok: false,
                reason: t('review.stillNeeded', { count: result.value.missing.length }),
            }
        }

        if (!result.value.isValid) {
            return { ok: false, reason: t('submit.invalidAnswers') }
        }

        await setStatus(current.id, 'submitted')
        assessment.value = { ...current, status: 'submitted' }

        // Best effort. The visit is submitted on this device either way, and
        // the engine keeps trying — being out of coverage at the end of a visit
        // is normal and must not block finishing one.
        try {
            await syncAssessment(current.id)
        } catch {
            // Left pending; the retry loop owns it from here.
        }

        return { ok: true }
    }

    function setComment(questionCode: string, pathogen: string | null, value: string) {
        comments.set(keyOf(questionCode, pathogen), value)
        void write(questionCode, pathogen, { comment: value })
    }

    async function updateContext(context: Context) {
        const current = assessment.value

        if (!current) {
            return
        }

        await saveContext(current.id, context)
        assessment.value = { ...current, context }
    }

    const answers = computed<AnswerInput[]>(() =>
        [...responses.entries()]
            .filter(([, value]) => value !== null)
            .map(([key, value]) => {
                const [code, pathogen] = key.split('|')
                return {
                    question_code: code ?? '',
                    pathogen: pathogen === '' ? null : (pathogen ?? null),
                    response: value as string,
                    // The engine needs this to tell an explained gap from an
                    // unexplained one. It changes no score — only whether the
                    // visit may be submitted.
                    comment: comments.get(key) ?? '',
                }
            }),
    )

    const result = computed(() =>
        score(
            template,
            answers.value,
            assessment.value?.context ?? {},
            assessment.value?.pathogens.map((pathogen) => pathogen.name) ?? [],
        ),
    )

    return {
        assessment,
        storage,
        saveState,
        saveError,
        lastSavedAt,
        answers,
        result,
        start,
        resume,
        responseFor,
        commentFor,
        setResponse,
        setComment,
        updateContext,
        findings,
        findingsFor,
        newFinding,
        setFinding,
        dropFinding,
        updatePathogens,
        submit,
        flush: flushWrites,
        answerKey,
    }
}
