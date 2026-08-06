<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { apiRequest } from '@/api/client'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'
import { RouterLink } from 'vue-router'

/**
 * The organisations auditing in this country.
 *
 * Labelled "Country" on screen and `programme` in the code. The concept is the
 * group of organisations that share a site registry and can therefore be
 * compared; today that is a country, and if one ever runs two separate
 * programmes the code does not have to be renamed to say so.
 *
 * Superadmin only, and bounded to their own programme by the token. This is
 * the one screen where an administrator of one tenant legitimately sees
 * another tenant's row — and even here it is counts, never their assessments.
 */

interface OrganizationRow {
    id: number
    code: string
    name: string
    country_code: string | null
    timezone: string
    is_active: boolean
    user_count: number
    active_admins: number
    assessments: number
}

const organizations = ref<OrganizationRow[]>([])
const loading = ref(true)
const error = ref('')
async function act<T>(run: () => Promise<T>): Promise<T | null> {
    error.value = ''

    try {
        return await run()
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')

        return null
    }
}

async function load(): Promise<void> {
    loading.value = true

    await act(async () => {
        organizations.value = (
            await apiRequest<{ organizations: OrganizationRow[] }>('/admin/organizations', {
                method: 'GET',
            })
        ).organizations
    })

    loading.value = false
}

onMounted(load)
</script>

<template>
    <AdminShell :title="t('organizations.title')" :subtitle="t('organizations.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex justify-end">
            <RouterLink
                :to="{ name: 'admin-organization-new' }"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
            >
                {{ t('organizations.add') }}
            </RouterLink>
        </div>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="overflow-x-auto rounded-card bg-surface">
            <table class="w-full min-w-[720px] text-left">
                <thead>
                    <tr class="border-b border-hairline text-[12px] uppercase tracking-wide text-label-2">
                        <th class="px-4 py-2.5 font-semibold">{{ t('organizations.organisation') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ t('admin.users') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ t('organizations.assessments') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ t('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="organization in organizations"
                        :key="organization.id"
                        class="border-b border-hairline last:border-0"
                        :class="organization.is_active ? '' : 'opacity-50'"
                    >
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'admin-organization', params: { id: organization.id } }">
                                <span class="block text-[15px] hover:text-accent">
                                    {{ organization.name }}
                                </span>
                                <span class="tnum block text-[13px] text-label-2">
                                    {{ organization.code }}
                                </span>
                            </RouterLink>
                        </td>
                        <td class="tnum px-4 py-3 text-[14px]">
                            {{ organization.user_count }}
                            <!-- Nobody able to administer it is the state
                                 bin/recover-access exists to dig out of, so it
                                 is worth seeing before somebody reports it. -->
                            <span v-if="organization.active_admins === 0" class="block text-[12px] text-no">
                                {{ t('organizations.noAdmin') }}
                            </span>
                        </td>
                        <td class="tnum px-4 py-3 text-[14px]">{{ organization.assessments }}</td>
                        <td class="px-4 py-3 text-right">
                            <RouterLink
                                :to="{ name: 'admin-organization', params: { id: organization.id } }"
                                class="text-[14px] text-accent"
                            >
                                {{ t('form.edit') }}
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminShell>
</template>
