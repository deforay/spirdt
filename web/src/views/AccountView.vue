<script setup lang="ts">
import { computed } from 'vue'

import { can, PERMISSION } from '@/auth/permissions'
import AccountPanels from '@/components/AccountPanels.vue'
import AssessorShell from '@/components/AssessorShell.vue'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'

/**
 * One page, whichever half of the application you came from.
 *
 * An account is not a management screen — an assessor has one too — so the
 * frame is chosen by what the person can otherwise reach rather than by the
 * route. Putting it only in the console would mean the assessor half had no
 * way to change a password without being locked out and forced to.
 */
const inConsole = computed(
    () =>
        can(PERMISSION.reportsRead) ||
        can(PERMISSION.registryRead) ||
        can(PERMISSION.usersManage) ||
        can(PERMISSION.rolesManage) ||
        can(PERMISSION.auditRead) ||
        can(PERMISSION.organizationsManage) ||
        can(PERMISSION.settingsManage),
)
</script>

<template>
    <AdminShell v-if="inConsole" :title="t('account.title')" :subtitle="t('account.subtitle')">
        <AccountPanels />
    </AdminShell>

    <AssessorShell v-else>
        <div class="mx-auto w-full max-w-[1536px] px-4 py-6 sm:px-6">
            <h1 class="mb-6 text-[28px] font-bold tracking-[-0.02em]">{{ t('account.title') }}</h1>
            <AccountPanels />
        </div>
    </AssessorShell>
</template>
