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

export interface DashboardSummary {
    totals: {
        assessments: number
        sites: number
        facilities: number
        drafts: number
        known_sites: number
    }
    levels: Array<{ level: number; count: number }>
    recent: Array<{ level: number; count: number }>
    /** The second horizon: one window shows noise, two show a direction. */
    trend: Array<{ level: number; count: number }>
    months: Array<{ month: string; count: number; mean: number | null }>
    sections: Array<{ code: string; name: string; mean: number; assessments: number }>
    latest: Array<{
        id: string
        facility: string | null
        site: string | null
        assessed_on: string
        percentage: number | null
        level: number | null
    }>
    map: Array<{
        id: string
        lat: number
        lng: number
        accuracy_m: number | null
        /** 'device' is where the assessor stood; 'facility' is what the registry claims. */
        source: string | null
        site: string | null
        facility: string | null
        assessed_on: string
        percentage: number | null
        level: number | null
    }>
    /** Band labels from the published instrument, not from this bundle. */
    bands: Array<{ level: number; label: string; min_percent: number }>
    /** True when the figures pool visits judged by different band definitions. */
    mixed_versions?: boolean
}

export interface DashboardFilters {
    from?: string
    to?: string
    /** Geographic unit id. Matches its whole subtree, as the registry does. */
    place?: number | null
}

export async function loadDashboard(
    locale: string,
    filters: DashboardFilters = {},
): Promise<DashboardSummary> {
    const query = new URLSearchParams({ locale })

    if (filters.from) {
        query.set('from', filters.from)
    }
    if (filters.to) {
        query.set('to', filters.to)
    }
    if (filters.place !== null && filters.place !== undefined) {
        query.set('place', String(filters.place))
    }

    return apiRequest<DashboardSummary>(`/admin/dashboard?${query.toString()}`, { method: 'GET' })
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
    per_page: number
    /** Every action this server can write, so the filter is not built from history. */
    actions: string[]
}

export interface AuditFilters {
    action?: string
    from?: string
    to?: string
    session_hash?: string
    /** Walk back from this row. Omit for the newest page. */
    before_id?: number
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

export interface RequestRow {
    id: number
    method: string
    path: string
    status: number
    duration_ms: number | null
    user_id: number | null
    user_name: string | null
    user_email: string | null
    /** First eight characters, for comparing rows by eye. */
    session: string | null
    session_hash: string | null
    /** Joins this row to the lines in var/log written while it was handled. */
    request_uid: string | null
    /** JSON as stored, with the secret-bearing keys already replaced server-side. */
    body: string | null
    ip_address: string | null
    user_agent: string | null
    platform: string | null
    browser: string | null
    device_id: string | null
    app_version: string | null
    created_at: string
}

export interface RequestPage {
    rows: RequestRow[]
    total: number
    per_page: number
    /** Every method the API can be called with, so the filter is not built from history. */
    methods: string[]
}

export interface RequestFilters {
    method?: string
    /** 'failed' | '4xx' | '5xx' | an exact code. */
    status?: string
    /** Matched as a substring, so a route is findable without knowing its endpoints. */
    path?: string
    session_hash?: string
    from?: string
    to?: string
    /** Walk back from this row. Omit for the newest page. */
    before_id?: number
}

export async function listRequests(filters: RequestFilters = {}): Promise<RequestPage> {
    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '' && value !== null) {
            query.set(key, String(value))
        }
    }

    const suffix = query.toString()

    return apiRequest<RequestPage>(`/admin/requests${suffix === '' ? '' : `?${suffix}`}`, {
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
