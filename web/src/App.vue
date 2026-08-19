<script setup lang="ts">
import { computed, watch } from 'vue'

import { refresh } from '@/api/client'
import { permissionsUnknown } from '@/auth/permissions'
import { session } from '@/auth/session'
import ChangePassword from '@/components/ChangePassword.vue'
import { applyDefaultLocale } from '@/i18n'
import { router } from '@/router'

/**
 * The shell.
 *
 * Two states, and the router owns the second one:
 *
 *   Signed in, password set  → replace it, because until then the server
 *   by somebody else            refuses every other request
 *   Otherwise                → whatever the route resolved to, which for a
 *                               visitor with no session is /login
 *
 * The password change sits ABOVE the router rather than being a route of its
 * own. It is not a place you navigate to: it interrupts a session that already
 * exists, there is nowhere else to be while it is true, and making it a route
 * would mean every other route needing a guard against it.
 *
 * SIGNING IN USED TO SIT HERE TOO, and the cost of that was the URL. The form
 * was painted over whatever route had already resolved underneath it, so the
 * address bar described a screen nobody was looking at — and for the visitor
 * who just opens the site, that address was /no-access, because "/" asks which
 * of nine screens this account can open and an account that does not exist yet
 * can open none of them. It is a route now; the router turns everybody else
 * away, in one place, in a way that can be read.
 */

/**
 * Repair a session saved before permissions existed.
 *
 * Every session open when that shipped carries no permission list, and the app
 * reads a missing list as holding nothing — so a superadmin holding all nine
 * landed on the no-access screen. The account was never the problem; the copy
 * of it in localStorage was.
 *
 * Refreshing rebuilds the session from the server, which now returns the list.
 * Done here, before any route decides anything, because the alternative is
 * telling everybody with an open session to sign out and in again — which
 * works, and which nobody should have to be told.
 *
 * Failure is silent on purpose. A refresh that cannot reach the server leaves
 * the session exactly as it was, and an assessor with unsynced work on a
 * device with no signal must not be signed out by a repair.
 */
if (permissionsUnknown()) {
    void refresh().then((ok) => {
        if (ok) {
            void router.replace({ name: 'home' })
        }
    })
}

/**
 * Start in the organisation's language on a device that has never been
 * switched.
 *
 * Watched rather than read once, because the session arrives after this
 * component does — at sign-in, and again at every refresh. applyDefaultLocale
 * ignores it whenever the device has a choice of its own, so a tablet somebody
 * switched to French stays French.
 */
watch(
    () => session.value?.instance?.locale,
    (code) => applyDefaultLocale(code),
    { immediate: true },
)

/**
 * Signed in, but the server will refuse everything except changing the
 * password. Shown instead of the app rather than as a prompt: a dismissible
 * one produces a screen where nothing works and nothing explains why.
 */
const mustChangePassword = computed(() => session.value?.user.mustChangePassword === true)

/**
 * Re-run the route decision now that the account can actually be used.
 *
 * The screen this interrupted was resolved for a session the server was
 * refusing every request from, so it is not necessarily the screen to go back
 * to. "/" asks the question again from scratch.
 */
function onReady(): void {
    void router.replace({ name: 'home' })
}

/**
 * Follow a session that has ended.
 *
 * Sign-out clears the session and so does a refresh token the server rejects,
 * and neither of them navigates — they had no need to when the sign-in form was
 * an overlay that simply appeared. Now that it is a route, nothing moves unless
 * something moves it, and without this the app sits on a management screen
 * whose every request comes back 401.
 *
 * Watched rather than pushed from the three places that sign out, because the
 * fourth is api/client.ts giving up on a refresh in the background, and a rule
 * that has to be remembered at each call site is a rule that will be missed at
 * the next one.
 */
watch(session, (current) => {
    if (current === null) {
        void router.replace({ name: 'login' })
    }
})
</script>

<template>
    <ChangePassword v-if="mustChangePassword" @changed="onReady" />

    <RouterView v-else />
</template>
