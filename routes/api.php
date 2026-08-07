<?php

declare(strict_types=1);

use App\Http\Action\Admin\AssignmentsAction;
use App\Http\Action\Admin\OrganizationsAction;
use App\Http\Action\Admin\RegistryAction;
use App\Http\Action\Admin\ReportsAction;
use App\Http\Action\Admin\UsersAction;
use App\Http\Action\AttachmentAction;
use App\Http\Action\Auth\ChangePasswordAction;
use App\Http\Action\Auth\LoginAction;
use App\Http\Action\Auth\LogoutAction;
use App\Http\Action\Auth\RefreshAction;
use App\Http\Action\SitesAction;
use App\Http\Action\SyncAction;
use App\Auth\Permission;
use App\Middleware\AuthMiddleware;
use App\Middleware\RequirePermissionMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Route registration entry point.
 *
 * Routes are grouped by the capability they require rather than by the entity
 * they touch, so the permission boundary is what the file's shape shows. Two
 * routes on the same noun sit in different groups when they need different
 * permissions — reading the registry and changing it are different jobs, and
 * putting them together would mean granting one to grant the other.
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
        // Inside AuthMiddleware, which is what resolves the permissions. A
        // viewer reads collected data and does not collect it, and a site_user
        // is staff at the place being assessed — neither is created holding
        // this, and neither has any business filing an assessment against a
        // site.
        ->add(new RequirePermissionMiddleware(Permission::ASSESSMENTS_SUBMIT))
        ->add(new AuthMiddleware());

    // Accounts. Kept apart from the registry below because they are a
    // different kind of trust: somebody who maintains a list of laboratories
    // is not automatically somebody who may create the accounts that read it.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/users', [UsersAction::class, 'index']);
        $group->post('/users', [UsersAction::class, 'create']);
        $group->patch('/users/{id}', [UsersAction::class, 'update']);
        $group->post('/users/{id}/password', [UsersAction::class, 'resetPassword']);
    })
        ->add(new RequirePermissionMiddleware(Permission::USERS_MANAGE))
        ->add(new AuthMiddleware());

    // The registry: places, facilities, the benches inside them.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->post('/geo-units', [RegistryAction::class, 'createGeoUnit']);
        $group->patch('/geo-units/{id}', [RegistryAction::class, 'updateGeoUnit']);
        $group->post('/facilities', [RegistryAction::class, 'createFacility']);
        $group->patch('/facilities/{id}', [RegistryAction::class, 'updateFacility']);
        // Folding a duplicate into the record that survives. Never deletes.
        $group->post('/facilities/{id}/merge', [RegistryAction::class, 'mergeFacility']);
        $group->post('/testing-sites', [RegistryAction::class, 'createTestingSite']);
        $group->patch('/testing-sites/{id}', [RegistryAction::class, 'updateTestingSite']);
    })
        ->add(new RequirePermissionMiddleware(Permission::REGISTRY_WRITE))
        ->add(new AuthMiddleware());

    // Who covers what. The organisation comes from the token.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->post('/assignments', [AssignmentsAction::class, 'create']);
        $group->delete('/assignments/{id}', [AssignmentsAction::class, 'delete']);
    })
        ->add(new RequirePermissionMiddleware(Permission::ASSIGNMENTS_WRITE))
        ->add(new AuthMiddleware());

    // The programme itself: which organisations audit under it. The only
    // surface where one tenant's administrator legitimately reaches another
    // tenant's row, and it stays bounded to their own programme by the token
    // rather than by a parameter.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/organizations', [OrganizationsAction::class, 'index']);
        $group->post('/organizations', [OrganizationsAction::class, 'create']);
        $group->patch('/organizations/{id}', [OrganizationsAction::class, 'update']);
    })
        ->add(new RequirePermissionMiddleware(Permission::ORGANIZATIONS_MANAGE))
        ->add(new AuthMiddleware());

    // Reading the registry and the plan. Separate from writing it, and held by
    // more people: the dashboard filters by the same hierarchy, and a filter
    // nobody can populate is not a filter.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/geo-units', [RegistryAction::class, 'geoUnits']);
        $group->get('/facilities', [RegistryAction::class, 'facilities']);
        $group->get('/facility-options', [RegistryAction::class, 'facilityOptions']);
        $group->get('/facilities/{id}', [RegistryAction::class, 'facility']);
        $group->get('/testing-sites/{id}', [RegistryAction::class, 'testingSite']);
        $group->get('/testing-sites', [RegistryAction::class, 'testingSites']);
        $group->get('/assignments', [AssignmentsAction::class, 'index']);
    })
        ->add(new RequirePermissionMiddleware(Permission::REGISTRY_READ))
        ->add(new AuthMiddleware());

    // What was collected. Apart from the registry because the two answer
    // different questions: one is a list of laboratories, the other is how each
    // of them is performing, and somebody may need the first without the
    // second.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/reports/assessments', [ReportsAction::class, 'index']);
        $group->get('/reports/assessments/{id}', [ReportsAction::class, 'show']);
    })
        ->add(new RequirePermissionMiddleware(Permission::REPORTS_READ))
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
