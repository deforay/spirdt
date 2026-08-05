<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\RegistryService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The national list — places, facilities, testing sites.
 *
 * Reads are open to anyone who may manage or read the organisation, because
 * the dashboard filters by the same hierarchy. Writes are administrators only.
 * The split is in the routes rather than here, so it is visible where the
 * permission is granted.
 *
 * The whole geographic tree comes back flat in one response. A national list
 * is a few hundred rows, the client assembles it, and one cacheable payload
 * beats a request per level — which on a cascade is a request per keystroke.
 */
final class RegistryAction
{
    public function __construct(private readonly RegistryService $registry = new RegistryService())
    {
    }

    public function geoUnits(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['geo_units' => $this->registry->geoUnits()]);
    }

    public function createGeoUnit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle(
            $response,
            fn (array $body): array => ['geo_unit' => $this->registry->createGeoUnit($body)],
            $request,
            201,
        );
    }

    /** @param array<string,string> $args */
    public function updateGeoUnit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->handle(
            $response,
            fn (array $body): array => [
                'geo_unit' => $this->registry->updateGeoUnit((int) ($args['id'] ?? 0), $body),
            ],
            $request,
        );
    }

    public function facilities(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $geoUnit = $request->getQueryParams()['geo_unit'] ?? null;

        return $this->json($response, 200, [
            'facilities' => $this->registry->facilities(is_numeric($geoUnit) ? (int) $geoUnit : null),
        ]);
    }

    public function createFacility(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle(
            $response,
            fn (array $body): array => ['facility' => $this->registry->createFacility($body)],
            $request,
            201,
        );
    }

    /** @param array<string,string> $args */
    public function updateFacility(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->handle(
            $response,
            fn (array $body): array => [
                'facility' => $this->registry->updateFacility((string) ($args['id'] ?? ''), $body),
            ],
            $request,
        );
    }

    public function testingSites(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $facility = $request->getQueryParams()['facility'] ?? null;

        return $this->json($response, 200, [
            'testing_sites' => $this->registry->testingSites(
                is_string($facility) && $facility !== '' ? $facility : null,
            ),
        ]);
    }

    public function createTestingSite(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle(
            $response,
            fn (array $body): array => ['testing_site' => $this->registry->createTestingSite($body)],
            $request,
            201,
        );
    }

    /** @param array<string,string> $args */
    public function updateTestingSite(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->handle(
            $response,
            fn (array $body): array => [
                'testing_site' => $this->registry->updateTestingSite((string) ($args['id'] ?? ''), $body),
            ],
            $request,
        );
    }

    /**
     * Body in, one shape of error out.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $run
     */
    private function handle(
        ResponseInterface $response,
        callable $run,
        ServerRequestInterface $request,
        int $status = 200,
    ): ResponseInterface {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        try {
            return $this->json($response, $status, $run($body));
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
