import { describe, expect, it } from 'vitest'
import { reactive, ref } from 'vue'

import { plain } from '../plain'

/**
 * Reactivity must not reach IndexedDB.
 *
 * These assert against `structuredClone` rather than against Dexie, on
 * purpose. Dexie in the test suite is backed by `fake-indexeddb`, which stores
 * whatever object it is handed and never applies the structured clone
 * algorithm — so a Proxy sails through the tests and throws
 * `DataCloneError: could not be cloned` in a real browser, on the first screen
 * an assessor touches.
 *
 * `structuredClone` is that algorithm, available directly. Testing against it
 * is testing the thing that actually rejects the write.
 */

describe('stripping reactivity before a write', () => {
    it('makes a reactive object storable', () => {
        const context = ref<Record<string, unknown>>({
            assessment_date: '2026-08-07',
            interviewee_name: 'Grace Phiri',
            refers_specimens: false,
        })

        // The failure this exists to prevent. If this ever stops throwing, the
        // browser has changed and the rest of this file is worth revisiting.
        expect(() => structuredClone(context.value)).toThrow()

        expect(() => structuredClone(plain(context.value))).not.toThrow()
        expect(plain(context.value)).toEqual({
            assessment_date: '2026-08-07',
            interviewee_name: 'Grace Phiri',
            refers_specimens: false,
        })
    })

    it('reaches nested objects, which toRaw alone does not', () => {
        const pathogens = ref([
            { key: 'hiv', name: 'HIV' },
            { key: 'syphilis', name: 'Syphilis' },
        ])

        const stored = plain(pathogens.value)

        expect(() => structuredClone(stored)).not.toThrow()
        expect(stored).toEqual([
            { key: 'hiv', name: 'HIV' },
            { key: 'syphilis', name: 'Syphilis' },
        ])
    })

    it('keeps a Blob intact, because rebuilding one would destroy a signature', () => {
        const blob = new Blob(['ink'], { type: 'image/png' })
        const attachment = reactive({ signedName: 'Grace Phiri', blob })

        const stored = plain(attachment)

        expect(stored.blob).toBeInstanceOf(Blob)
        expect(stored.blob.size).toBe(3)
    })

    it('passes through what was never reactive', () => {
        expect(plain('a string')).toBe('a string')
        expect(plain(null)).toBeNull()
        expect(plain(7)).toBe(7)
    })
})
