<?php

declare(strict_types=1);

use App\Http\Action\Admin\AssignmentsAction;
use App\Http\Action\Admin\OrganizationsAction;
use App\Http\Action\Admin\RegistryAction;
use App\Http\Action\Admin\UsersAction;
use App\Http\Action\AttachmentAction;
use App\Http\Action\Auth\ChangePasswordAction;
use App\Http\Action\Auth\LoginAction;
use App\Http\Action\Auth\LogoutAction;
use App\Http\Action\Auth\RefreshAction;
use App\Http\Action\SitesAction;
use App\Http\Action\SyncAction;
use App\Middleware\AuthMiddleware;
use App\Middleware\RequireRoleMiddleware;
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

    // Authenticated, but exempt from the must-change-password gate — it is the
    // route that clears it, so gating it would leave the account with no way
    // out. Every other authenticated route refuses until this has been used.
    $app->group('/auth', function (RouteCollectorProxy $group): void {
        $group->post('/password', ChangePasswordAction::class);
    })->add(new AuthMiddleware(requireUsablePassword: false));

    $app->group('/sync', function (RouteCollectorProxy $group): void {
        // Idempotent. A device that cannot tell a failed request from a lost
        // response will send this again, and must not create a second visit.
        $group->post('/assessments', SyncAction::class);

        // Signatures and photographs, on their own channel so an upload that
        // will not complete cannot hold up the assessment it belongs to.
        // Idempotent on the checksum of what arrives.
        $group->post('/attachments', AttachmentAction::class);
    })
        // Inside AuthMiddleware, which is what establishes the role. A viewer
        // reads collected data and does not collect it, and a site_user is
        // staff at the place being assessed — neither has any business filing
        // an assessment against a site.
        ->add(new RequireRoleMiddleware('assessor', 'admin', 'superadmin'))
        ->add(new AuthMiddleware());

    // Management. Everything here is behind a role gate as well as
    // authentication, because these routes are the difference between reading
    // an organisation's data and running it.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/users', [UsersAction::class, 'index']);
        $group->post('/users', [UsersAction::class, 'create']);
        $group->patch('/users/{id}', [UsersAction::class, 'update']);
        $group->post('/users/{id}/password', [UsersAction::class, 'resetPassword']);

        // The registry: places, facilities, the benches inside them.
        $group->post('/geo-units', [RegistryAction::class, 'createGeoUnit']);
        $group->patch('/geo-units/{id}', [RegistryAction::class, 'updateGeoUnit']);
        $group->post('/facilities', [RegistryAction::class, 'createFacility']);
        $group->patch('/facilities/{id}', [RegistryAction::class, 'updateFacility']);
        $group->post('/testing-sites', [RegistryAction::class, 'createTestingSite']);
        $group->patch('/testing-sites/{id}', [RegistryAction::class, 'updateTestingSite']);

        // Who covers what. The organisation comes from the token.
        $group->post('/assignments', [AssignmentsAction::class, 'create']);
        $group->delete('/assignments/{id}', [AssignmentsAction::class, 'delete']);
    })
        ->add(new RequireRoleMiddleware('admin', 'superadmin'))
        ->add(new AuthMiddleware());

    // The programme itself: which organisations audit under it. The only
    // surface where one tenant's administrator legitimately reaches another
    // tenant's row, so it is superadmin alone and bounded to their own
    // programme by the token rather than by a parameter.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/organizations', [OrganizationsAction::class, 'index']);
        $group->post('/organizations', [OrganizationsAction::class, 'create']);
        $group->patch('/organizations/{id}', [OrganizationsAction::class, 'update']);
    })
        ->add(new RequireRoleMiddleware('superadmin'))
        ->add(new AuthMiddleware());

    // Reading the registry and the plan. A viewer is included: the dashboard
    // filters by the same hierarchy, and a filter nobody can populate is not a
    // filter. Writing stays in the administrators-only group above.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/geo-units', [RegistryAction::class, 'geoUnits']);
        $group->get('/facilities', [RegistryAction::class, 'facilities']);
        $group->get('/testing-sites', [RegistryAction::class, 'testingSites']);
        $group->get('/assignments', [AssignmentsAction::class, 'index']);
    })
        ->add(new RequireRoleMiddleware('admin', 'superadmin', 'viewer'))
        ->add(new AuthMiddleware());

    // Reference data the device caches to work offline.
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/sites', SitesAction::class);

        // Served by the application rather than the web server: these files sit
        // outside the document root, and the organisation scope is the only
        // thing keeping one tenant's signatures away from another's.
        $group->get('/attachments/{id}', [AttachmentAction::class, 'show']);
    })->add(new AuthMiddleware());
};
