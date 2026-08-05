import { apiRequest } from './client'

/**
 * The list of testing sites, cached for offline use.
 *
 * Reference data, not work — it comes from the server and can always be
 * fetched again. That is why it lives in localStorage rather than in the
 * database next to the assessments: nothing here is irreplaceable, and mixing
 * it in with things that are makes the durable store harder to reason about.
 *
 * The cache is what makes the app usable at a site with no signal. A fetch that
 * fails falls back to it rather than showing an empty list, because an assessor
 * standing in a clinic needs the site they are standing in, not an error.
 */

const CACHE_KEY = 'spirdt.sites'

export interface Site {
    id: string
    name: string
    facility_id: string
    facility_name: string | null
}

interface SitesResponse {
    sites: Site[]
    count: number
    fetched_at: string
}

export function cachedSites(): Site[] {
    try {
        const raw = localStorage.getItem(CACHE_KEY)

        return raw === null ? [] : (JSON.parse(raw) as Site[])
    } catch {
        return []
    }
}

export async function fetchSites(): Promise<Site[]> {
    try {
        const body = await apiRequest<SitesResponse>('/sites', { method: 'GET' })

        try {
            localStorage.setItem(CACHE_KEY, JSON.stringify(body.sites))
        } catch {
            // Out of storage. The list still works for this session.
        }

        return body.sites
    } catch {
        // Offline, or the server is down. Whatever was cached last is better
        // than nothing, and is very likely still correct.
        return cachedSites()
    }
}
