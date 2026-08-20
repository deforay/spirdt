import { apiRequest } from './client'
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

export interface ReportSection {
    number: number
    code: string
    title: string | null
    scope: string
    questions: ReportQuestion[]
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
    signatures: ReportSignature[]
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
