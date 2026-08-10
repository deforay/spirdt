import { computed } from 'vue'

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
    usersManage: 'users.manage',
    rolesManage: 'roles.manage',
    auditRead: 'audit.read',
    organizationsManage: 'organizations.manage',
} as const

export type PermissionKey = (typeof PERMISSION)[keyof typeof PERMISSION]

/**
 * Absent rather than empty on a session saved before this shipped.
 *
 * Treated as holding nothing, which hides every link until the next sign-in
 * refreshes the session. Hiding a link somebody has is recoverable in one
 * sign-in. Showing one they do not have sends them to a screen that refuses.
 */
function held(): readonly string[] {
    return session.value?.user.permissions ?? []
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
    )
}

/** The same, for templates, so a change of account re-renders the navigation. */
export const permissions = computed(() => held())
