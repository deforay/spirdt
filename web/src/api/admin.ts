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

export interface AdminRole {
    id: number
    key: string
    name: string
    is_system: boolean
    permissions: string[]
    user_count: number
    /** False for a role that outranks the caller. The API refuses those too. */
    editable: boolean
}

export interface RoleCatalogue {
    roles: AdminRole[]
    /** Every permission this version knows, so a new one needs no rebuild here. */
    catalogue: string[]
    /** What the caller may hand out, which is exactly what they hold. */
    grantable: string[]
}

export async function listRoles(): Promise<RoleCatalogue> {
    return apiRequest<RoleCatalogue>('/admin/roles', { method: 'GET' })
}

/** The whole set, not the difference — see RoleAdminService for why. */
export async function updateRolePermissions(
    id: number,
    permissions: string[],
): Promise<AdminRole> {
    const body = await apiRequest<{ role: AdminRole }>(`/admin/roles/${id}/permissions`, {
        method: 'PATCH',
        body: { permissions },
    })

    return body.role
}

export interface AuditRow {
    id: number
    action: string
    actor_type: string
    actor_id: number | null
    actor_name: string | null
    actor_email: string | null
    entity_type: string | null
    entity_id: string | null
    metadata: Record<string, unknown> | null
    ip_address: string | null
    platform: string | null
    browser: string | null
    /** First eight characters, for comparing rows by eye. */
    session: string | null
    session_hash: string | null
    created_at: string
}

export interface AuditPage {
    rows: AuditRow[]
    total: number
    page: number
    per_page: number
    /** Every action this server can write, so the filter is not built from history. */
    actions: string[]
}

export interface AuditFilters {
    action?: string
    from?: string
    to?: string
    session_hash?: string
    page?: number
}

export async function listAudit(filters: AuditFilters = {}): Promise<AuditPage> {
    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '' && value !== null) {
            query.set(key, String(value))
        }
    }

    const suffix = query.toString()

    return apiRequest<AuditPage>(`/admin/audit${suffix === '' ? '' : `?${suffix}`}`, {
        method: 'GET',
    })
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
