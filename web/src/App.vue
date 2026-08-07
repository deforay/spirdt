<script setup lang="ts">
import { computed } from 'vue'

import { session } from '@/auth/session'
import ChangePassword from '@/components/ChangePassword.vue'
import SignIn from '@/components/SignIn.vue'
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
