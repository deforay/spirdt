import 'fake-indexeddb/auto'

import { beforeEach, describe, expect, it } from 'vitest'

import {
    createAssessment,
    flushWrites,
    loadAnswers,
    markSynced,
    pendingAnswers,
    recoverFromJournal,
    saveAnswer,
    saveContext,
} from '../assessments'
import { answerKey, db } from '../database'
import { uuidv7 } from '../uuid'

/**
 * Losing a site visit is the failure this application exists to prevent, so
 * these test durability rather than the API surface.
 */

async function newAssessment() {
    return createAssessment({
        organizationId: 1,
        siteName: 'Kanyama Clinic',
        templateCode: 'spi-rdt',
        templateVersion: '1.0.0',
        pathogens: ['hiv', 'syphilis'],
    })
}

beforeEach(async () => {
    await db.delete()
    await db.open()
})

describe('local assessments', () => {
    it('keeps an answer that was written before the tab died', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })

        // Closing and reopening stands in for the tab being killed. Nothing is
        // flushed on unload, because unload does not fire when a browser is
        // force-quit or a battery dies.
        db.close()
        await db.open()

        const answers = await loadAnswers(assessment.id)

        expect(answers).toHaveLength(1)
        expect(answers[0]?.response).toBe('Y')
        expect(answers[0]?.dirty).toBe(true)
    })

    it('keeps the last thing typed, not the last write to finish', async () => {
        const assessment = await newAssessment()

        // A comment produces one write per keystroke. Fired together, without
        // ordering, whichever transaction happens to settle last would win.
        const typing = ['G', 'Gl', 'Glo', 'Glov', 'Glove', 'Gloves']
        await Promise.all(
            typing.map((text) => saveAnswer(assessment.id, '3.4', null, { response: 'P', comment: text })),
        )
        await flushWrites()

        const answers = await loadAnswers(assessment.id)

        expect(answers[0]?.comment).toBe('Gloves')
    })

    it('does not lose a response when only the comment is written, or the reverse', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '3.2', null, { response: 'N' })
        await saveAnswer(assessment.id, '3.2', null, { comment: 'No exposure SOP on site.' })

        const answers = await loadAnswers(assessment.id)

        expect(answers[0]?.response).toBe('N')
        expect(answers[0]?.comment).toBe('No exposure SOP on site.')
    })

    it('keeps a pathogen-scoped answer apart from the assessment-scoped one', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '4.1', 'hiv', { response: 'Y' })
        await saveAnswer(assessment.id, '4.1', 'syphilis', { response: 'N' })

        const answers = await loadAnswers(assessment.id)
        const byKey = new Map(answers.map((row) => [row.key, row]))

        expect(answers).toHaveLength(2)
        expect(byKey.get(answerKey(assessment.id, '4.1', 'hiv'))?.response).toBe('Y')
        expect(byKey.get(answerKey(assessment.id, '4.1', 'syphilis'))?.response).toBe('N')
    })

    it('rebuilds every answer from the journal', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })
        await saveAnswer(assessment.id, '3.2', null, { response: 'N', comment: 'No SOP.' })
        await saveAnswer(assessment.id, '4.10', 'hiv', { response: 'NA', comment: 'No equipment needed.' })

        // Stands in for the answers table being lost or corrupted while the
        // append-only journal survives.
        await db.answers.clear()
        expect(await loadAnswers(assessment.id)).toHaveLength(0)

        const restored = await recoverFromJournal(assessment.id)
        const answers = await loadAnswers(assessment.id)
        const byKey = new Map(answers.map((row) => [row.key, row]))

        expect(restored).toBe(3)
        expect(answers).toHaveLength(3)
        expect(byKey.get(answerKey(assessment.id, '3.2', null))?.comment).toBe('No SOP.')
        expect(byKey.get(answerKey(assessment.id, '4.10', 'hiv'))?.response).toBe('NA')
    })

    it('replays the journal to the final value of an edited answer', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '3.4', null, { response: 'Y' })
        await saveAnswer(assessment.id, '3.4', null, { response: 'P', comment: 'Coats not worn.' })

        await db.answers.clear()
        await recoverFromJournal(assessment.id)

        const answers = await loadAnswers(assessment.id)

        expect(answers[0]?.response).toBe('P')
        expect(answers[0]?.comment).toBe('Coats not worn.')
    })

    it('marks answers synced without deleting them', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })
        await saveAnswer(assessment.id, '3.2', null, { response: 'N' })

        const before = await pendingAnswers(assessment.id)
        expect(before).toHaveLength(2)

        await markSynced(
            assessment.id,
            before.map((row) => row.key),
        )

        expect(await pendingAnswers(assessment.id)).toHaveLength(0)
        expect(
            (await loadAnswers(assessment.id)).length,
            'the device keeps what it filed',
        ).toBe(2)
        expect((await db.assessments.get(assessment.id))?.syncedAt).not.toBeNull()
    })

    it('makes an answer dirty again when it is changed after syncing', async () => {
        const assessment = await newAssessment()

        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })
        await markSynced(assessment.id, [answerKey(assessment.id, '3.1', null)])
        await saveAnswer(assessment.id, '3.1', null, { response: 'P' })

        expect(await pendingAnswers(assessment.id)).toHaveLength(1)
    })

    it('stores Part A separately from the answers', async () => {
        const assessment = await newAssessment()

        await saveContext(assessment.id, { refers_specimens: 'yes', interviewee_name: 'A. Banda' })
        await flushWrites()

        const stored = await db.assessments.get(assessment.id)

        expect(stored?.context.refers_specimens).toBe('yes')
    })

    it('moves the assessment timestamp on every answer', async () => {
        const assessment = await newAssessment()
        const started = assessment.updatedAt

        await new Promise((resolve) => setTimeout(resolve, 5))
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })

        const stored = await db.assessments.get(assessment.id)

        expect(stored?.updatedAt.localeCompare(started)).toBeGreaterThan(0)
    })
})

describe('uuidv7', () => {
    it('is a valid version 7 uuid', () => {
        const id = uuidv7()

        expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    })

    it('sorts by the order it was created in, including within one millisecond', () => {
        // 5000 forces the counter past its 4096 ceiling, which is where a
        // naive implementation starts emitting ids that sort backwards.
        const ids = Array.from({ length: 5000 }, () => uuidv7())

        expect([...ids].sort()).toEqual(ids)
        expect(new Set(ids).size, 'no duplicates').toBe(ids.length)
    })
})
