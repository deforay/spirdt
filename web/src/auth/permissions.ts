import { computed } from 'vue'

import type { MessageKey } from '@/i18n'

import { session } from './session'

/**
 * What the signed-in account may do.
 *
 * THIS DECIDES WHAT IS SHOWN, NEVER WHAT IS ALLOWED. The list arrives with the
 * sign-in response and lives in localStorage, where the person holding the
 * device can edit it. Every route on the API re-reads the grants from the
 * database on every request, so editing this buys a screen full of buttons that
 * all return 403.
 *
 * The point of having it at all is that the alternative is worse. Without it
 * the app guesses from the role name, and a role whose permissions an
 * organisation has changed then shows links that refuse and hides ones that
 * would have worked.
 *
 * The keys mirror App\Auth\Permission. They are stored strings rather than
 * code, so they are never renamed — see that file for what that costs.
 */
export const PERMISSION = {
    assessmentsSubmit: 'assessments.submit',
    registryRead: 'registry.read',
    registryWrite: 'registry.write',
    assignmentsWrite: 'assignments.write',
    reportsRead: 'reports.read',
    reportsSend: 'reports.send',
    usersManage: 'users.manage',
    rolesManage: 'roles.manage',
    auditRead: 'audit.read',
    organizationsManage: 'organizations.manage',
    settingsManage: 'settings.manage',
} as const

export type PermissionKey = (typeof PERMISSION)[keyof typeof PERMISSION]

/**
 * Absent rather than empty on a session saved before this shipped.
 *
 * Treated as holding nothing, because showing a link somebody does not have
 * sends them to a screen that refuses. But "absent" and "empty" are different
 * facts and only one of them is a decision — see permissionsUnknown().
 */
function held(): readonly string[] {
    return session.value?.user.permissions ?? []
}

/**
 * Signed in, on a session that predates permissions existing.
 *
 * THE UPGRADE CASE, and it is not hypothetical: every session open at the
 * moment this shipped carries no permission list, so every one of them read as
 * holding nothing and landed on the no-access screen — including a superadmin
 * holding all nine. The account was fine; the copy of it in localStorage was
 * from a version that had never heard of the field.
 *
 * Distinguishing absent from empty is what lets the app repair itself: a
 * session with no list can be refreshed into one, whereas a session with an
 * empty list is an account that genuinely holds nothing and refreshing it
 * would change nothing.
 */
export function permissionsUnknown(): boolean {
    return session.value !== null && session.value.user.permissions === undefined
}

export function can(permission: PermissionKey): boolean {
    return held().includes(permission)
}

export function canAny(...permissions: PermissionKey[]): boolean {
    return permissions.some((permission) => can(permission))
}

/**
 * Whether this account has any business in the management app at all.
 *
 * Used to decide where "/" sends somebody, so it asks the broad question. An
 * account holding any one of these has at least one screen worth landing on.
 */
export function canManage(): boolean {
    return canAny(
        PERMISSION.reportsRead,
        PERMISSION.registryRead,
        PERMISSION.usersManage,
        PERMISSION.rolesManage,
        PERMISSION.auditRead,
        PERMISSION.organizationsManage,
        PERMISSION.settingsManage,
    )
}

/**
 * The best screen this account can actually open.
 *
 * Lives here rather than in the router because it is a question about
 * permissions, not about routing — and because the router cannot be tested
 * without a DOM, while both bugs this app has had in its navigation were in
 * exactly this decision. The first sent an account with nothing anywhere and
 * back again until vue-router gave up; the second sent one to a dead end it
 * then had no way to leave.
 *
 * Returns 'no-access' ONLY when there is genuinely nowhere to go. The guard
 * that lets somebody off that screen keys off the same answer, so the two
 * cannot disagree about whether the dead end is warranted.
 */
export function landing(): string {
    if (can(PERMISSION.reportsRead)) {
        return 'admin-dashboard'
    }

    if (can(PERMISSION.registryRead)) {
        return 'admin-places'
    }

    if (can(PERMISSION.usersManage)) {
        return 'admin-users'
    }

    if (can(PERMISSION.rolesManage)) {
        return 'admin-roles'
    }

    if (can(PERMISSION.auditRead)) {
        return 'admin-audit'
    }

    if (can(PERMISSION.organizationsManage)) {
        return 'admin-organizations'
    }

    if (can(PERMISSION.settingsManage)) {
        return 'admin-settings'
    }

    if (can(PERMISSION.assessmentsSubmit)) {
        return 'assess'
    }

    return 'no-access'
}

/**
 * What to call the screen landing() picked.
 *
 * Beside landing() rather than anywhere else, for the reason that function
 * gives about the guard: two places deciding the same thing separately is two
 * places that can disagree, and here the disagreement is a link that says one
 * screen and opens another. A link into the management side used to be called
 * "Management console" — a name no screen wears — and calling it Dashboard
 * instead was right for the account that lands on the dashboard and a lie for
 * the one that lands on Geography.
 *
 * The keys are the ones the management navigation already uses for the same
 * routes, so a link into that side is called what it is called once you are
 * there.
 */
export function landingLabel(): MessageKey {
    switch (landing()) {
        case 'admin-dashboard':
            return 'dash.title'
        case 'admin-places':
            return 'places.title'
        case 'admin-users':
            return 'admin.users'
        case 'admin-roles':
            return 'roles.title'
        case 'admin-audit':
            return 'audit.title'
        case 'admin-organizations':
            return 'organizations.title'
        case 'admin-settings':
            return 'settings.title'
        default:
            return 'dash.title'
    }
}

/** The same, for templates, so a change of account re-renders the navigation. */
export const permissions = computed(() => held())
