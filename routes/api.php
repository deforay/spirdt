<?php

declare(strict_types=1);

use App\Http\Action\Admin\AssignmentsAction;
use App\Http\Action\Admin\AuditAction;
use App\Http\Action\Admin\DashboardAction;
use App\Http\Action\Admin\OrganizationsAction;
use App\Http\Action\Admin\RegistryAction;
use App\Http\Action\Admin\ReportsAction;
use App\Http\Action\Admin\RequestLogAction;
use App\Http\Action\Admin\RolesAction;
use App\Http\Action\Admin\SettingsAction;
use App\Http\Action\Admin\SitePhotoAction;
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

        // Taking one back. Photographs are deleted by the assessor who took
        // them; signatures are replaced by signing again and this refuses
        // them, which is why the route names no kind.
        $group->delete('/attachments/{id}', [AttachmentAction::class, 'destroy']);
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

    // Who did what, and what the server was asked. Read-only, both of them:
    // the audit rows are written by the services that perform the actions from
    // an actor taken off a verified token, and the request rows by the
    // middleware from what actually arrived.
    //
    // One permission for the pair. They are the evidence and the diagnostic of
    // the same activity, read by the same person for the same reason, and
    // splitting them would mean an installation could grant the record of what
    // was done while withholding the record of what was asked — which is the
    // half that says why.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/audit', [AuditAction::class, 'index']);
        $group->get('/requests', [RequestLogAction::class, 'index']);
    })
        ->add(new RequirePermissionMiddleware(Permission::AUDIT_READ))
        ->add(new AuthMiddleware());

    // What a role may do, as opposed to who holds it. A separate permission
    // because this is the one screen that can be used to obtain the others.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/roles', [RolesAction::class, 'index']);
        $group->patch('/roles/{id}/permissions', [RolesAction::class, 'updatePermissions']);
    })
        ->add(new RequirePermissionMiddleware(Permission::ROLES_MANAGE))
        ->add(new AuthMiddleware());

    // The installation itself: its name, who to contact about it, where its
    // mail goes out, and the localisation of the caller's own organisation.
    // Instance settings are shared rather than tenant-scoped, which is why this
    // has its own permission and why that permission is seeded to superadmin.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/settings', [SettingsAction::class, 'index']);
        $group->patch('/settings', [SettingsAction::class, 'update']);
    })
        ->add(new RequirePermissionMiddleware(Permission::SETTINGS_MANAGE))
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
        // What the bench looks like. Multipart rather than JSON, and its own
        // route rather than a field on the site, because an image is bytes and
        // the rest of a site record is a form.
        $group->post('/testing-sites/{id}/photo', [SitePhotoAction::class, 'store']);
        $group->delete('/testing-sites/{id}/photo', [SitePhotoAction::class, 'destroy']);
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
        // Reading the bench is reading the registry. Changing what it looks
        // like is in the write group above, with the rest of the corrections.
        $group->get('/testing-sites/{id}/photo', [SitePhotoAction::class, 'show']);
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
        // The whole summary in one request. Six panels reading six endpoints
        // would be six round trips before anything renders.
        $group->get('/dashboard', DashboardAction::class);

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
