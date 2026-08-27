import { apiFile, apiRequest } from './client'
import type { Page } from './registry'

/**
 * What was collected, as the management screens read it.
 *
 * Nothing here is computed on this side. The percentage, the level and the
 * band all arrive as the engine recorded them at the time of the visit — a
 * report that recalculated would change as the scoring code changed, which is
 * the one property a certificate must not have.
 */

export interface AssessmentRow {
    id: string
    status: string
    assessed_on: string | null
    /** Which round of auditing this belongs to. Free text; null when unrecorded. */
    audit_round: string | null
    submitted_at: string | null
    campaign_id: number | null
    campaign: string | null
    /** Where the assessor was standing. Null whenever no fix was available. */
    latitude: number | null
    longitude: number | null
    /** Metres. Null on a position inherited from the facility, which has none. */
    accuracy_m: number | null
    /** 'device' is evidence somebody was there; 'facility' is what the registry claims. */
    location_source: string | null
    site: string | null
    facility: string | null
    facility_code: string | null
    /** "Copperbelt › Kitwe", so a row says where it was without a second lookup. */
    place: string | null
    total_score: number | null
    total_possible: number | null
    percentage: number | null
    /** Null while the visit is still a draft and has never been scored. */
    level: number | null
    pathogens: number | null
}

export interface ReportFilters {
    geoUnitId?: number | null
    facilityId?: string | null
    testingSiteId?: string | null
    campaignId?: number | null
    status?: string | null
    from?: string | null
    to?: string | null
    level?: number | null
    search?: string | null
    page?: number
    perPage?: number
}

export interface Band {
    level: number
    label: string | null
    description: string | null
    min_percent: number
}

export interface SectionScore {
    number: number
    code: string
    title: string | null
    applicable: boolean
    score: number
    possible: number
    percentage: number | null
    answered: number
    /** Not Applicable, and therefore out of BOTH sides of the division. */
    excluded: number
}

export interface ReportAnswer {
    response: string
    label: string
    points: number | null
    comment: string | null
    pathogen_id: string | null
    pathogen: string | null
}

export interface ReportQuestion {
    code: string
    text: string | null
    guidance: string | null
    na_allowed: boolean
    /** Empty when the question was never answered, which the report still shows. */
    answers: ReportAnswer[]
    findings: number
}

/**
 * One photograph taken during the visit.
 *
 * The bytes are not in the report: `url` is served by the app, because these
 * files sit outside the document root and the organisation scope is the only
 * thing keeping one tenant's evidence away from another's.
 */
export interface ReportPhotograph {
    id: string
    /** The assessor's own words about what is in it. Null when they wrote none. */
    caption: string | null
    uploaded_at: string | null
    url: string
}

export interface ReportSection {
    number: number
    code: string
    title: string | null
    scope: string
    questions: ReportQuestion[]
    /** What was photographed here, in the order it was taken. */
    photographs: ReportPhotograph[]
}

export interface ReportFinding {
    id: string
    question_code: string
    question: string | null
    response: string
    gap: string
    recommendation: string | null
    responsibility_level: string
    urgency: 'immediate' | 'follow_up' | null
    responsible_person: string | null
    due_date: string | null
    status: string
    closed_on: string | null
    closure_note: string | null
    pathogen: string | null
}

export interface ReportSignature {
    id: string
    role: string | null
    signed_name: string | null
    uploaded_at: string | null
    /** Served by the app, because these files sit outside the document root. */
    url: string
}

/**
 * One Part A answer, as label and answer rather than as code and code.
 *
 * The assessment stores what was chosen — `facility_type: health_center` — and
 * only the template knows what that means, so the pairing is done on the
 * server where the instrument is. A `repeat` field carries `rows` instead of a
 * value: the staff who do the testing are a list, not a sentence.
 */
export interface ReportContextField {
    code: string
    label: string
    type: string
    value: string | null
    rows: { label: string; value: string }[][]
}

/**
 * One attempt to email this report out.
 *
 * Read off the audit trail rather than a table of its own, so a failed attempt
 * is here beside the successful ones — an administrator asked why a laboratory
 * never got their report needs to see that somebody tried.
 */
export interface ReportSend {
    sent: boolean
    to: string
    variant: PdfVariant
    /** Whether the evidence went with it. Two documents share one variant. */
    photographs: boolean
    /** What the mail server said, on an attempt that did not go. */
    reason: string | null
    by: string | null
    at: string
}

