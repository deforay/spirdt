<script setup lang="ts">
import { PhPencilSimple } from '@phosphor-icons/vue'
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
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex justify-end">
            <RouterLink
                :to="{ name: 'admin-user-new' }"
                class="rounded-full bg-accent px-4 py-2 text-[15px] font-semibold text-accent-ink"
            >
                {{ t('admin.addUser') }}
            </RouterLink>
        </div>

        <p v-if="loading" class="text-[16px] text-label-2">{{ t('admin.loading') }}</p>

        <div v-else class="data-card data-scroll">
            <table class="data-table min-w-[720px]">
                <thead>
                    <tr>
                        <th>{{ t('admin.person') }}</th>
                        <th>{{ t('admin.role') }}</th>
                        <th>{{ t('admin.lastSignIn') }}</th>
                        <th>{{ t('admin.status') }}</th>
                        <th class="text-right">{{ t('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                    >
                        <td>
                            <RouterLink :to="{ name: 'admin-user', params: { id: user.id } }">
                                <span class="block text-[16px] hover:text-accent">
                                    {{ user.full_name }}
                                </span>
                                <span class="block text-[14px] text-label-2">{{ user.email }}</span>
                                <!-- Brass, not amber. Amber is Partial on a
                                     question and means nothing else anywhere
                                     in this application. -->
                                <span
                                    v-if="user.must_change_password"
                                    class="chip mt-1.5 bg-brass-soft text-brass"
                                >
                                    {{ t('admin.mustChangePassword') }}
                                </span>
                            </RouterLink>
                        </td>
                        <td class="text-[15px] text-label-2">
                            {{ t(`role.${user.role}` as 'role.admin') }}
                        </td>
                        <td class="tnum text-[14px] text-label-2">
                            {{
                                user.last_login_at === null
                                    ? t('admin.never')
                                    : new Date(user.last_login_at).toLocaleDateString()
                            }}
                        </td>
                        <td>
                            <span
                                :class="[
                                    'chip',
                                    user.is_active
                                        ? 'bg-accent-soft text-accent'
                                        : 'bg-track text-label-2',
                                ]"
                            >
                                {{ user.is_active ? t('admin.active') : t('admin.disabled') }}
                            </span>
                        </td>
                        <td class="text-right">
                            <!-- The row is already a link to the record; this
                                 is the same trip for somebody who reads across
                                 to the last column looking for the verb. -->
                            <RouterLink
                                :to="{ name: 'admin-user', params: { id: user.id } }"
                                class="inline-flex size-10 items-center justify-center rounded-card text-label-2 transition-colors hover:bg-accent-soft hover:text-accent"
                                :aria-label="t('form.edit')"
                            >
                                <PhPencilSimple :size="17" aria-hidden="true" />
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminShell>
</template>
