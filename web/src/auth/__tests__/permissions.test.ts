import { afterEach, describe, expect, it } from 'vitest'

import { can, canAny, canManage, landing, PERMISSION } from '../permissions'
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

describe('landing', () => {
    /**
     * Where "/" sends somebody, and the answer the no-access guard keys off.
     *
     * Both navigation bugs this app has had were here. The first sent an
     * account holding nothing to a screen that refused it and back again until
     * vue-router gave up — a site_user is seeded for every organisation and
     * granted nothing, so it happened on a first sign-in. The second sent an
     * account to that dead end and then had no way to take it off again: the
     * screen asks for no permission, so the guard had nothing to check and
     * rendered it for ever, to a superadmin holding all nine.
     */
    it('prefers the dashboard for anybody who can read reports', () => {
        signedInWith([PERMISSION.reportsRead, PERMISSION.registryRead])

        expect(landing()).toBe('admin-dashboard')
    })

    it('falls through to whatever the account can actually open', () => {
        signedInWith([PERMISSION.usersManage])
        expect(landing()).toBe('admin-users')

        signedInWith([PERMISSION.auditRead])
        expect(landing()).toBe('admin-audit')

        signedInWith([PERMISSION.assessmentsSubmit])
        expect(landing()).toBe('assess')
    })

    /**
     * The dead end is warranted only here. Anywhere else would refuse and send
     * them back, which is a loop rather than a message.
     */
    it('is the dead end only when there is nowhere to go', () => {
        signedInWith([])

        expect(landing()).toBe('no-access')
    })

    /**
     * And the account in the bug: every permission there is, and it was sitting
     * on the dead end. The guard asks this, so a wrong answer here is that
     * screen becoming permanent again.
     */
    it('never strands an account that holds everything', () => {
        signedInWith(Object.values(PERMISSION))

        expect(landing()).not.toBe('no-access')
    })

    it('sends a signed-out visitor nowhere in particular', () => {
        session.value = null

        expect(landing()).toBe('no-access')
    })
})
