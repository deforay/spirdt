<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import { can, PERMISSION } from '@/auth/permissions'
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
 * The navigation lists only what this account can open, asked as what it may
 * DO rather than what its role is called. The two answered the same until
 * permissions became editable; now an organisation that grants the registry to
 * a role of its own gets the links, and one that takes reports away from its
 * viewers stops showing them.
 *
 * Each entry names the same permission its route does, so a link never leads
 * somewhere that refuses.
 */

const props = defineProps<{ title: string; subtitle?: string }>()

const user = computed(() => session.value?.user ?? null)

const links = computed(() =>
    [
        { to: { name: 'admin-reports' }, label: t('reports.title'), need: PERMISSION.reportsRead },
        { to: { name: 'admin-users' }, label: t('admin.users'), need: PERMISSION.usersManage },
        { to: { name: 'admin-roles' }, label: t('roles.title'), need: PERMISSION.rolesManage },
        { to: { name: 'admin-places' }, label: t('places.title'), need: PERMISSION.registryRead },
        { to: { name: 'admin-facilities' }, label: t('facilities.title'), need: PERMISSION.registryRead },
        { to: { name: 'admin-sites' }, label: t('sitesAdmin.title'), need: PERMISSION.registryRead },
        { to: { name: 'admin-assignments' }, label: t('assignments.title'), need: PERMISSION.registryRead },
        {
            to: { name: 'admin-organizations' },
            label: t('organizations.title'),
            need: PERMISSION.organizationsManage,
        },
    ].filter((link) => can(link.need)),
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
