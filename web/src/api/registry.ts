import { apiRequest } from './client'

/**
 * The national list, as the management screens see it.
 *
 * The geographic tree arrives flat and is assembled here. That is deliberate
 * on both sides: a national list is a few hundred rows, one payload is
 * cacheable, and a request per level would be a request per keystroke on a
 * cascade.
 */

export interface GeoUnit {
    id: number
    parent_id: number | null
    /** Free text — "Province", "Region", "Woreda". Never assume two levels. */
    level: string
    name: string
    code: string | null
    is_active: boolean
}

export interface Page<T> {
    rows: T[]
    total: number
    page: number
    per_page: number
}

export interface Facility {
    id: string
    geo_unit_id: number | null
    /** "Copperbelt › Kitwe". Sent by the server so a row says where it is. */
    place: string | null
    name: string
    code: string | null
    facility_type: string | null
    level: string | null
    affiliation: string | null
    address: string | null
    contact_name: string | null
    contact_phone: string | null
    contact_email: string | null
    latitude: number | null
    longitude: number | null
    /** 'field' means an assessor created it on the spot and nobody has reconciled it. */
    source: string
    is_active: boolean
}

/** What the instrument allows for type, level and affiliation. */
export interface FacilityOption {
    key: string
    label: string
}

export interface FacilityOptions {
    facility_type: FacilityOption[]
    level: FacilityOption[]
    affiliation: FacilityOption[]
}

export type FacilityInput = Partial<
    Pick<
        Facility,
        | 'name'
        | 'code'
        | 'geo_unit_id'
        | 'facility_type'
        | 'level'
        | 'affiliation'
        | 'address'
        | 'contact_name'
        | 'contact_phone'
        | 'contact_email'
        | 'latitude'
        | 'longitude'
        | 'is_active'
    >
>

export interface RegistryTestingSite {
    id: string
    facility_id: string
    facility_name: string | null
    place: string | null
    name: string
    location_description: string | null
    source: string
    is_active: boolean
}

export interface Assignment {
    id: number
    testing_site_id: string
    user_id: number | null
    campaign_id: number | null
    due_on: string | null
    is_active: boolean
}

export interface GeoTree {
    units: GeoUnit[]
    /** id → "Copperbelt › Kitwe", built once by the server rather than per row. */
    paths: Record<number, string>
}

export async function listGeoUnits(): Promise<GeoTree> {
    const body = await apiRequest<{ geo_units: GeoUnit[]; paths: Record<number, string> }>(
        '/admin/geo-units',
        { method: 'GET' },
    )

    return { units: body.geo_units, paths: body.paths }
}

export async function createGeoUnit(input: {
    name: string
    level: string
    parent_id?: number | null
}): Promise<GeoUnit> {
    return (await apiRequest<{ geo_unit: GeoUnit }>('/admin/geo-units', { body: input })).geo_unit
}

export async function updateGeoUnit(
    id: number,
    patch: Partial<Pick<GeoUnit, 'name' | 'level' | 'code' | 'is_active'>>,
): Promise<GeoUnit> {
    return (
        await apiRequest<{ geo_unit: GeoUnit }>(`/admin/geo-units/${id}`, {
            method: 'PATCH',
            body: patch,
        })
    ).geo_unit
}

export interface ListQuery {
    geoUnitId?: number | null
    search?: string
    page?: number
    perPage?: number
}

/**
 * Nothing here fetches "all of them".
 *
 * A national registry runs to thousands of facilities. Every list is a page
 * with a total beside it, because "50 of 1,240" is the difference between a
 * list somebody trusts and one they scroll to the bottom of hoping it ended.
 */
function queryString(query: ListQuery & { facilityId?: string | null }): string {
    const parts = new URLSearchParams()

    if (query.geoUnitId !== null && query.geoUnitId !== undefined) {
        parts.set('geo_unit', String(query.geoUnitId))
    }

    if (query.facilityId !== null && query.facilityId !== undefined && query.facilityId !== '') {
        parts.set('facility', query.facilityId)
    }

    if ((query.search ?? '').trim() !== '') {
        parts.set('q', (query.search ?? '').trim())
    }

    parts.set('page', String(query.page ?? 1))

    if (query.perPage !== undefined) {
        parts.set('per_page', String(query.perPage))
    }

    return `?${parts.toString()}`
}

export async function listFacilities(query: ListQuery = {}): Promise<Page<Facility>> {
    return apiRequest<Page<Facility>>(`/admin/facilities${queryString(query)}`, { method: 'GET' })
}

export async function getFacility(id: string): Promise<Facility> {
    return (await apiRequest<{ facility: Facility }>(`/admin/facilities/${id}`, { method: 'GET' }))
        .facility
}

export async function facilityOptions(locale: string): Promise<FacilityOptions> {
    return (
        await apiRequest<{ options: FacilityOptions }>(`/admin/facility-options?locale=${locale}`, {
            method: 'GET',
        })
    ).options
}

export async function createFacility(input: FacilityInput): Promise<Facility> {
    return (await apiRequest<{ facility: Facility }>('/admin/facilities', { body: input })).facility
}

/**
 * Fold a duplicate into the record that survives.
 *
 * Nothing is deleted: the loser keeps its row and gains a pointer, because
 * assessments already reference it and "which facility was this visit against?"
 * has to keep resolving.
 */
export async function mergeFacility(loserId: string, intoId: string): Promise<Facility> {
    return (
        await apiRequest<{ facility: Facility }>(`/admin/facilities/${loserId}/merge`, {
            body: { into: intoId },
        })
    ).facility
}

export async function updateFacility(id: string, patch: FacilityInput): Promise<Facility> {
    return (
        await apiRequest<{ facility: Facility }>(`/admin/facilities/${id}`, {
            method: 'PATCH',
            body: patch,
        })
    ).facility
}

export async function listTestingSites(
    query: ListQuery & { facilityId?: string | null } = {},
): Promise<Page<RegistryTestingSite>> {
    return apiRequest<Page<RegistryTestingSite>>(`/admin/testing-sites${queryString(query)}`, {
        method: 'GET',
    })
}

export async function createTestingSite(input: {
    name: string
    facility_id: string
}): Promise<RegistryTestingSite> {
    return (
        await apiRequest<{ testing_site: RegistryTestingSite }>('/admin/testing-sites', {
            body: input,
        })
    ).testing_site
}

export async function updateTestingSite(
    id: string,
    patch: Partial<Pick<RegistryTestingSite, 'name' | 'is_active'>>,
): Promise<RegistryTestingSite> {
    return (
        await apiRequest<{ testing_site: RegistryTestingSite }>(`/admin/testing-sites/${id}`, {
            method: 'PATCH',
            body: patch,
        })
    ).testing_site
}

export async function listAssignments(): Promise<Assignment[]> {
    return (await apiRequest<{ assignments: Assignment[] }>('/admin/assignments', { method: 'GET' }))
        .assignments
}

export async function createAssignment(input: {
    testing_site_id: string
    user_id?: number | null
    campaign_id?: number | null
    due_on?: string | null
}): Promise<{ id: number }> {
    return (await apiRequest<{ assignment: { id: number } }>('/admin/assignments', { body: input }))
        .assignment
}

export async function withdrawAssignment(id: number): Promise<void> {
    await apiRequest(`/admin/assignments/${id}`, { method: 'DELETE' })
}
