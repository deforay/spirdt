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
    /** Assigned to this assessor's organisation, to whoever within it. */
    assigned: boolean
    /**
     * Assigned to this assessor, or to their organisation with nobody named.
     *
     * A site assigned to a named colleague is `assigned` but not
     * `assigned_to_me`: visible, so somebody can cover for them, and off the
     * default list, so the default list means something.
     */
    assigned_to_me: boolean
}

interface SitesResponse {
    sites: Site[]
    count: number
    fetched_at: string
}

export function cachedSites(): Site[] {
    try {
        const raw = localStorage.getItem(CACHE_KEY)

        if (raw === null) {
            return []
        }

        // A list cached before assignments existed has neither flag. Reading a
        // missing flag as false would empty the assessor's list on the first
        // run after an update, with the sites plainly still there — so an
        // unannotated entry is treated as assigned rather than as unassigned.
        return (JSON.parse(raw) as Site[]).map((site) => ({
            ...site,
            assigned: site.assigned ?? true,
            assigned_to_me: site.assigned_to_me ?? true,
        }))
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
