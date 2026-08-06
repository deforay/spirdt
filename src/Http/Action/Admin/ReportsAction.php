<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

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
    public function __construct(private readonly ReportService $reports = new ReportService())
    {
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

        return $this->json($response, 200, $report);
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
