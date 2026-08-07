import 'fake-indexeddb/auto'

import Dexie from 'dexie'
import { beforeEach, describe, expect, it } from 'vitest'

import { SpirdtDatabase } from '../database'

/**
 * The version 5 upgrade, which changes what identifies a finding.
 *
 * Before it, the primary key was the answer's natural key, so one finding per
 * answer was structural. After it, the key is the finding's own UUID and a
 * question may carry several.
 *
 * What is worth a test of its own is the consequence for a finding the server
 * has ALREADY stored. The server minted its own id for those and never told
 * this device, so once the row is re-keyed neither side can name it the same
 * way. `legacyKey` is the last remaining thing both sides recognise, and this
 * upgrade is the only moment it still exists.
 *
 * Each test opens its own database. Sharing one would mean the upgrade had
 * already run by the second test, which is exactly the code under test.
 */

const OLD_KEY = '019fd800-0000-7000-8000-00000000000a|3.2|'

let name: string
let counter = 0

beforeEach(() => {
    counter += 1
    name = `spirdt-rekey-${counter}`
})

/** The findings table as version 4 had it, holding one pre-upgrade row. */
async function seedVersion4(row: Record<string, unknown> = {}): Promise<void> {
    const old = new Dexie(name)

    old.version(4).stores({
        assessments: 'id, status, syncState, syncedAt, updatedAt',
        answers: 'key, assessmentId, dirty, [assessmentId+questionCode]',
        findings: 'key, assessmentId, dirty',
        attachments: 'key, assessmentId',
        journal: '++id, assessmentId, at',
    })

    await old.open()
    await old.table('findings').add({
        key: OLD_KEY,
        assessmentId: '019fd800-0000-7000-8000-00000000000a',
        questionCode: '3.2',
        pathogen: null,
        response: 'N',
        gap: 'No exposure SOP on site.',
        recommendation: '',
        responsibilityLevel: 'site',
        responsiblePerson: '',
        dueDate: null,
        updatedAt: '2026-08-01T09:00:00.000Z',
        revision: 3,
        dirty: false,
        ...row,
    })
    old.close()
}

describe('upgrading a finding to its own id', () => {
    it('remembers the key the server knows it by', async () => {
        await seedVersion4()

        const db = new SpirdtDatabase(name)
        await db.open()

        const findings = await db.findings.toArray()
        expect(findings).toHaveLength(1)

        const finding = findings[0]
        expect(finding?.key).not.toBe(OLD_KEY)
        expect(finding?.legacyKey).toBe(OLD_KEY)

        db.close()
    })

    it('sends it again, because the server has never seen the new id', async () => {
        await seedVersion4({ dirty: false, revision: 3 })

        const db = new SpirdtDatabase(name)
        await db.open()

        const finding = (await db.findings.toArray())[0]

        // Acknowledged under the old key, and unacknowledged under this one.
        expect(finding?.dirty).toBe(true)
        expect(finding?.revision).toBe(4)

        db.close()
    })

    it('keeps the gap and who it belongs to', async () => {
        await seedVersion4()

        const db = new SpirdtDatabase(name)
        await db.open()

        const finding = (await db.findings.toArray())[0]

        expect(finding?.gap).toBe('No exposure SOP on site.')
        expect(finding?.questionKey).toBe('3.2|')
        expect(finding?.urgency).toBeNull()

        db.close()
    })
})
