<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\DashboardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The country at a glance.
 *
 * Behind Permission::REPORTS_READ rather than a permission of its own. This
 * screen shows the same collected data the reports screen does, aggregated —
 * a separate key would let an organisation grant the summary while withholding
 * the detail, which is a distinction nobody has asked for and which the numbers
 * would not survive anyway.
 *
 * One request for the whole screen. Six panels reading six endpoints would be
 * six round trips before anything renders, and they are all derived from the
 * same set of rows.
 */
final class DashboardAction
{
    public function __construct(private readonly DashboardService $dashboard = new DashboardService())
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $locale = (string) ($request->getQueryParams()['locale'] ?? 'en');

        $response->getBody()->write((string) json_encode($this->dashboard->summary($locale)));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
