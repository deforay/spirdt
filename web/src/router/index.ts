import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { can, landing, PERMISSION, type PermissionKey } from '@/auth/permissions'
import { session } from '@/auth/session'
import { settleFlash } from '@/composables/useFlash'
import LoginView from '@/views/LoginView.vue'

/**
 * One application, two audiences, separated by role.
 *
 * Assessors collect audits on a phone with no signal. Management reads what
 * was collected, on a desktop. They share a login, a deployment and an API,
 * and they are told apart here.
 *
 * THE MANAGEMENT ROUTES ARE LAZY. Every one of them is a dynamic import, so an
 * assessor never downloads a screen they cannot open — which is the whole
 * reason one application is affordable. Tables and charts are heavy, and the
 * offline half has to arrive over the connection it exists to cope with.
 *
 * These guards are for getting people to the right place, NOT for security.
 * Anything a route protects, the API protects again with
 * RequirePermissionMiddleware — a guard in a bundle the user has already
 * downloaded is a signpost, not a lock.
 *
 * Each route names the CAPABILITY it needs rather than the roles that happen to
 * have it. An organisation that grants reports to a role this file has never
 * heard of gets a working screen, not a redirect.
 */

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        // Nothing of its own: it decides where this person belongs and sends
        // them there, so a shared bookmark works for everybody.
        //
        // WHETHER ANYBODY IS SIGNED IN IS ASKED FIRST, and that is not a
        // special case so much as the absence of the thing landing() reads. A
        // visitor with no session holds no permissions, so landing() walked all
        // nine and truthfully answered 'no-access' — which is how the front
        // page of the application came to announce itself as a dead end to
        // everybody who had not signed in yet. There is nothing to decide about
        // an account until there is an account.
        redirect: () => ({ name: session.value === null ? 'login' : landing() }),
    },
    {
        // The one screen that works without a session, and therefore the only
        // place the guard below can send somebody who has none.
        //
        // It has an address of its own because the alternative was that it did
        // not: the form used to be painted over whatever route had resolved
        // underneath it, which left the URL describing a screen nobody was
        // looking at. A page has to be able to say what it is.
        path: '/login',
        name: 'login',
        // NOT lazy, unlike everything else here. The rule above is that a
        // screen is downloaded by the people who can open it, and this is the
        // screen every single visitor opens first — deferring it buys nothing
        // and costs a second round trip before anybody can see a password
        // field, on the one screen that has to work over the connection the
        // rest of the app exists to do without.
        component: LoginView,
    },
    {
        // No permission: everybody who can sign in has an account, and the
        // password on it is the one thing they can always change.
        path: '/account',
        name: 'account',
        component: () => import('@/views/AccountView.vue'),
    },
    {
        path: '/assess',
        name: 'assess',
        component: () => import('@/views/AssessView.vue'),
        meta: { permission: PERMISSION.assessmentsSubmit },
    },
    {
        // A short path rather than /admin/dashboard, because it is the address
        // somebody types and the one that goes in a bookmark. The API for it
        // sits under /admin like the rest.
        path: '/dashboard',
        name: 'admin-dashboard',
        component: () => import('@/views/admin/DashboardView.vue'),
        meta: { permission: PERMISSION.reportsRead },
    },
    {
        path: '/admin/users',
        name: 'admin-users',
        component: () => import('@/views/admin/UsersView.vue'),
        meta: { permission: PERMISSION.usersManage },
    },
    {
        // The first screen that shows collected data rather than the things
        // data is collected about, and what a viewer signs in for — so it is
        // where management lands.
        path: '/admin/reports',
        name: 'admin-reports',
        component: () => import('@/views/admin/ReportsView.vue'),
        meta: { permission: PERMISSION.reportsRead },
    },
    {
        path: '/admin/reports/:id',
        name: 'admin-report',
        component: () => import('@/views/admin/ReportView.vue'),
        meta: { permission: PERMISSION.reportsRead },
    },
    {
        path: '/admin/places',
        name: 'admin-places',
        component: () => import('@/views/admin/PlacesView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/facilities',
        name: 'admin-facilities',
        component: () => import('@/views/admin/FacilitiesView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/facilities/new',
        name: 'admin-facility-new',
        component: () => import('@/views/admin/FacilityFormView.vue'),
        meta: { permission: PERMISSION.registryWrite },
    },
    {
        path: '/admin/facilities/:id',
        name: 'admin-facility',
        component: () => import('@/views/admin/FacilityFormView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/sites',
        name: 'admin-sites',
        component: () => import('@/views/admin/SitesView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/audit',
        name: 'admin-audit',
        component: () => import('@/views/admin/AuditView.vue'),
        meta: { permission: PERMISSION.auditRead },
    },
    {
        // Beside the audit trail and sharing its permission: the evidence and
        // the diagnostic of the same activity. /admin/requests rather than
        // /admin/logs, because the file log is a different thing and will want
        // that name if it ever gets a screen.
        path: '/admin/requests',
        name: 'admin-requests',
        component: () => import('@/views/admin/RequestLogView.vue'),
        meta: { permission: PERMISSION.auditRead },
    },
    {
        path: '/admin/roles',
        name: 'admin-roles',
        component: () => import('@/views/admin/RolesView.vue'),
        meta: { permission: PERMISSION.rolesManage },
    },
    {
        path: '/admin/organizations',
        name: 'admin-organizations',
        component: () => import('@/views/admin/OrganizationsView.vue'),
        // Superadmin only, and the API enforces it again.
        meta: { permission: PERMISSION.organizationsManage },
    },
    {
        path: '/admin/settings',
        name: 'admin-settings',
        component: () => import('@/views/admin/SettingsView.vue'),
        meta: { permission: PERMISSION.settingsManage },
    },
    {
        path: '/admin/assignments',
        name: 'admin-assignments',
        component: () => import('@/views/admin/AssignmentsView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/places/new',
        name: 'admin-place-new',
        component: () => import('@/views/admin/PlaceFormView.vue'),
        meta: { permission: PERMISSION.registryWrite },
    },
    {
        path: '/admin/places/:id',
        name: 'admin-place',
        component: () => import('@/views/admin/PlaceFormView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/sites/new',
        name: 'admin-site-new',
        component: () => import('@/views/admin/SiteFormView.vue'),
        meta: { permission: PERMISSION.registryWrite },
    },
    {
        path: '/admin/sites/:id',
        name: 'admin-site',
        component: () => import('@/views/admin/SiteFormView.vue'),
        meta: { permission: PERMISSION.registryRead },
    },
    {
        path: '/admin/users/new',
        name: 'admin-user-new',
        component: () => import('@/views/admin/UserFormView.vue'),
        meta: { permission: PERMISSION.usersManage },
    },
    {
        path: '/admin/users/:id',
        name: 'admin-user',
        component: () => import('@/views/admin/UserFormView.vue'),
        meta: { permission: PERMISSION.usersManage },
    },
    {
        path: '/admin/organizations/new',
        name: 'admin-organization-new',
        component: () => import('@/views/admin/OrganizationFormView.vue'),
        meta: { permission: PERMISSION.organizationsManage },
    },
    {
        path: '/admin/organizations/:id',
        name: 'admin-organization',
        component: () => import('@/views/admin/OrganizationFormView.vue'),
        meta: { permission: PERMISSION.organizationsManage },
    },

    // Deliberately carries no permission of its own. It is where an account
    // that can open nothing else is sent, so a REQUIREMENT on it would send
    // that account somewhere that sends it back here. It gets a guard of the
    // opposite kind below instead — see the beforeEach.
    {
        path: '/no-access',
        name: 'no-access',
        component: () => import('@/views/NoAccessView.vue'),
    },

    // /admin is not a screen — the management pages all live beneath it — but
    // it is the address somebody types. Without this it matched nothing and
    // rendered nothing: a blank page with no navigation on it, and no way back
    // except knowing another URL.
    {
        path: '/admin',
        // The same decision "/" makes — made by pointing at it rather than by
        // repeating it. Fixed at reports, it sent an account without
        // reports.read to a screen that immediately bounced it; asking
        // landing() here in its own right meant this path went on sending
        // signed-out visitors to the dead end after "/" had stopped.
        redirect: { name: 'home' },
    },

    /**
     * Anything else.
     *
     * A single-page app answers every path with itself, so a mistyped or
     * retired URL reaches the router rather than the web server, and a router
     * with no match for it renders an empty document. Nothing on that page
     * says what happened and nothing on it leads anywhere.
     *
     * Sent home rather than shown an error, because home already knows where
     * this person belongs: an assessor lands on their site list and a manager
     * on the reports. A dead end is worse than a redirect that is occasionally
     * more decisive than the reader expected.
     */
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'home' },
    },
]

export const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach((to) => {
    // Signed out. There is exactly one screen that works, and every other
    // address is something to come back to rather than something to render now.
    //
    // The path travels in the query so the deep link survives the detour: an
    // assessor sent a link to a particular site opens that site after signing
    // in, rather than the dashboard and an explanation. LoginView decides
    // whether to honour it — see the note there on why it is not trusted.
    if (session.value === null) {
        return to.name === 'login' ? true : { name: 'login', query: { next: to.fullPath } }
    }

    // Signed in and asking for the front door anyway: a bookmark, a back
    // button, or a browser restoring the tab it was closed on. Showing the form
    // to somebody who already has a session offers them a second one.
    if (to.name === 'login') {
        return { name: landing() }
    }

    // THE DEAD END HAS TO BE LEAVEABLE. /no-access asks for no permission, so
    // without this the guard has nothing to check and simply renders it — for
    // ever, and for anybody. An account that reached it once while its
    // permissions were unknown, or that was granted access afterwards, or that
    // merely has the URL in its history, stayed on a screen saying it could do
    // nothing while holding every permission there is. Reloading did not help,
    // because the reason it was sent there is not re-examined by arriving.
    //
    // Checked against landing() rather than against a permission, so the two
    // cannot disagree: landing() returns 'no-access' only when there is
    // genuinely nowhere to go, and that is exactly when this must not fire.
    if (to.name === 'no-access') {
        const home = landing()

        return home === 'no-access' ? true : { name: home }
    }

    const required = to.meta.permission as PermissionKey | undefined

    if (required !== undefined && !can(required)) {
        // Somewhere they can actually go, rather than a dead end. Reports
        // first because it is what most management accounts sign in for, and
        // the registry after it, because an account may hold one without the
        // other now that the two are separate permissions.
        return { name: landing() }
    }

    return true
})

/**
 * A confirmation set on a form is meant for the screen the form sends you to,
 * and for that screen only. It rides one navigation and is cleared by the
 * next.
 */
router.afterEach(() => {
    settleFlash()

    // Arriving anywhere at all means the bundle is current, so whatever the
    // reload below was guarding against is over.
    forgetReload()
})

/**
 * A tab that was open across a deploy.
 *
 * EVERY SCREEN BUT THE SIGN-IN FORM IS A DYNAMIC IMPORT, and the names of
 * those files are fingerprints of their contents — so a build replaces all of
 * them. The import map naming the old ones is baked into the bundle this tab
 * is already running, which means that from the moment a build lands, every
 * navigation this tab has not already made asks for a file that is gone.
 *
 * vue-router reports that by rejecting the navigation and nothing else. The
 * caller is usually in no position to show it and mostly does not try — the
 * sign-in view discards it with a `void` — so the screen simply stays where it
 * was. The symptom is a control that does nothing whatsoever: no error, no
 * spinner, no address change. It was found on the sign-in button and it was
 * never about signing in; every management link on a tab left open since the
 * morning fails the same silent way.
 *
 * Reloading is the repair, and it works because index.html is the one file
 * whose name never changes. It is also what somebody does by hand when a click
 * does nothing — the difference is that they land back where they started and
 * this lands them where they were going.
 *
 * ONCE PER DESTINATION. A chunk can be missing for reasons a reload cannot
 * mend, and a repair that retries itself is a tab that reloads for ever.
 */
const RELOAD_KEY = 'spirdt.reload-for'

router.onError((error, to) => {
    if (!isMissingChunk(error)) {
        return
    }

    try {
        if (sessionStorage.getItem(RELOAD_KEY) === to.fullPath) {
            return
        }

        sessionStorage.setItem(RELOAD_KEY, to.fullPath)
    } catch {
        // No sessionStorage to mark it in. Reloading once is still the right
        // move and still what a person would do unaided; what is lost is the
        // protection against a loop, which needs a chunk to be permanently
        // missing before it can happen at all.
    }

    window.location.assign(to.fullPath)
})

function forgetReload(): void {
    try {
        sessionStorage.removeItem(RELOAD_KEY)
    } catch {
        // Nothing was written, so there is nothing to clear.
    }
}

/**
 * Whether this is a chunk that will not load, as opposed to a real fault in a
 * screen that loaded perfectly well.
 *
 * Matched on the message because that is all there is: no browser gives this a
 * code, and each words it differently. Chrome and Firefox both say
 * "dynamically imported module"; Safari says the module script failed to
 * import; Vite raises its own line when a stylesheet cannot be preloaded.
 *
 * The MIME line is the one worth explaining. A server that answers the SPA
 * fallback for a missing asset hands back index.html with a 200, and the
 * browser rejects the HTML rather than the request — this app's own .htaccess
 * did exactly that until it was told to leave /assets alone. It is kept
 * because not every deployment is behind that file.
 */
function isMissingChunk(error: unknown): boolean {
    if (!(error instanceof Error)) {
        return false
    }

    const message = error.message.toLowerCase()

    return (
        message.includes('dynamically imported module') ||
        message.includes('importing a module script failed') ||
        message.includes('expected a javascript module script') ||
        message.includes('unable to preload')
    )
}
