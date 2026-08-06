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

export interface Facility {
    id: string
    geo_unit_id: number | null
    name: string
    code: string | null
    facility_type: string | null
    level: string | null
    affiliation: string | null
    /** 'field' means an assessor created it on the spot and nobody has reconciled it. */
    source: string
    is_active: boolean
}

export interface RegistryTestingSite {
    id: string
    facility_id: string
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

export async function listGeoUnits(): Promise<GeoUnit[]> {
    return (await apiRequest<{ geo_units: GeoUnit[] }>('/admin/geo-units', { method: 'GET' }))
        .geo_units
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

export async function listFacilities(geoUnitId?: number | null): Promise<Facility[]> {
    const query = geoUnitId === null || geoUnitId === undefined ? '' : `?geo_unit=${geoUnitId}`

    return (await apiRequest<{ facilities: Facility[] }>(`/admin/facilities${query}`, { method: 'GET' }))
        .facilities
}

export async function createFacility(input: {
    name: string
    geo_unit_id?: number | null
    facility_type?: string
    affiliation?: string
}): Promise<Facility> {
    return (await apiRequest<{ facility: Facility }>('/admin/facilities', { body: input })).facility
}

export async function updateFacility(
    id: string,
    patch: Partial<Pick<Facility, 'name' | 'geo_unit_id' | 'facility_type' | 'affiliation' | 'is_active'>>,
): Promise<Facility> {
    return (
        await apiRequest<{ facility: Facility }>(`/admin/facilities/${id}`, {
            method: 'PATCH',
            body: patch,
        })
    ).facility
}

export async function listTestingSites(facilityId?: string | null): Promise<RegistryTestingSite[]> {
    const query = facilityId === null || facilityId === undefined ? '' : `?facility=${facilityId}`

    return (
        await apiRequest<{ testing_sites: RegistryTestingSite[] }>(`/admin/testing-sites${query}`, {
            method: 'GET',
        })
    ).testing_sites
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
