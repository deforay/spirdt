import type { AcknowledgedAnswer } from '../db/assessments'
import type { StoredAnswer, StoredAssessment, StoredFinding } from '../db/database'

/**
 * Turning what is on the device into what the server accepts.
 *
 * Two things are deliberate here.
 *
 * Only dirty answers are sent. The server upserts on a natural key and keeps
 * what it already has, so re-sending an acknowledged answer changes nothing and
 * costs an assessor on a metered phone connection real money.
 *
 * Every answer sent is paired with the revision that was sent. The engine needs
 * that to know, when the acknowledgement comes back, whether the answer it is
 * about to mark clean is still the one it sent.
 */

export interface SyncPayload {
    id: string
    testing_site_id: string
    facility_id: string
    template_code: string
    template_version: string
    assessed_on: string
    status: 'draft' | 'submitted'
    context: Record<string, unknown>
    device_id: string
    started_at: string
    pathogens: Array<{ key: string; name: string }>
    answers: Array<{
        question_code: string
        pathogen?: string
        response: string
        comment?: string
        answered_at?: string
    }>
    findings: Array<{
        id: string
        question_code: string
        pathogen?: string
        response: string
        gap: string
        recommendation?: string
        responsibility_level: string
        urgency?: string
        responsible_person?: string
        due_date?: string
    }>
}

export interface BuiltPayload {
    payload: SyncPayload
    /** Exactly what went in `answers`, as it was when it went. */
    sent: AcknowledgedAnswer[]
    /** The same, for `findings`. */
    sentFindings: AcknowledgedAnswer[]
}

/** Why an assessment cannot be sent yet. Shown to a person, so it says what to do. */
export class NotSendable extends Error {}

export function buildPayload(
    assessment: StoredAssessment,
    answers: StoredAnswer[],
    deviceId: string,
    findings: StoredFinding[] = [],
): BuiltPayload {
    if (assessment.siteId === null || assessment.facilityId === null) {
        // The server stores a visit against a real site, so there is nothing
        // useful to send yet. This is a gap in the assessment, not a fault, and
        // it is fixed by choosing the site rather than by retrying.
        throw new NotSendable('Choose the testing site before syncing this assessment.')
    }

    if (assessment.assessedOn === '') {
        throw new NotSendable('Set the date of the visit before syncing this assessment.')
    }

    const sendable = answers.filter((answer) => answer.dirty && answer.response !== null)

    // A finding with no gap described is one the assessor opened and did not
    // fill in. Sending it would put an empty row in the site's action list.
    const sendableFindings = findings.filter(
        (finding) => finding.dirty && finding.gap.trim() !== '',
    )

    return {
        payload: {
            id: assessment.id,
            testing_site_id: assessment.siteId,
            facility_id: assessment.facilityId,
            template_code: assessment.templateCode,
            template_version: assessment.templateVersion,
            assessed_on: assessment.assessedOn,
            // Only a submitted visit is reported as one. Anything still being
            // worked on syncs as a draft, which is a backup, not a submission.
            status: assessment.status === 'submitted' ? 'submitted' : 'draft',
            context: assessment.context,
            device_id: deviceId,
            started_at: assessment.startedAt,
            pathogens: assessment.pathogens.map((pathogen) => ({
                key: pathogen.key,
                name: pathogen.name,
            })),
            answers: sendable.map((answer) => ({
                question_code: answer.questionCode,
                ...(answer.pathogen === null ? {} : { pathogen: answer.pathogen }),
                response: answer.response as string,
                ...(answer.comment === '' ? {} : { comment: answer.comment }),
                ...(answer.answeredAt === null ? {} : { answered_at: answer.answeredAt }),
            })),
            findings: sendableFindings.map((finding) => ({
                // The finding's own id, which is what the server upserts on
                // now that one question may carry several.
                id: finding.key,
                question_code: finding.questionCode,
                ...(finding.pathogen === null ? {} : { pathogen: finding.pathogen }),
                response: finding.response,
                gap: finding.gap,
                ...(finding.recommendation === '' ? {} : { recommendation: finding.recommendation }),
                responsibility_level: finding.responsibilityLevel,
                ...(finding.urgency === null ? {} : { urgency: finding.urgency }),
                ...(finding.responsiblePerson === ''
                    ? {}
                    : { responsible_person: finding.responsiblePerson }),
                ...(finding.dueDate === null ? {} : { due_date: finding.dueDate }),
            })),
        },
        sent: sendable.map((answer) => ({ key: answer.key, revision: answer.revision })),
        sentFindings: sendableFindings.map((finding) => ({
            key: finding.key,
            revision: finding.revision,
        })),
    }
}

/**
 * Narrow what was sent down to what the server confirmed storing.
 *
 * The server answers with `question_code|pathogen` entries, which is the local
 * natural key with the assessment id removed. Anything absent stays dirty —
 * the server skips an answer naming a pathogen the payload never declared, and
 * that answer has to keep being offered rather than being marked clean because
 * the request as a whole succeeded.
 */
export function acknowledged(
    assessmentId: string,
    sent: AcknowledgedAnswer[],
    accepted: string[],
): AcknowledgedAnswer[] {
    const confirmed = new Set(accepted.map((entry) => `${assessmentId}|${entry}`))

    return sent.filter((entry) => confirmed.has(entry.key))
}
