<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
    type AdminUser,
    ASSIGNABLE_ROLES,
    createUser,
    listUsers,
    resetUserPassword,
    updateUser,
} from '@/api/admin'
import { session } from '@/auth/session'
import FormField from '@/components/admin/FormField.vue'
import FormPage from '@/components/admin/FormPage.vue'
import { flash } from '@/composables/useFlash'
import { t } from '@/i18n'

/**
 * One person who can sign in.
 *
 * A generated password appears once, on creation and on reset, and is never
 * retrievable afterwards — so it is shown on this page rather than as a toast
 * that can be dismissed by a stray click while somebody is copying it down.
 *
 * Your own role and your own account are not editable here. Removing your own
 * access is the commonest way to lock an organisation out, and the server
 * refuses it regardless.
 */

const route = useRoute()
const router = useRouter()

const id = computed(() => (route.params.id === undefined ? null : Number(route.params.id)))
const isNew = computed(() => id.value === null)
const isSelf = computed(() => id.value !== null && id.value === session.value?.user.id)

const form = ref({ full_name: '', email: '', role: 'assessor', title: '', phone: '' })
const isActive = ref(true)
const saving = ref(false)
const error = ref('')
const issued = ref<string | null>(null)

const backTo = { name: 'admin-users' }

async function act<T>(run: () => Promise<T>): Promise<T | null> {
    error.value = ''

    try {
        return await run()
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')

        return null
    }
}

async function onSave(): Promise<void> {
    if (form.value.full_name.trim() === '' || (isNew.value && form.value.email.trim() === '')) {
        error.value = t('userForm.required')

        return
    }

    saving.value = true

    if (id.value === null) {
        const created = await act(() => createUser(form.value))

        saving.value = false

        if (created !== null) {
            // Stay on the page: the password is shown once and somebody is
            // usually standing there waiting to write it down.
            issued.value = created.password
            await router.replace({ name: 'admin-user', params: { id: created.user.id } })
        }

        return
    }

    const saved = await act(() =>
        updateUser(id.value as number, {
            full_name: form.value.full_name.trim(),
            title: form.value.title.trim(),
            phone: form.value.phone.trim(),
            ...(isSelf.value ? {} : { role: form.value.role }),
        }),
    )

    saving.value = false

    if (saved !== null) {
        flash(t('flash.saved', { name: form.value.full_name.trim() }))
        await router.push(backTo)
    }
}

async function onToggleActive(): Promise<void> {
    if (id.value === null) {
        return
    }

    const saved = await act(() => updateUser(id.value as number, { is_active: !isActive.value }))

    if (saved !== null) {
        isActive.value = saved.is_active
        flash(t(saved.is_active ? 'flash.activated' : 'flash.deactivated', {
            name: form.value.full_name,
        }))
    }
}

async function onResetPassword(): Promise<void> {
    if (id.value === null) {
        return
    }

    const result = await act(() => resetUserPassword(id.value as number))

    if (result !== null) {
        issued.value = result.password
    }
}

function fill(user: AdminUser): void {
    form.value = {
        full_name: user.full_name,
        email: user.email,
        role: user.role,
        title: user.title ?? '',
        phone: user.phone ?? '',
    }
    isActive.value = user.is_active
}

onMounted(async () => {
    if (id.value === null) {
        return
    }

    // The list is tens of rows, so it is cheaper than a second endpoint.
    const users = await act(listUsers)
    const existing = users?.find((user) => user.id === id.value)

    if (existing !== undefined) {
        fill(existing)
    }
})

const inputClass = 'field'
</script>

<template>
    <FormPage
        :title="isNew ? t('userForm.addTitle') : form.full_name"
        :subtitle="t('userForm.subtitle')"
        :back-to="backTo"
        :saving="saving"
        :error="error"
        @save="onSave"
    >
        <div v-if="issued" class="rounded-card border border-accent bg-accent-soft px-5 py-4 sm:col-span-2">
            <p class="text-[15px] font-semibold text-accent">{{ t('userForm.passwordIs') }}</p>
            <p class="tnum mt-1 select-all font-mono text-[18px]">{{ issued }}</p>
            <p class="mt-1 text-[14px] text-label-2">{{ t('admin.passwordOnce') }}</p>
        </div>

        <FormField :label="t('admin.fullName')">
            <input v-model="form.full_name" type="text" :class="inputClass" />
        </FormField>

        <FormField :label="t('admin.email')" :hint="isNew ? '' : t('userForm.emailFixed')">
            <input
                v-model="form.email"
                type="email"
                autocapitalize="off"
                spellcheck="false"
                :disabled="!isNew"
                :class="[inputClass, isNew ? '' : 'opacity-60']"
            />
        </FormField>

        <FormField :label="t('admin.role')" :hint="isSelf ? t('userForm.ownRole') : ''">
            <select v-model="form.role" :disabled="isSelf" :class="[inputClass, isSelf ? 'opacity-60' : '']">
                <option v-for="role in ASSIGNABLE_ROLES" :key="role" :value="role">
                    {{ t(`role.${role}` as 'role.admin') }}
                </option>
            </select>
        </FormField>

        <FormField :label="t('userForm.title')">
            <input v-model="form.title" type="text" :class="inputClass" />
        </FormField>

        <FormField :label="t('userForm.phone')">
            <input v-model="form.phone" type="tel" :class="inputClass" />
        </FormField>

        <template #actions>
            <template v-if="!isNew">
                <button type="button" class="text-[15px] text-accent" @click="onResetPassword">
                    {{ t('admin.resetPassword') }}
                </button>
                <button
                    v-if="!isSelf"
                    type="button"
                    class="text-[15px]"
                    :class="isActive ? 'text-no' : 'text-yes'"
                    @click="onToggleActive"
                >
                    {{ isActive ? t('admin.deactivate') : t('admin.activate') }}
                </button>
            </template>
        </template>
    </FormPage>
</template>
