<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { apiRequest } from '@/api/client'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'

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
const issued = ref<{ name: string; email: string; password: string } | null>(null)

const adding = ref(false)
const draft = ref({ code: '', name: '', admin_email: '', admin_name: '' })

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

async function onAdd(): Promise<void> {
    const created = await act(() =>
        apiRequest<{ organization: OrganizationRow; password: string }>('/admin/organizations', {
            body: draft.value,
        }),
    )

    if (created === null) {
        return
    }

    issued.value = {
        name: created.organization.name,
        email: draft.value.admin_email,
        password: created.password,
    }
    adding.value = false
    draft.value = { code: '', name: '', admin_email: '', admin_name: '' }
    await load()
}

async function onToggle(organization: OrganizationRow): Promise<void> {
    const updated = await act(() =>
        apiRequest(`/admin/organizations/${organization.id}`, {
            method: 'PATCH',
            body: { is_active: !organization.is_active },
        }),
    )

    if (updated !== null) {
        await load()
    }
}

onMounted(load)
</script>

<template>
    <AdminShell :title="t('organizations.title')" :subtitle="t('organizations.subtitle')">
        <div
            v-if="issued"
            class="mb-4 rounded-card border border-accent bg-accent-soft px-4 py-3"
            role="alert"
        >
            <p class="text-[14px] font-semibold text-accent">
                {{ t('organizations.created', { name: issued.name }) }}
            </p>
            <p class="mt-1 text-[14px]">{{ issued.email }}</p>
            <p class="tnum mt-1 select-all font-mono text-[18px]">{{ issued.password }}</p>
            <p class="mt-1 text-[13px] text-label-2">{{ t('admin.passwordOnce') }}</p>
            <button
                type="button"
                class="mt-2 text-[14px] font-medium text-accent"
                @click="issued = null"
            >
                {{ t('admin.passwordNoted') }}
            </button>
        </div>

        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex justify-end">
            <button
                type="button"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
                @click="adding = !adding"
            >
                {{ adding ? t('action.cancel') : t('organizations.add') }}
            </button>
        </div>

        <form
            v-if="adding"
            class="mb-5 grid gap-3 rounded-card bg-surface p-4 sm:grid-cols-2"
            @submit.prevent="onAdd"
        >
            <input
                v-model="draft.name"
                type="text"
                :placeholder="t('organizations.name')"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <input
                v-model="draft.code"
                type="text"
                autocapitalize="off"
                spellcheck="false"
                :placeholder="t('organizations.code')"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <input
                v-model="draft.admin_name"
                type="text"
                :placeholder="t('organizations.adminName')"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <input
                v-model="draft.admin_email"
                type="email"
                autocapitalize="off"
                spellcheck="false"
                :placeholder="t('organizations.adminEmail')"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <p class="text-[13px] text-label-2 sm:col-span-2">
                {{ t('organizations.createsAdmin') }}
            </p>
            <div class="sm:col-span-2">
                <button
                    type="submit"
                    class="rounded-lg bg-accent px-4 py-2 text-[14px] font-semibold text-white disabled:opacity-40"
                    :disabled="
                        draft.name.trim() === '' ||
                        draft.code.trim() === '' ||
                        draft.admin_email.trim() === '' ||
                        draft.admin_name.trim() === ''
                    "
                >
                    {{ t('organizations.add') }}
                </button>
            </div>
        </form>

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
                            <span class="block text-[15px]">{{ organization.name }}</span>
                            <span class="tnum block text-[13px] text-label-2">
                                {{ organization.code }}
                            </span>
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
                            <button
                                type="button"
                                class="text-[14px]"
                                :class="organization.is_active ? 'text-no' : 'text-yes'"
                                @click="onToggle(organization)"
                            >
                                {{
                                    organization.is_active
                                        ? t('admin.deactivate')
                                        : t('admin.activate')
                                }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminShell>
</template>
