import { apiRequest } from './client'

/**
 * The management endpoints.
 *
 * Not cached, unlike the site list: this is read on a desk with a connection,
 * and a stale copy of who has access is worse than no copy. Everything here is
 * refused by the API for anyone without an administrative role, so the screens
 * that use it are a convenience rather than the control.
 */

export interface AdminUser {
    id: number
    email: string
    full_name: string
    title: string | null
    phone: string | null
    role: string
    is_active: boolean
    must_change_password: boolean
    last_login_at: string | null
}

/** Assignable in the UI. Superadmin is created by provisioning, not here. */
export const ASSIGNABLE_ROLES = ['admin', 'assessor', 'viewer', 'site_user'] as const

export async function listUsers(): Promise<AdminUser[]> {
    const body = await apiRequest<{ users: AdminUser[] }>('/admin/users', { method: 'GET' })

    return body.users
}

export interface NewUser {
    email: string
    full_name: string
    role: string
    title?: string
    phone?: string
}

/**
 * The password comes back exactly once and is never retrievable again, so the
 * caller has to put it in front of somebody before it is lost.
 */
export async function createUser(input: NewUser): Promise<{ user: AdminUser; password: string }> {
    return apiRequest<{ user: AdminUser; password: string }>('/admin/users', { body: input })
}

export async function updateUser(
    id: number,
    patch: Partial<Pick<AdminUser, 'full_name' | 'title' | 'phone' | 'role' | 'is_active'>>,
): Promise<AdminUser> {
    const body = await apiRequest<{ user: AdminUser }>(`/admin/users/${id}`, {
        method: 'PATCH',
        body: patch,
    })

    return body.user
}

export async function resetUserPassword(
    id: number,
): Promise<{ user: AdminUser; password: string }> {
    return apiRequest<{ user: AdminUser; password: string }>(`/admin/users/${id}/password`, {
        body: {},
    })
}
