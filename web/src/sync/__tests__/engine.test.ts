import 'fake-indexeddb/auto'

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { saveSession } from '../../auth/session'
import { createAssessment, loadAnswers, saveAnswer } from '../../db/assessments'
import { db } from '../../db/database'
import { syncAssessment } from '../engine'
import { acknowledged, buildPayload, NotSendable } from '../payload'

/**
 * The sync, from the device's side.
 *
 * What is tested here is what happens when it goes wrong, because that is where
 * an assessment gets lost. A successful sync is one line; a refused one, a
 * dropped connection and a mid-flight edit are the cases that decide whether a
 * visit survives.
 */

const SITE = '019fd200-0000-7000-8000-0000000000bb'
const FACILITY = '019fd200-0000-7000-8000-0000000000aa'

function respondWith(status: number, body: unknown): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () =>
            Promise.resolve(
                new Response(JSON.stringify(body), {
                    status,
                    headers: { 'Content-Type': 'application/json' },
                }),
            ),
        ),
    )
}

async function newAssessment(overrides: Record<string, unknown> = {}) {
    return createAssessment({
        organizationId: 1,
        siteName: 'Kanyama Clinic',
        siteId: SITE,
        facilityId: FACILITY,
        templateCode: 'spi-rdt',
        templateVersion: '1.0.0',
        pathogens: [{ key: 'hiv', name: 'HIV' }],
        ...overrides,
    })
}

/** The suite runs in node, where localStorage is not a thing. */
const memory = new Map<string, string>()

beforeEach(async () => {
    await db.delete()
    await db.open()

    memory.clear()
    vi.stubGlobal('localStorage', {
        getItem: (key: string) => memory.get(key) ?? null,
        setItem: (key: string, value: string) => void memory.set(key, value),
        removeItem: (key: string) => void memory.delete(key),
        clear: () => memory.clear(),
    })

    saveSession({
        accessToken: 'test-access-token',
        refreshToken: 'test-refresh-token',
        expiresAt: Date.now() + 3_600_000,
        user: {
            id: 1,
            email: 'jane@example.org',
            fullName: 'Jane Doe',
            role: 'assessor',
            organizationId: 1,
            organization: 'Org A',
            mustChangePassword: false,
        },
    })
})

afterEach(() => {
    vi.unstubAllGlobals()
})

describe('sending an assessment', () => {
    it('marks acknowledged answers clean and keeps them on the device', async () => {
        const assessment = await newAssessment()
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })
        await saveAnswer(assessment.id, '4.1', 'HIV', { response: 'P' })

        respondWith(200, {
            assessment_id: assessment.id,
            accepted: ['3.1|', '4.1|HIV'],
            score: {},
        })

        expect(await syncAssessment(assessment.id)).toBe('synced')

        const answers = await loadAnswers(assessment.id)

        expect(answers).toHaveLength(2)
        expect(answers.every((row) => !row.dirty)).toBe(true)
        expect((await db.assessments.get(assessment.id))?.syncState).toBe('synced')
    })

    it('leaves an answer the server did not confirm dirty', async () => {
        // The server drops an answer naming a pathogen the payload never
        // declared. The request succeeded, so nothing looks wrong — but that
        // answer was not stored, and marking it clean would retire it unsent.
        const assessment = await newAssessment()
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })
        await saveAnswer(assessment.id, '4.1', 'Ebola', { response: 'Y' })

        respondWith(200, { assessment_id: assessment.id, accepted: ['3.1|'], score: {} })

        await syncAssessment(assessment.id)

        const stillDirty = (await loadAnswers(assessment.id)).filter((row) => row.dirty)

        expect(stillDirty).toHaveLength(1)
        expect(stillDirty[0]?.pathogen).toBe('Ebola')
        expect((await db.assessments.get(assessment.id))?.syncState).toBe('pending')
    })

    it('stops retrying a payload the server refuses, and says why', async () => {
        const assessment = await newAssessment()
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })

        respondWith(422, { error: { message: 'No published template spi-rdt 9.9.9.' } })

        expect(await syncAssessment(assessment.id)).toBe('blocked')

        const stored = await db.assessments.get(assessment.id)

        expect(stored?.syncState).toBe('blocked')
        expect(stored?.syncError).toContain('9.9.9')
        expect((await loadAnswers(assessment.id))[0]?.dirty, 'the work is kept').toBe(true)
    })

    it('keeps everything and rethrows when the connection fails', async () => {
        const assessment = await newAssessment()
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))),
        )

        await expect(syncAssessment(assessment.id)).rejects.toThrow(/connection/i)

        const stored = await db.assessments.get(assessment.id)

        expect(stored?.syncState, 'not blocked — this is worth retrying').toBe('pending')
        expect((await loadAnswers(assessment.id))[0]?.dirty).toBe(true)
    })

    it('waits, rather than refusing, when the site has not been chosen', async () => {
        const assessment = await newAssessment({ siteId: null, facilityId: null })
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })

        respondWith(200, { assessment_id: assessment.id, accepted: [], score: {} })

        expect(await syncAssessment(assessment.id)).toBe('waiting')

        const stored = await db.assessments.get(assessment.id)

        expect(stored?.syncState).toBe('pending')
        expect(stored?.syncError).toMatch(/testing site/i)
    })

    it('sends a visit still being worked on as a draft', async () => {
        const assessment = await newAssessment()
        await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })

        const captured: string[] = []
        vi.stubGlobal(
            'fetch',
            vi.fn(async (_url: string, init: RequestInit) => {
                captured.push(String(init.body))

                return Promise.resolve(
                    new Response(
                        JSON.stringify({ assessment_id: assessment.id, accepted: ['3.1|'], score: {} }),
                        { status: 200 },
                    ),
                )
            }),
        )

        await syncAssessment(assessment.id)

        expect(JSON.parse(captured[0] ?? '{}').status).toBe('draft')
    })
})

describe('building the payload', () => {
    it('sends only what has not been acknowledged', async () => {
        const assessment = await newAssessment()
        const first = await saveAnswer(assessment.id, '3.1', null, { response: 'Y' })
        await saveAnswer(assessment.id, '3.2', null, { response: 'N' })

        await db.answers.update(first.key, { dirty: false })

        const built = buildPayload(assessment, await loadAnswers(assessment.id), 'device-1')

        expect(built.payload.answers).toHaveLength(1)
        expect(built.payload.answers[0]?.question_code).toBe('3.2')
    })

    it('leaves out an answer that has been cleared', async () => {
        const assessment = await newAssessment()
        await saveAnswer(assessment.id, '3.1', null, { response: null, comment: 'Not checked.' })

        const built = buildPayload(assessment, await loadAnswers(assessment.id), 'device-1')

        expect(built.payload.answers).toHaveLength(0)
    })

    it('refuses to build a payload with no site', async () => {
        const assessment = await newAssessment({ siteId: null, facilityId: null })

        expect(() => buildPayload(assessment, [], 'device-1')).toThrow(NotSendable)
    })

    it('matches server keys back to local ones', () => {
        const sent = [
            { key: 'a|3.1|', revision: 1 },
            { key: 'a|4.1|HIV', revision: 1 },
        ]

        expect(acknowledged('a', sent, ['3.1|'])).toEqual([{ key: 'a|3.1|', revision: 1 }])
    })
})
