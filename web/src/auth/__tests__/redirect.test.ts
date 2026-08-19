import { describe, expect, it } from 'vitest'

import { intendedPath } from '../redirect'

/**
 * The value under test arrives in a URL that anybody can write, so the cases
 * that matter are the hostile ones. A miss here does not break a screen — it
 * turns the sign-in page into a working redirect to somewhere else.
 */
describe('intendedPath', () => {
    it('keeps a path on this origin', () => {
        expect(intendedPath('/assess')).toBe('/assess')
        expect(intendedPath('/admin/reports/42?tab=scores')).toBe('/admin/reports/42?tab=scores')
    })

    it('refuses another site', () => {
        expect(intendedPath('https://evil.example/')).toBeNull()
        expect(intendedPath('http://evil.example/')).toBeNull()
    })

    /**
     * The one that looks like a path and is not: browsers read a leading "//"
     * as "same scheme, different host".
     */
    it('refuses a protocol-relative URL', () => {
        expect(intendedPath('//evil.example/')).toBeNull()
    })

    /** And the same trick spelled with the slash browsers normalise. */
    it('refuses a backslash that normalises into one', () => {
        expect(intendedPath('/\\evil.example/')).toBeNull()
    })

    it('refuses anything that is not a path at all', () => {
        expect(intendedPath('assess')).toBeNull()
        expect(intendedPath('javascript:alert(1)')).toBeNull()
        expect(intendedPath(undefined)).toBeNull()
        expect(intendedPath(null)).toBeNull()
        expect(intendedPath(['/assess', '/admin'])).toBeNull()
    })
})
