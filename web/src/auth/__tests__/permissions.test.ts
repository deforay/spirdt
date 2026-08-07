import { afterEach, describe, expect, it } from 'vitest'

import { can, canAny, canManage, PERMISSION } from '../permissions'
import { session, type SessionUser } from '../session'

/**
 * What the app shows, not what the API allows.
 *
 * The cases worth holding are the ones where being wrong hides work or offers
 * work that will be refused: a session saved before permissions existed, and an
 * account that holds none at all.
 */

function signedInWith(permissions: string[] | undefined): void {
    session.value = {
        accessToken: 'token',
        refreshToken: 'refresh',
        expiresAt: Date.now() + 900_000,
        user: {
            id: 1,
            email: 'someone@example.org',
            fullName: 'Someone',
            role: 'viewer',
            permissions,
            organizationId: 1,
            organization: 'Org',
            mustChangePassword: false,
        } as SessionUser,
    }
}

afterEach(() => {
    session.value = null
})

describe('can', () => {
    it('reads the list the session carries', () => {
        signedInWith([PERMISSION.reportsRead])

        expect(can(PERMISSION.reportsRead)).toBe(true)
        expect(can(PERMISSION.usersManage)).toBe(false)
    })

    /**
     * The upgrade case. A session saved by the previous version has no list at
     * all, and reading `undefined` as "everything" would fill the navigation
     * with links that all refuse. Hiding them costs one sign-in.
     */
    it('treats a session saved before permissions existed as holding nothing', () => {
        signedInWith(undefined)

        expect(can(PERMISSION.reportsRead)).toBe(false)
        expect(canManage()).toBe(false)
    })

    it('holds nothing when signed out', () => {
        session.value = null

        expect(can(PERMISSION.assessmentsSubmit)).toBe(false)
    })

    it('does not confuse one permission for another that shares a prefix', () => {
        signedInWith([PERMISSION.registryRead])

        expect(can(PERMISSION.registryWrite)).toBe(false)
    })
})

describe('canManage', () => {
    it('is true on any single management permission', () => {
        signedInWith([PERMISSION.registryRead])

        expect(canManage()).toBe(true)
    })

    /**
     * An assessor is not management. This is what decides where "/" sends
     * somebody, so getting it wrong lands a field worker on a dashboard they
     * cannot read and away from the checklist they opened the app for.
     */
    it('is false for an account that can only file assessments', () => {
        signedInWith([PERMISSION.assessmentsSubmit])

        expect(canManage()).toBe(false)
        expect(canAny(PERMISSION.assessmentsSubmit)).toBe(true)
    })

    it('is false for an account holding nothing', () => {
        signedInWith([])

        expect(canManage()).toBe(false)
    })
})
