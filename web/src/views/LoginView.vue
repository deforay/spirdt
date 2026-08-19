<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'

import { landing } from '@/auth/permissions'
import { intendedPath } from '@/auth/redirect'
import SignIn from '@/components/SignIn.vue'

/**
 * The front door, and the only screen a signed-out visitor can reach.
 *
 * Thin on purpose: the form is SignIn.vue, which knows how to take an email
 * and a password and nothing about where anybody goes afterwards. This decides
 * that, because it is the piece that has the URL in front of it.
 *
 * Sign-in used to sit above the router as an overlay — no address of its own,
 * painted over whatever route had been resolved underneath. That worked until
 * you looked at the URL: "/" asks landing() where this person belongs, landing()
 * reads a permission list that a signed-out visitor does not have, and the
 * honest answer to "which of these nine screens can you open" is none of them.
 * So the front page of the application announced itself as /no-access.
 */

const route = useRoute()
const router = useRouter()

/**
 * Sent to the deep link they asked for, or to the best screen this account can
 * open. Replace rather than push, so the back button does not lead to a sign-in
 * page for a session that already exists.
 *
 * The deep link is VALIDATED, NOT TRUSTED — see auth/redirect.ts, which is
 * where the reasoning about that lives.
 *
 * A deep link to something this account may not open needs no check here: the
 * router's guard reads the permission on the route and sends them to landing()
 * instead, which is the same answer it gives everybody else.
 */
function onSignedIn(): void {
    void router.replace(intendedPath(route.query.next) ?? { name: landing() })
}
</script>

<template>
    <SignIn @signed-in="onSignedIn" />
</template>
