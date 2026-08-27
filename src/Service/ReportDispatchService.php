<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Exception\NoRecipient;
use App\Mail\Mailer;
use App\Mail\MailFailed;
use App\Mail\Message;
use App\Mail\SmtpMailer;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

/**
 * Getting the report to the people it is about.
 *
 * The laboratory that was audited has no account here and never will. Until
 * now the report reached them because somebody downloaded a file and attached
 * it to their own email — which works, and leaves no trace in this system that
 * it happened. A year later, "was the site ever told?" had no answer.
 *
 * So the send happens here, and the fact of it is written to the audit trail:
 * who sent it, which visit, which of the documents, the address it went to,
 * and — when the mail server refuses — that somebody tried and why it did not
 * work. An administrator asked why a laboratory never got their report needs
 * the attempt as much as the success.
 *
 * THE ADDRESS IS REMEMBERED. Asked for once, it goes onto the facility in the
 * registry, because the second send should not ask again and because a
 * contact somebody typed into a dialog and nowhere else is a contact that is
 * lost the moment they close the tab.
 */
final class ReportDispatchService
{
    /**
     * The covering note, in the four languages the app ships.
     *
     * Plain text and three sentences. It is read on whatever mail client a
     * district office has, the attachment is the document, and the message
     * only has to say what arrived and who sent it.
     *
     * @var array<string,array<string,string>>
     */
    private const STRINGS = [
        'en' => [
            'subject' => 'Audit report — {site}',
            'body'    => "{site} was assessed on {date}.\n\n"
                . "The report is attached as a PDF.\n\n"
                . 'Sent from {organisation}{sender}.',
            'by'      => ' by {name}',
        ],
        'fr' => [
            'subject' => "Rapport d'audit — {site}",
            'body'    => "{site} a été évalué le {date}.\n\n"
                . "Le rapport est joint au format PDF.\n\n"
                . 'Envoyé par {organisation}{sender}.',
            'by'      => ' de la part de {name}',
        ],
        'pt' => [
            'subject' => 'Relatório de auditoria — {site}',
            'body'    => "{site} foi avaliado em {date}.\n\n"
                . "O relatório segue em anexo em PDF.\n\n"
                . 'Enviado por {organisation}{sender}.',
            'by'      => ' da parte de {name}',
        ],
        'es' => [
            'subject' => 'Informe de auditoría — {site}',
            'body'    => "{site} fue evaluado el {date}.\n\n"
                . "El informe se adjunta en PDF.\n\n"
                . 'Enviado por {organisation}{sender}.',
            'by'      => ' de parte de {name}',
        ],
    ];

    public function __construct(
        private readonly ReportPdfService $pdfs = new ReportPdfService(),
        private readonly ReportService $reports = new ReportService(),
        private readonly ?Mailer $mailer = null,
    ) {
    }

