<?php

declare(strict_types=1);

use App\Http\Action\Auth\LoginAction;
use App\Http\Action\Auth\LogoutAction;
use App\Http\Action\Auth\RefreshAction;
use App\Http\Action\SitesAction;
use App\Http\Action\SyncAction;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Route registration entry point.
 *
 * Routes are split by audience under routes/api/ as they are added —
 * assessor sync, admin, platform. Keeping the split by WHO CALLS IT (rather
 * than by entity) makes the permission boundary visible in the file tree,
 * and gives the OpenAPI generator a natural grouping.
 *
 * Everything touching tenant data sits behind AuthMiddleware, the only place
 * an organisation is established for a request. A route added outside it
 * cannot quietly read across tenants — the model scope throws rather than
 * returning every organisation's rows.
 */
return static function (App $app): void {
    $app->get('/health', function ($request, $response) {
        $payload = [
            'status'  => 'ok',
            'app'     => env('APP_NAME', 'SPI-RDT'),
            'version' => trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')),
            'time'    => gmdate('c'),
        ];

        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    });

    // Deliberately outside AuthMiddleware: these are what produce the token it
    // checks. They establish no tenant, so anything they touch goes through
    // acrossOrganizations() rather than a scope that is not armed here.
    $app->group('/auth', function (RouteCollectorProxy $group): void {
        $group->post('/login', LoginAction::class);
        $group->post('/refresh', RefreshAction::class);
        $group->post('/logout', LogoutAction::class);
    });

    $app->group('/sync', function (RouteCollectorProxy $group): void {
        // Idempotent. A device that cannot tell a failed request from a lost
        // response will send this again, and must not create a second visit.
        $group->post('/assessments', SyncAction::class);
    })->add(new AuthMiddleware());

    // Reference data the device caches to work offline.
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/sites', SitesAction::class);
    })->add(new AuthMiddleware());
};
