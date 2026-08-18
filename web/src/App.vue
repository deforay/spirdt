<script setup lang="ts">
import { computed, watch } from 'vue'

import { refresh } from '@/api/client'
import { permissionsUnknown } from '@/auth/permissions'
import { session } from '@/auth/session'
import ChangePassword from '@/components/ChangePassword.vue'
import SignIn from '@/components/SignIn.vue'
import { applyDefaultLocale } from '@/i18n'
import { router } from '@/router'

/**
 * The shell.
 *
 * Three states, in order, and only the last one has routes in it:
 *
 *   Not signed in            → sign in
 *   Signed in, password set  → replace it, because until then the server
 *   by somebody else            refuses every other request
 *   Signed in properly       → the best screen this account can open
 *
 * The first two sit ABOVE the router rather than being routes of their own.
 * They are not places you navigate to — there is nowhere else to be while
 * either is true, and making them routes would mean every other route needing
 * a guard against them.
 */

const signedIn = computed(() => session.value !== null)

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
 * Re-run the route decision now that the permissions are known.
 *
 * Sign-in happens outside the router, so nothing has yet chosen between the
 * assessor and management halves — and `/` resolved before the session existed
 * would have sent an administrator to the checklist.
 */
function onReady(): void {
    void router.replace({ name: 'home' })
}
</script>

<template>
    <SignIn v-if="!signedIn" @signed-in="onReady" />

    <ChangePassword v-else-if="mustChangePassword" @changed="onReady" />

    <RouterView v-else />
</template>