    /**
     * Send one report, and write down that it went.
     *
     * `$email` is what somebody typed into the dialog. Absent it, the
     * facility's recorded contact is used — and when there is neither, this
     * refuses rather than guessing, because the one thing worse than not
     * sending a laboratory's report is sending it somewhere else.
     *
     * `$mayRemember` is whether the caller may write to the registry. Keeping
     * the address is a registry edit and `facilities` is shared across the
     * programme, so an address typed by somebody who may send but not correct
     * records would otherwise become the contact every other organisation
     * sharing that facility sends to.
     *
     * @throws InvalidArgumentException there is no such assessment here
     * @throws NoRecipient              there is no address to send to, or what
     *                                  was given is not one
     * @throws MailFailed               the message did not reach a server
     *
     * @return array{to:string,variant:string,photographs:bool,filename:string,remembered:bool}
     */
    public function send(
        string $assessmentId,
        string $locale = 'en',
        string $variant = ReportPdfService::FULL,
        bool $withPhotographs = false,
        ?string $email = null,
        bool $mayRemember = false,
    ): array {
        $locale = isset(self::STRINGS[$locale]) ? $locale : 'en';

        // Normalised HERE rather than left to the renderer, which quietly
        // treats anything it does not know as the full report. The trail has to
        // name the document that actually went, and a row saying 'action'
        // beside a full report sent is a row that answers the wrong question.
        $variant = $variant === ReportPdfService::ACTIONS
            ? ReportPdfService::ACTIONS
            : ReportPdfService::FULL;

        $withPhotographs = $withPhotographs && $variant === ReportPdfService::FULL;

        $report = $this->reports->report($assessmentId, $locale);

        $assessment = is_array($report['assessment'] ?? null) ? $report['assessment'] : [];
        $facilityId = (string) (($assessment['facility']['id'] ?? '') ?: '');

        $given = $email === null ? '' : trim($email);
        $recorded = $this->recordedEmail($facilityId);
        $to = $given === '' ? $recorded : $given;

        if ($to === '') {
            throw new NoRecipient('There is no email address for this facility.');
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new NoRecipient('That is not an email address.');
        }

        $file = $this->pdfs->render($assessmentId, $locale, $withPhotographs, $variant);

        $message = new Message(
            to: $to,
            subject: $this->wording('subject', $locale, $report),
            body: $this->wording('body', $locale, $report),
            attachment: $file['bytes'],
            filename: $file['filename'],
        );

        try {
            ($this->mailer ?? new SmtpMailer())->send($message);
        } catch (MailFailed $e) {
            // Recorded before it is rethrown. An attempt that failed is the
            // half of the history somebody chasing a missing report needs, and
            // it is the half that is easiest not to write down.
            AuditLog::record(AuditAction::REPORT_SEND_FAILED, 'assessment', $assessmentId, [
                'to'          => $to,
                'variant'     => $variant,
                'photographs' => $withPhotographs,
                'reason'      => mb_substr($e->getMessage(), 0, 300),
            ]);

            throw $e;
        }

        // BEFORE the registry is touched. The message has left the building,
        // and that is the fact the trail exists to hold — a database write that
        // deadlocks on the way to remembering an optional address must not be
        // able to lose it, or a retry sends a second copy against a history
        // that records neither.
        AuditLog::record(AuditAction::REPORT_SENT, 'assessment', $assessmentId, [
            'to'          => $to,
            // Which document, to the same detail the menu offers. The variant
            // alone does not separate a full report sent with its evidence
            // from one sent without, and the trail is the only record of which
            // one a laboratory actually received.
            'variant'     => $variant,
            'photographs' => $withPhotographs,
            'filename'    => $file['filename'],
            'bytes'       => strlen($file['bytes']),
        ]);

        // Only once it has actually gone, and only by somebody who may edit the
        // registry. An address remembered from a send that failed is an address
        // nobody has confirmed anybody reads.
        $remembered = $mayRemember
            && $given !== ''
            && $recorded === ''
            && $this->remember($facilityId, $to);

        return [
            'to'          => $to,
            'variant'     => $variant,
            'photographs' => $withPhotographs,
            'filename'    => $file['filename'],
            'remembered'  => $remembered,
        ];
    }

    /**
     * Where this facility's report goes, if anybody has said.
     *
     * The facility rather than the testing site, matching the registry: a
     * hospital has a laboratory manager, and its four benches do not have four
     * mailboxes.
     */
    public function recordedEmail(string $facilityId): string
    {
        if ($facilityId === '' || !BinaryUuid::isValid($facilityId)) {
            return '';
        }

        $facility = Facility::query()
            ->where('id', BinaryUuid::toBytes($facilityId))
            ->first(['contact_email']);

        return $facility === null ? '' : trim((string) ($facility->contact_email ?? ''));
    }

