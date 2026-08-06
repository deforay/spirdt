<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { type AdminUser, listUsers } from '@/api/admin'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'
import { RouterLink } from 'vue-router'

/**
 * Who can sign in, and as what.
 *
 * This screen is what turns bin/recover-access back into break-glass. Adding
 * an assessor, promoting a colleague and resetting a forgotten password all
 * happen here now, and none of them needs a shell on the server.
 *
 * The list only lists. Adding somebody, changing a role and resetting a
 * password all happen on the person's own page, so the generated password —
 * which is shown once and never retrievable — appears somewhere that cannot be
 * dismissed by a stray click while it is being copied down.
 */

const users = ref<AdminUser[]>([])
const loading = ref(true)
const error = ref('')

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

onMounted(load)
</script>

<template>
    <AdminShell :title="t('admin.users')" :subtitle="t('admin.usersSubtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex justify-end">
            <RouterLink
                :to="{ name: 'admin-user-new' }"
                class="rounded-full bg-accent px-4 py-2 text-[14px] font-semibold text-white"
            >
                {{ t('admin.addUser') }}
            </RouterLink>
        </div>

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
                            <RouterLink :to="{ name: 'admin-user', params: { id: user.id } }">
                                <span class="block text-[15px] hover:text-accent">
                                    {{ user.full_name }}
                                </span>
                                <span class="block text-[13px] text-label-2">{{ user.email }}</span>
                                <span
                                    v-if="user.must_change_password"
                                    class="mt-0.5 inline-block text-[12px] text-partial"
                                >
                                    {{ t('admin.mustChangePassword') }}
                                </span>
                            </RouterLink>
                        </td>
                        <td class="px-4 py-3 text-[14px] text-label-2">
                            {{ t(`role.${user.role}` as 'role.admin') }}
                        </td>
                        <td class="tnum px-4 py-3 text-[13px] text-label-2">
                            {{
                                user.last_login_at === null
                                    ? t('admin.never')
                                    : new Date(user.last_login_at).toLocaleDateString()
                            }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <RouterLink
                                :to="{ name: 'admin-user', params: { id: user.id } }"
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
