<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import {
    ASSIGNABLE_ROLES,
    type AdminUser,
    createUser,
    listUsers,
    resetUserPassword,
    updateUser,
} from '@/api/admin'
import { session } from '@/auth/session'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'

/**
 * Who can sign in, and as what.
 *
 * This screen is what turns bin/recover-access back into break-glass. Adding
 * an assessor, promoting a colleague and resetting a forgotten password all
 * happen here now, and none of them needs a shell on the server.
 *
 * A generated password is shown ONCE, in a panel that has to be dismissed
 * deliberately, because there is no second chance to read it and the person it
 * belongs to is usually standing there. It is never stored in this component
 * beyond that panel.
 */

const users = ref<AdminUser[]>([])
const loading = ref(true)
const error = ref('')

/** The password just issued, and who for. Cleared only by the person reading it. */
const issued = ref<{ name: string; password: string } | null>(null)

const adding = ref(false)
const draft = ref({ email: '', full_name: '', role: 'assessor' })

const me = computed(() => session.value?.user.id ?? 0)

async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        users.value = await listUsers()
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')
    } finally {
        loading.value = false
    }
}

async function act<T>(run: () => Promise<T>): Promise<T | null> {
    error.value = ''

    try {
        return await run()
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')

        return null
    }
}

async function onAdd(): Promise<void> {
    const result = await act(() => createUser(draft.value))

    if (result === null) {
        return
    }

    issued.value = { name: result.user.full_name, password: result.password }
    adding.value = false
    draft.value = { email: '', full_name: '', role: 'assessor' }
    await load()
}

async function onRoleChange(user: AdminUser, role: string): Promise<void> {
    const updated = await act(() => updateUser(user.id, { role }))

    if (updated !== null) {
        await load()
    }
}

async function onToggleActive(user: AdminUser): Promise<void> {
    const updated = await act(() => updateUser(user.id, { is_active: !user.is_active }))

    if (updated !== null) {
        await load()
    }
}

async function onResetPassword(user: AdminUser): Promise<void> {
    const result = await act(() => resetUserPassword(user.id))

    if (result === null) {
        return
    }

    issued.value = { name: result.user.full_name, password: result.password }
    await load()
}

onMounted(load)
</script>

<template>
    <AdminShell :title="t('admin.users')" :subtitle="t('admin.usersSubtitle')">
        <!-- Shown once, and dismissed on purpose. There is no second chance. -->
        <div
            v-if="issued"
            class="mb-4 rounded-card border border-accent bg-accent-soft px-4 py-3"
            role="alert"
        >
            <p class="text-[14px] font-semibold text-accent">
                {{ t('admin.passwordFor', { name: issued.name }) }}
            </p>
            <p class="tnum mt-1 select-all font-mono text-[18px]">{{ issued.password }}</p>
            <p class="mt-1 text-[13px] text-label-2">{{ t('admin.passwordOnce') }}</p>
            <button type="button" class="mt-2 text-[14px] font-medium text-accent" @click="issued = null">
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
                {{ adding ? t('action.cancel') : t('admin.addUser') }}
            </button>
        </div>

        <form
            v-if="adding"
            class="mb-5 grid gap-3 rounded-card bg-surface p-4 sm:grid-cols-[1fr_1fr_auto_auto]"
            @submit.prevent="onAdd"
        >
            <input
                v-model="draft.full_name"
                type="text"
                :placeholder="t('admin.fullName')"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <input
                v-model="draft.email"
                type="email"
                autocapitalize="off"
                spellcheck="false"
                :placeholder="t('admin.email')"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
            />
            <select
                v-model="draft.role"
                class="rounded-lg bg-ground px-3 py-2 text-[15px] outline-none"
            >
                <option v-for="role in ASSIGNABLE_ROLES" :key="role" :value="role">
                    {{ t(`role.${role}` as 'role.admin') }}
                </option>
            </select>
            <button
                type="submit"
                class="rounded-lg bg-accent px-4 py-2 text-[14px] font-semibold text-white disabled:opacity-40"
                :disabled="draft.email.trim() === '' || draft.full_name.trim() === ''"
            >
                {{ t('admin.addUser') }}
            </button>
        </form>

        <p v-if="loading" class="text-[15px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="overflow-x-auto rounded-card bg-surface">
            <table class="w-full min-w-[720px] text-left">
                <thead>
                    <tr class="border-b border-hairline text-[12px] uppercase tracking-wide text-label-2">
                        <th class="px-4 py-2.5 font-semibold">{{ t('admin.person') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ t('admin.role') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ t('admin.lastSignIn') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ t('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-b border-hairline last:border-0"
                        :class="user.is_active ? '' : 'opacity-50'"
                    >
                        <td class="px-4 py-3">
                            <span class="block text-[15px]">{{ user.full_name }}</span>
                            <span class="block text-[13px] text-label-2">{{ user.email }}</span>
                            <span
                                v-if="user.must_change_password"
                                class="mt-0.5 inline-block text-[12px] text-partial"
                            >
                                {{ t('admin.mustChangePassword') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <!-- Your own role is not editable here: removing it
                                 is how somebody locks themselves out, and the
                                 server refuses it anyway. -->
                            <select
                                v-if="user.id !== me && user.role !== 'superadmin'"
                                :value="user.role"
                                class="rounded-lg bg-ground px-2.5 py-1.5 text-[14px] outline-none"
                                @change="
                                    onRoleChange(user, ($event.target as HTMLSelectElement).value)
                                "
                            >
                                <option v-for="role in ASSIGNABLE_ROLES" :key="role" :value="role">
                                    {{ t(`role.${role}` as 'role.admin') }}
                                </option>
                            </select>
                            <span v-else class="text-[14px] text-label-2">
                                {{ t(`role.${user.role}` as 'role.admin') }}
                            </span>
                        </td>
                        <td class="tnum px-4 py-3 text-[13px] text-label-2">
                            {{
                                user.last_login_at === null
                                    ? t('admin.never')
                                    : new Date(user.last_login_at).toLocaleDateString()
                            }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="text-[14px] text-accent"
                                @click="onResetPassword(user)"
                            >
                                {{ t('admin.resetPassword') }}
                            </button>
                            <button
                                v-if="user.id !== me"
                                type="button"
                                class="ml-4 text-[14px]"
                                :class="user.is_active ? 'text-no' : 'text-yes'"
                                @click="onToggleActive(user)"
                            >
                                {{ user.is_active ? t('admin.deactivate') : t('admin.activate') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminShell>
</template>