    /**
     * Keep the address that was typed, so the next send does not ask again.
     *
     * Only ever fills a blank. Overwriting a contact the registry already
     * holds because somebody sent one report elsewhere would let a typo in a
     * dialog quietly replace a national record.
     */
    private function remember(string $facilityId, string $email): bool
    {
        if ($facilityId === '' || !BinaryUuid::isValid($facilityId)) {
            return false;
        }

        $updated = Facility::query()
            ->where('id', BinaryUuid::toBytes($facilityId))
            ->whereNull('contact_email')
            ->update(['contact_email' => $email]);

        return $updated > 0;
    }

    /**
     * One line of the covering note, with the visit's own facts in it.
     *
     * @param array<string,mixed> $report
     */
    private function wording(string $key, string $locale, array $report): string
    {
        $assessment = is_array($report['assessment'] ?? null) ? $report['assessment'] : [];
        $site = is_array($assessment['site'] ?? null) ? $assessment['site'] : [];
        $facility = is_array($assessment['facility'] ?? null) ? $assessment['facility'] : [];

        $name = (string) ($site['name'] ?? '');

        if ($name === '') {
            $name = (string) ($facility['name'] ?? '');
        }

        return strtr(self::STRINGS[$locale][$key], [
            '{site}'         => $name,
            '{date}'         => (string) ($assessment['assessed_on'] ?? ''),
            '{organisation}' => $this->organisationName(),
            '{sender}'       => $this->senderClause($locale),
        ]);
    }

    /** Who pressed send, for a recipient who may want to reply to a person. */
    private function senderClause(string $locale): string
    {
        $userId = TenantContext::current()?->userId;

        if ($userId === null) {
            return '';
        }

        $user = User::query()->where('id', $userId)->first(['full_name']);
        $name = $user === null ? '' : trim((string) ($user->full_name ?? ''));

        return $name === '' ? '' : strtr(self::STRINGS[$locale]['by'], ['{name}' => $name]);
    }

    private function organisationName(): string
    {
        $organization = Organization::query()
            ->where('id', TenantContext::requireOrganizationId())
            ->first(['name']);

        return $organization === null ? '' : (string) $organization->name;
    }

    /**
     * Every send of one visit, newest first.
     *
     * Read straight off the audit trail rather than kept a second time in a
     * table of its own. The trail is the record — it is never pruned, it
     * already holds who and when and from where — and a second copy is a
     * second thing to keep in step with it.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $assessmentId): array
    {
        if (!BinaryUuid::isValid($assessmentId)) {
            return [];
        }

        $rows = Capsule::table('audit_log')
            // Joined on the organisation as well as the id. Every read in this
            // application is scoped, and a join that scopes only one side of
            // itself is a join that will one day put somebody else's name on
            // this organisation's history.
            ->leftJoin('users', function ($join): void {
                $join->on('users.id', '=', 'audit_log.actor_id')
                    ->on('users.organization_id', '=', 'audit_log.organization_id');
            })
            ->where('audit_log.organization_id', TenantContext::requireOrganizationId())
            ->where('audit_log.entity_type', 'assessment')
            ->where('audit_log.entity_id', BinaryUuid::toBytes($assessmentId))
            ->whereIn('audit_log.action', [AuditAction::REPORT_SENT, AuditAction::REPORT_SEND_FAILED])
            ->orderByDesc('audit_log.created_at')
            ->limit(20)
            ->get(['audit_log.action', 'audit_log.metadata', 'audit_log.created_at', 'users.full_name']);

        $history = [];

        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row->metadata ?? '{}'), true);

            if (!is_array($metadata)) {
                $metadata = [];
            }

            $history[] = [
                'sent'        => $row->action === AuditAction::REPORT_SENT,
                'to'          => (string) ($metadata['to'] ?? ''),
                'variant'     => (string) ($metadata['variant'] ?? ReportPdfService::FULL),
                'photographs' => ($metadata['photographs'] ?? false) === true,
                'reason'      => isset($metadata['reason']) ? (string) $metadata['reason'] : null,
                'by'          => $row->full_name === null ? null : (string) $row->full_name,
                'at'          => (string) $row->created_at,
            ];
        }

        return $history;
    }
}