export interface Report {
    assessment: {
        id: string
        status: string
        assessed_on: string | null
        started_at: string | null
        ended_at: string | null
        submitted_at: string | null
        audit_round: string | null
        previous_assessed_on: string | null
        refers_specimens: boolean | null
        context: Record<string, unknown>
        site: { id: string; name: string | null; location: string | null }
        facility: {
            id: string
            name: string | null
            code: string | null
            type: string | null
            level: string | null
            address: string | null
            place: string | null
        }
        pathogens: { sequence: number; name: string; tests_description: string | null }[]
    }
    score:
        | { scored: false }
        | {
              scored: true
              total_score: number
              total_possible: number
              percentage: number | null
              level: number | null
              band: Band | null
              pathogen_count: number
              scoring_version: string
              scored_at: string | null
              sections: SectionScore[]
              pathogens: { key: string; score: number; possible: number }[]
              anomalies: { missing: string[]; unexpected: string[]; violations: string[] }
          }
    sections: ReportSection[]
    findings: ReportFinding[]
    /** Of the site itself, taken on the setup screen and belonging to no section. */
    site_photographs: ReportPhotograph[]
    signatures: ReportSignature[]
    /** Part A, read back through the instrument that asked it. */
    context_fields: ReportContextField[]
    /**
     * Every time this report was emailed out, and every time it was not.
     *
     * Empty for an account that may read the report without being able to send
     * it: who mailed a laboratory's score where belongs to whoever may send it
     * again.
     */
    sent: ReportSend[]
    /** The facility's contact, where a send goes unless somebody says otherwise. */
    recipient: string
}

export async function listAssessments(filters: ReportFilters = {}): Promise<Page<AssessmentRow>> {
    const query = new URLSearchParams()

    const map: Record<string, unknown> = {
        geo_unit_id: filters.geoUnitId,
        facility_id: filters.facilityId,
        testing_site_id: filters.testingSiteId,
        campaign_id: filters.campaignId,
        status: filters.status,
        from: filters.from,
        to: filters.to,
        level: filters.level,
        search: filters.search,
        page: filters.page,
        per_page: filters.perPage,
    }

    for (const [key, value] of Object.entries(map)) {
        // Level 0 is a real level and the lowest one, so this tests for absence
        // rather than for falsiness. Filtering on "needs improvement in all
        // areas" is the first thing anybody will do.
        if (value === null || value === undefined || value === '') {
            continue
        }

        query.set(key, String(value))
    }

    const suffix = query.toString()

    return apiRequest<Page<AssessmentRow>>(
        `/admin/reports/assessments${suffix === '' ? '' : `?${suffix}`}`,
    )
}

export async function fetchReport(id: string, locale: string): Promise<Report> {
    return apiRequest<Report>(
        `/admin/reports/assessments/${encodeURIComponent(id)}?locale=${encodeURIComponent(locale)}`,
    )
}

/**
 * Which document. `full` is the record — every question, answered or not.
 * `actions` is the site's details and the work it has to do, which is the part
 * anybody has to act on and two pages rather than seven.
 */
export type PdfVariant = 'full' | 'actions'

/**
 * The same report as a file, saved where the browser saves things.
 *
 * Fetched rather than linked. Every call carries the token in a header, and a
 * plain link carries nothing — so an anchor pointed at this endpoint answers
 * 401 and shows the reader a blank tab. It arrives as bytes and is handed to
 * the browser under the name the server chose.
 *
 * Photographs are the caller's decision. Five per section at a phone camera's
 * resolution is a file too big to email, and somebody sending a summary to a
 * ministry wants the numbers rather than the evidence.
 */
export async function downloadAssessmentPdf(
    id: string,
    locale: string,
    photographs: boolean,
    variant: PdfVariant = 'full',
): Promise<void> {
    const query = new URLSearchParams({
        locale,
        photographs: photographs ? '1' : '0',
        variant,
    })

    const file = await apiFile(
        `/admin/reports/assessments/${encodeURIComponent(id)}/pdf?${query.toString()}`,
        'report.pdf',
        // Longer than the thirty seconds every other call gets. Nothing comes
        // back until the whole document is composited, and a visit carrying
        // twenty megabytes of photographs is a minute of work on a small
        // server — aborting it makes exactly the reports that most need the
        // evidence the ones that cannot be downloaded.
        { timeoutMs: 180_000 },
    )

    const url = URL.createObjectURL(file.blob)
    const link = document.createElement('a')

    link.href = url
    link.download = file.filename
    document.body.appendChild(link)
    link.click()
    link.remove()

    // Freed on the next tick rather than immediately: revoking while the click
    // is still being handled cancels the download in some browsers.
    setTimeout(() => URL.revokeObjectURL(url), 0)
}

/**
 * Email this report to the site it is about.
 *
 * The address is optional: without one the facility's recorded contact is
 * used, and an address given for a facility that has none is kept, so the next
 * send does not ask again.
 *
 * Slower than an ordinary call — a document is rendered and a mail server is
 * spoken to before anything comes back — so it is given the same room the
 * download is.
 */
export async function sendAssessmentReport(
    id: string,
    options: { locale: string; variant: PdfVariant; photographs: boolean; email?: string },
): Promise<{
    to: string
    variant: PdfVariant
    photographs: boolean
    filename: string
    remembered: boolean
}> {
    return apiRequest(`/admin/reports/assessments/${encodeURIComponent(id)}/send`, {
        method: 'POST',
        body: {
            locale: options.locale,
            variant: options.variant,
            photographs: options.photographs,
            ...(options.email === undefined ? {} : { email: options.email }),
        },
        timeoutMs: 180_000,
    })
}
