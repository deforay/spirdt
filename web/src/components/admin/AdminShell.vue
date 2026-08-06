<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import { session } from '@/auth/session'
import { signOut } from '@/auth/login'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { t } from '@/i18n'

/**
 * The frame every management screen sits in.
 *
 * Wider than the assessor app on purpose. That one is a 430px column because
 * it is held in one hand in a clinic; this is read at a desk, and the same
 * column here would waste two thirds of the screen on the tables it exists to
 * show.
 *
 * The navigation lists only what this role can open. A viewer sees the
 * readable screens and not the ones that change things — which matches what
 * the API will allow, so a link never leads somewhere that refuses.
 */

const props = defineProps<{ title: string; subtitle?: string }>()

const user = computed(() => session.value?.user ?? null)

const canAdminister = computed(() =>
    ['admin', 'superadmin'].includes(user.value?.role ?? ''),
)

/** Managing the other organisations in this country is the superadmin's job. */
const ownsProgramme = computed(() => user.value?.role === 'superadmin')

const links = computed(() =>
    [
        // People is administrators only; the registry and the plan are readable
        // by a viewer, because the dashboard filters by the same hierarchy.
        { to: { name: 'admin-users' }, label: t('admin.users'), show: canAdminister.value },
        { to: { name: 'admin-registry' }, label: t('registry.title'), show: true },
        { to: { name: 'admin-assignments' }, label: t('assignments.title'), show: true },
        { to: { name: 'admin-organizations' }, label: t('organizations.title'), show: ownsProgramme.value },
    ].filter((link) => link.show),
)

async function onSignOut(): Promise<void> {
    await signOut(session.value?.refreshToken ?? null)
}
</script>

<template>
    <div class="min-h-screen bg-ground">
        <header class="border-b border-hairline bg-surface">
            <div class="mx-auto flex max-w-[1100px] items-center gap-4 px-5 py-3">
                <span class="text-[17px] font-bold tracking-tight">SPI-RDT</span>

                <nav class="flex flex-1 items-center gap-1">
                    <RouterLink
                        v-for="link in links"
                        :key="link.label"
                        :to="link.to"
                        class="rounded-full px-3 py-1.5 text-[14px] font-medium text-label-2 hover:text-label"
                        active-class="bg-accent-soft text-accent"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <LocaleSwitcher />

                <div class="flex items-center gap-3">
                    <span class="hidden text-[13px] text-label-2 sm:block">
                        {{ user?.fullName }}
                    </span>
                    <button type="button" class="text-[14px] text-accent" @click="onSignOut">
                        {{ t('admin.signOut') }}
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[1100px] px-5 py-6">
            <div class="mb-5">
                <h1 class="text-[26px] font-bold tracking-tight">{{ props.title }}</h1>
                <p v-if="props.subtitle" class="mt-0.5 text-[14px] text-label-2">
                    {{ props.subtitle }}
                </p>
            </div>

            <slot />
        </main>
    </div>
</template>
