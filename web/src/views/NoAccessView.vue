<script setup lang="ts">
import { PhSignOut } from '@phosphor-icons/vue'
import { computed } from 'vue'

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

/**
 * Somebody to ask, by name and address, when the installation has said who.
 *
 * "Ask your administrator" is advice that assumes the reader knows which
 * person that is. This screen is reached by exactly the account least likely to
 * — a new one, on its first sign-in, holding nothing. The contact is set on the
 * settings screen and rides on the sign-in response, so it is here before this
 * account can call anything.
 */
/** The same name the management frame shows, so the two agree. */
const brand = computed(() => {
    const name = session.value?.instance?.name ?? ''

    return name === '' ? 'SPI-RDT' : name
})

const contact = computed(() => {
    const instance = session.value?.instance
    const email = instance?.contactEmail ?? ''

    if (email === '') {
        return ''
    }

    const name = instance?.contactName ?? ''

    return name === ''
        ? t('noAccess.contactEmail', { email })
        : t('noAccess.contact', { name, email })
})

async function onSignOut(): Promise<void> {
    await signOut(session.value?.refreshToken ?? null)
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col justify-center bg-ground px-5">
        <header class="mb-7 flex items-start justify-between gap-3">
            <h1 class="text-[32px] font-bold tracking-tight">{{ brand }}</h1>
            <div class="mt-1.5"><LocaleSwitcher /></div>
        </header>

        <div class="rounded-surface border border-hairline bg-surface p-6">
            <h2 class="text-[18px] font-semibold">{{ t('noAccess.title') }}</h2>
            <p class="mt-2 text-[16px] leading-relaxed text-label-2">{{ t('noAccess.body') }}</p>

            <p v-if="contact" class="mt-3 text-[16px] leading-relaxed text-label-2">
                {{ contact }}
            </p>

            <p v-if="session?.user.email" class="mt-4 text-[14px] text-label-3">
                {{ session.user.email }}
            </p>
        </div>

        <button
            type="button"
            class="mt-4 flex min-h-12 items-center justify-center gap-2 rounded-card border border-hairline bg-surface px-5 text-[16px] font-medium text-label transition-colors hover:bg-accent-soft hover:text-accent"
            @click="onSignOut"
        >
            <PhSignOut :size="16" aria-hidden="true" />
            {{ t('admin.signOut') }}
        </button>
    </div>
</template>
