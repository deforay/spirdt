<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Auth\Permission;
use App\Exception\NoRecipient;
use App\Mail\MailFailed;
use App\Service\ReportDispatchService;
use App\Service\ReportPdfService;
use App\Service\ReportService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What was collected, read back.
 *
 * Both routes are reads and both are open to a viewer as well as an
 * administrator — a viewer is somebody whose whole job is this screen. Nothing
 * here writes, and nothing here takes an organisation from the request: the
 * scope comes from the token, so an id belonging to another organisation is
 * "no such assessment" rather than a permission error.
 */
final class ReportsAction
{
    public function __construct(
        private readonly ReportService $reports = new ReportService(),
        private readonly ReportPdfService $pdfs = new ReportPdfService(),
        private readonly ReportDispatchService $dispatch = new ReportDispatchService(),
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        return $this->json($response, 200, $this->reports->assessments(
            [
                'geo_unit_id'     => $this->optionalInt($query, 'geo_unit_id'),
                'facility_id'     => $this->optionalString($query, 'facility_id'),
                'testing_site_id' => $this->optionalString($query, 'testing_site_id'),
                'campaign_id'     => $this->optionalInt($query, 'campaign_id'),
                'status'          => $this->optionalString($query, 'status'),
                'from'            => $this->optionalString($query, 'from'),
                'to'              => $this->optionalString($query, 'to'),
                // Zero is a real level and the lowest one, so the check is for
                // the parameter being present rather than for it being truthy.
                'level'           => $this->optionalInt($query, 'level'),
                'search'          => $this->optionalString($query, 'search'),
            ],
            max(1, (int) ($query['page'] ?? 1)),
            (int) ($query['per_page'] ?? ReportService::PAGE_SIZE),
        ));
    }

    /** @param array<string,string> $args */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $query = $request->getQueryParams();

        try {
            $report = $this->reports->report(
                (string) ($args['id'] ?? ''),
                $this->optionalString($query, 'locale') ?? 'en',
            );
        } catch (InvalidArgumentException $e) {
            // 404, not 422. The id is well-formed and simply does not name
            // anything this organisation can see, and those two cases must not
            // be distinguishable from outside.
            return $this->json($response, 404, ['error' => ['message' => $e->getMessage()]]);
        }

        // Where it has already been, and where it would go by default. Both
        // belong with the report rather than behind a second request: the
        // screen shows them together, and a send history fetched separately is
        // a screen that renders once saying nothing was ever sent.
        //
        // Only for somebody who may send. This is a contact address, a list of
        // who mailed a laboratory's score where, and whatever a mail server
        // said about it — which belongs to the person deciding whether to send
        // it again, not to everybody who may read the report. Hiding the
        // dialog is not the same as not answering with the data behind it.
        $facility = is_array($report['assessment']['facility'] ?? null)
            ? $report['assessment']['facility']
            : [];

        $maySend = $this->holds($request, Permission::REPORTS_SEND);

        $report['sent'] = $maySend ? $this->dispatch->history((string) ($args['id'] ?? '')) : [];
        $report['recipient'] = $maySend
            ? $this->dispatch->recordedEmail((string) ($facility['id'] ?? ''))
            : '';

        return $this->json($response, 200, $report);
    }

    /**
     * The same report, as a file to keep.
     *
     * Photographs are asked for rather than assumed. Five per section at a
     * phone camera's resolution is a document too big to email, and the reader
     * who wants the evidence and the reader who wants the numbers are not
     * always the same person. Absent the parameter, they are included: the
     * complete record is the safer default for something that leaves the
     * system.
     *
     * The variant is the same distinction the download menu offers: the whole
     * record, or the site's details and the work it has to do.
     *
     * Rendered on the way out rather than stored. A report is a view of rows
     * that can still change — a finding closed, a signature added — and a file
     * cached at submission would be the wrong document the moment either
     * happened.
     *
     * @param array<string,string> $args
     */
    public function pdf(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $query = $request->getQueryParams();

        try {
            $file = $this->pdfs->render(
                (string) ($args['id'] ?? ''),
                $this->optionalString($query, 'locale') ?? 'en',
                ($query['photographs'] ?? '1') !== '0',
                // Anything but the short one is the whole record, so a
                // mistyped variant errs towards giving somebody more of the
                // document rather than less.
                $this->optionalString($query, 'variant') ?? ReportPdfService::FULL,
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 404, ['error' => ['message' => $e->getMessage()]]);
        }

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            // Attachment rather than inline: this is asked for from a list of
            // audits, and a PDF that replaces the page somebody was reading
            // costs them their place in it.
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $file['filename'] . '"',
            )
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Email the report to the site it is about.
     *
     * The address is the facility's recorded contact unless one is given, and
     * one given for a facility that has none is kept — the second send should
     * not have to ask again.
     *
     * Three answers, and the difference matters to whoever is looking at the
     * dialog. 404: no such assessment here. 422: nothing to send to, or what
     * was typed is not an address — both fixable by the person in front of the
     * screen. 502: the request was fine and the mail server was not, which is
     * an administrator's problem rather than theirs.
     *
     * @param array<string,string> $args
     */
    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            $body = [];
        }

        $email = $body['email'] ?? null;

        try {
            $sent = $this->dispatch->send(
                (string) ($args['id'] ?? ''),
                $this->optionalString($body, 'locale') ?? 'en',
                $this->optionalString($body, 'variant') ?? ReportPdfService::FULL,
                ($body['photographs'] ?? false) === true,
                is_string($email) ? $email : null,
                // Keeping the address is a registry edit, and `facilities` is
                // shared across the programme: an address remembered by
                // somebody who may not correct records would become the
                // contact every organisation sharing that facility sends to.
                $this->holds($request, Permission::REGISTRY_WRITE),
            );
        } catch (NoRecipient $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        } catch (InvalidArgumentException $e) {
            // The id names nothing this organisation may see. Same answer a
            // request for another organisation's assessment gets, on purpose.
            return $this->json($response, 404, ['error' => ['message' => $e->getMessage()]]);
        } catch (MailFailed $e) {
            return $this->json($response, 502, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, $sent);
    }

    /**
     * Whether this request's account holds a permission.
     *
     * Read off the request rather than the database: AuthMiddleware has
     * already resolved what the token's role carries, and asking again would
     * be a second answer that could differ from the one the route gates used.
     */
    private function holds(ServerRequestInterface $request, string $permission): bool
    {
        $held = $request->getAttribute('permissions');

        return is_array($held) && in_array($permission, $held, true);
    }

    /** @param array<string,mixed> $query */
    private function optionalInt(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;

        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /** @param array<string,mixed> $query */
    private function optionalString(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
