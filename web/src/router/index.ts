import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { session } from '@/auth/session'

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
 * RequireRoleMiddleware — a guard in a bundle the user has already downloaded
 * is a signpost, not a lock.
 */

/** Who may collect audits. Mirrors the role list on the /sync routes. */
const ASSESSING = ['assessor', 'admin', 'superadmin']

/** Who may read and run the organisation. */
const MANAGING = ['admin', 'superadmin', 'viewer']

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        // Nothing of its own: it decides where this person belongs and sends
        // them there, so a shared bookmark works for everybody.
        redirect: () => (canManage() ? { name: 'admin-users' } : { name: 'assess' }),
    },
    {
        path: '/assess',
        name: 'assess',
        component: () => import('@/views/AssessView.vue'),
        meta: { roles: ASSESSING },
    },
    {
        path: '/admin/users',
        name: 'admin-users',
        component: () => import('@/views/admin/UsersView.vue'),
        // A viewer cannot administer people; the API refuses them too. Kept in
        // MANAGING rather than narrowed here so there is one list of roles
        // that reach management at all, and the API decides the rest.
        meta: { roles: MANAGING },
    },
    {
        path: '/admin/places',
        name: 'admin-places',
        component: () => import('@/views/admin/PlacesView.vue'),
        meta: { roles: MANAGING },
    },
    {
        path: '/admin/facilities',
        name: 'admin-facilities',
        component: () => import('@/views/admin/FacilitiesView.vue'),
        meta: { roles: MANAGING },
    },
    {
        path: '/admin/facilities/new',
        name: 'admin-facility-new',
        component: () => import('@/views/admin/FacilityFormView.vue'),
        meta: { roles: MANAGING },
    },
    {
        path: '/admin/facilities/:id',
        name: 'admin-facility',
        component: () => import('@/views/admin/FacilityFormView.vue'),
        meta: { roles: MANAGING },
    },
    {
        path: '/admin/sites',
        name: 'admin-sites',
        component: () => import('@/views/admin/SitesView.vue'),
        meta: { roles: MANAGING },
    },
    {
        path: '/admin/organizations',
        name: 'admin-organizations',
        component: () => import('@/views/admin/OrganizationsView.vue'),
        // Superadmin only, and the API enforces it again.
        meta: { roles: ['superadmin'] },
    },
    {
        path: '/admin/assignments',
        name: 'admin-assignments',
        component: () => import('@/views/admin/AssignmentsView.vue'),
        meta: { roles: MANAGING },
    },
]

export const router = createRouter({
    history: createWebHistory(),
    routes,
})

function role(): string {
    return session.value?.user.role ?? ''
}

function canManage(): boolean {
    return MANAGING.includes(role())
}

router.beforeEach((to) => {
    // Signed out, and not signed in yet: App.vue shows the sign-in screen over
    // the top of whatever route this is, so there is nothing to redirect to.
    if (session.value === null) {
        return true
    }

    const allowed = to.meta.roles

    if (Array.isArray(allowed) && !allowed.includes(role())) {
        // Somewhere they can actually go, rather than a dead end. A viewer who
        // follows a link to the checklist lands on the dashboard instead of an
        // error they cannot act on.
        return { name: canManage() ? 'admin-users' : 'assess' }
    }

    return true
})
