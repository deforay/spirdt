<script setup lang="ts">
import { PhSignOut } from '@phosphor-icons/vue'

import { signOut } from '@/auth/login'
import { session } from '@/auth/session'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { t } from '@/i18n'

/**
 * Where an account with no permissions lands.
 *
 * It exists to end a loop. Every other route asks for a capability, so an
 * account holding none was refused everywhere and sent somewhere that refused
 * it again — the router noticed the cycle and threw, and the person saw a blank
 * page with an error in the console. A site_user, which is seeded for every
 * organisation and granted nothing, reached that on their first sign-in.
 *
 * So this screen asks for nothing. It says what is wrong in a way that names
 * who can fix it, and it offers the one action that is actually available:
 * signing out, so the next person can use the device.
 */

async function onSignOut(): Promise<void> {
    await signOut(session.value?.refreshToken ?? null)
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col justify-center bg-ground px-5">
        <header class="mb-7 flex items-start justify-between gap-3">
            <h1 class="text-[30px] font-bold tracking-tight">SPI-RDT</h1>
            <div class="mt-1.5"><LocaleSwitcher /></div>
        </header>

        <div class="rounded-card bg-surface p-5 sm:rounded-surface sm:shadow-surface">
            <h2 class="text-[17px] font-semibold">{{ t('noAccess.title') }}</h2>
            <p class="mt-2 text-[15px] leading-relaxed text-label-2">{{ t('noAccess.body') }}</p>

            <p v-if="session?.user.email" class="mt-4 text-[13px] text-label-3">
                {{ session.user.email }}
            </p>
        </div>

        <button
            type="button"
            class="mt-3 flex items-center justify-center gap-2 rounded-card bg-surface px-4 py-3 text-[15px] font-medium text-label sm:rounded-surface sm:shadow-surface"
            @click="onSignOut"
        >
            <PhSignOut :size="16" aria-hidden="true" />
            {{ t('admin.signOut') }}
        </button>
    </div>
</template>
