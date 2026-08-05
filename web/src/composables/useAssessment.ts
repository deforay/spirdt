import { computed, reactive, ref, shallowRef } from 'vue'

import {
    createAssessment,
    flushWrites,
    loadAnswers,
    saveAnswer,
    saveContext,
} from '@/db/assessments'
import { answerKey, type StoredAssessment, type StoredResponse } from '@/db/database'
import { checkStorage, type StorageReport } from '@/db/storage'
import { questionKey, score } from '@/scoring/engine'
import type { AnswerInput, Context, Template } from '@/scoring/types'

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

    const storage = ref<StorageReport | null>(null)
    const saveState = ref<SaveState>('idle')
    const saveError = ref<string>('')
    const lastSavedAt = ref<Date | null>(null)
    const inFlight = ref(0)

    async function start(input: { siteName: string; pathogens: string[]; context: Context }) {
        // Checked before anything is written, not after. A device that is not
        // keeping data has to be found out before fifty-nine questions, not
        // after them.
        storage.value = await checkStorage()

        const created = await createAssessment({
            organizationId: 1,
            siteName: input.siteName,
            templateCode: template.code,
            templateVersion: template.version,
            context: input.context,
            pathogens: input.pathogens,
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

        for (const row of await loadAnswers(existing.id)) {
            const key = questionKey(row.questionCode, row.pathogen)
            responses.set(key, row.response)
            comments.set(key, row.comment)
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
                error instanceof Error ? error.message : 'The answer was not saved to this device.'
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
                }
            }),
    )

    const result = computed(() =>
        score(
            template,
            answers.value,
            assessment.value?.context ?? {},
            assessment.value?.pathogens ?? [],
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
        flush: flushWrites,
        answerKey,
    }
}
