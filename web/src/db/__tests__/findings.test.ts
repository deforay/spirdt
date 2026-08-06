import 'fake-indexeddb/auto'

import { beforeEach, describe, expect, it } from 'vitest'

import {
    addFinding,
    createAssessment,
    discardFindingsFor,
    loadFindings,
    questionGroupKey,
    removeFinding,
    saveFinding,
} from '../assessments'
import { db } from '../database'

/**
 * Findings on the device.
 *
 * The rule worth pinning is that a question may carry SEVERAL. It used to
 * carry one, structurally — the primary key was the answer's natural key — and
 * everything that used to be impossible by construction is now only true
 * because the code says so.
 */

async function newAssessment(): Promise<string> {
    const created = await createAssessment({
        organizationId: 1,
        siteId: '019fd700-0000-7000-8000-00000000000a',
        facilityId: '019fd700-0000-7000-8000-00000000000b',
        siteName: 'Kitwe TB clinic',
        templateCode: 'spi-rdt',
        templateVersion: '1.0.0',
    })

    return created.id
}

beforeEach(async () => {
    await db.delete()
    await db.open()
})

describe('several findings on one question', () => {
    it('keeps both rather than replacing the first', async () => {
        const assessmentId = await newAssessment()

        const first = await addFinding(assessmentId, '3.2', null, 'N')
        const second = await addFinding(assessmentId, '3.2', null, 'N')

        await saveFinding(first.key, { gap: 'No exposure SOP on site.' })
        await saveFinding(second.key, { gap: 'Staff untrained on exposure response.' })

        const stored = await loadFindings(assessmentId)

        expect(stored).toHaveLength(2)
        expect(stored.map((row) => row.gap).sort()).toEqual([
            'No exposure SOP on site.',
            'Staff untrained on exposure response.',
        ])
    })

    it('gives each its own id and groups them under one question', async () => {
        const assessmentId = await newAssessment()

        const first = await addFinding(assessmentId, '3.2', null, 'N')
        const second = await addFinding(assessmentId, '3.2', null, 'N')

        expect(first.key).not.toBe(second.key)
        expect(first.questionKey).toBe(questionGroupKey('3.2', null))
        expect(second.questionKey).toBe(first.questionKey)
    })

    it('keeps findings on the same question but different pathogens apart', async () => {
        const assessmentId = await newAssessment()

        await addFinding(assessmentId, '4.2', 'HIV', 'N')
        await addFinding(assessmentId, '4.2', 'Malaria', 'N')

        const stored = await loadFindings(assessmentId)

        expect(new Set(stored.map((row) => row.questionKey)).size).toBe(2)
    })
})

describe('discarding', () => {
    /**
     * ALL of them, not one. Leaving the rest would hand the site an action
     * list for a shortfall the assessment no longer records.
     */
    it('drops every finding on a question when the answer stops being a gap', async () => {
        const assessmentId = await newAssessment()

        await addFinding(assessmentId, '3.2', null, 'N')
        await addFinding(assessmentId, '3.2', null, 'N')
        const elsewhere = await addFinding(assessmentId, '3.1', null, 'P')

        await discardFindingsFor(assessmentId, '3.2', null)

        const stored = await loadFindings(assessmentId)

        expect(stored).toHaveLength(1)
        expect(stored[0]?.key).toBe(elsewhere.key)
    })

    it('removes one without touching its siblings', async () => {
        const assessmentId = await newAssessment()

        const first = await addFinding(assessmentId, '3.2', null, 'N')
        const second = await addFinding(assessmentId, '3.2', null, 'N')

        await removeFinding(first.key)

        const stored = await loadFindings(assessmentId)

        expect(stored).toHaveLength(1)
        expect(stored[0]?.key).toBe(second.key)
    })

    /** The gap text survives in the journal, so a mis-tap is recoverable. */
    it('leaves the discarded text in the journal', async () => {
        const assessmentId = await newAssessment()

        const finding = await addFinding(assessmentId, '3.2', null, 'N')
        await saveFinding(finding.key, { gap: 'No exposure SOP on site.' })
        await removeFinding(finding.key)

        const journalled = await db.journal.where('assessmentId').equals(assessmentId).toArray()
        const serialised = JSON.stringify(journalled)

        expect(serialised).toContain('No exposure SOP on site.')
    })
})

describe('urgency', () => {
    it('starts unstated, because nobody has said', async () => {
        const assessmentId = await newAssessment()
        const finding = await addFinding(assessmentId, '3.2', null, 'N')

        expect(finding.urgency).toBeNull()
    })

    it('can be set and cleared again', async () => {
        const assessmentId = await newAssessment()
        const finding = await addFinding(assessmentId, '3.2', null, 'N')

        const set = await saveFinding(finding.key, { urgency: 'immediate' })

        expect(set?.urgency).toBe('immediate')

        const cleared = await saveFinding(finding.key, { urgency: null })

        expect(cleared?.urgency).toBeNull()
    })

    /**
     * Every write bumps the revision, which is what the sync compares against
     * what it sent. Without it an edit made during an upload is marked clean
     * on the strength of the previous one and never sent again.
     */
    it('advances the revision on every write', async () => {
        const assessmentId = await newAssessment()
        const finding = await addFinding(assessmentId, '3.2', null, 'N')

        const once = await saveFinding(finding.key, { gap: 'One.' })
        const twice = await saveFinding(finding.key, { gap: 'Two.' })

        expect(once?.revision).toBe(finding.revision + 1)
        expect(twice?.revision).toBe(finding.revision + 2)
        expect(twice?.dirty).toBe(true)
    })
})
