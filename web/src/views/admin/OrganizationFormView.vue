<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { apiRequest } from '@/api/client'
import FormField from '@/components/admin/FormField.vue'
import FormPage from '@/components/admin/FormPage.vue'
import { flash } from '@/composables/useFlash'
import { t } from '@/i18n'

/**
 * One organisation auditing in this country.
 *
 * Creating one creates its first administrator in the same request. Separately
 * would mean an organisation existing with nobody able to administer it,
 * hoping somebody remembers the second step — which is the state the recovery
 * tool exists to dig out of.
 *
 * The code cannot be changed afterwards: it is typed at sign-in to
 * disambiguate an address held in more than one organisation, so changing it
 * would sign that organisation's people out of a name they had learned.
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

const route = useRoute()
const router = useRouter()

const id = computed(() => (route.params.id === undefined ? null : Number(route.params.id)))
const isNew = computed(() => id.value === null)

const form = ref({
    name: '',
    code: '',
    admin_name: '',
    admin_email: '',
    timezone: 'UTC',
    country_code: '',
})

const isActive = ref(true)
const saving = ref(false)
const error = ref('')
const issued = ref<{ email: string; password: string } | null>(null)

const backTo = { name: 'admin-organizations' }

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
    saving.value = true

    if (id.value === null) {
        const created = await act(() =>
            apiRequest<{ organization: OrganizationRow; password: string }>('/admin/organizations', {
                body: form.value,
            }),
        )

        saving.value = false

        if (created !== null) {
            issued.value = { email: form.value.admin_email, password: created.password }
            await router.replace({ name: 'admin-organization', params: { id: created.organization.id } })
        }

        return
    }

    const saved = await act(() =>
        apiRequest(`/admin/organizations/${id.value}`, {
            method: 'PATCH',
            body: {
                name: form.value.name,
                timezone: form.value.timezone,
                country_code: form.value.country_code,
            },
        }),
    )

    saving.value = false

    if (saved !== null) {
        flash(t('flash.saved', { name: form.value.name }))
        await router.push(backTo)
    }
}

async function onToggleActive(): Promise<void> {
    const saved = await act(() =>
        apiRequest(`/admin/organizations/${id.value}`, {
            method: 'PATCH',
            body: { is_active: !isActive.value },
        }),
    )

    if (saved !== null) {
        isActive.value = !isActive.value
        flash(t(isActive.value ? 'flash.activated' : 'flash.deactivated', { name: form.value.name }))
    }
}

onMounted(async () => {
    if (id.value === null) {
        return
    }

    const body = await act(() =>
        apiRequest<{ organizations: OrganizationRow[] }>('/admin/organizations', { method: 'GET' }),
    )

    const existing = body?.organizations.find((row) => row.id === id.value)

    if (existing !== undefined) {
        form.value = {
            name: existing.name,
            code: existing.code,
            admin_name: '',
            admin_email: '',
            timezone: existing.timezone,
            country_code: existing.country_code ?? '',
        }
        isActive.value = existing.is_active
    }
})

const inputClass = 'field'
</script>

<template>
    <FormPage
        :title="isNew ? t('organizationForm.addTitle') : form.name"
        :subtitle="t('organizationForm.subtitle')"
        :back-to="backTo"
        :saving="saving"
        :error="error"
        @save="onSave"
    >
        <div v-if="issued" class="rounded-card border border-accent bg-accent-soft px-5 py-4 sm:col-span-2">
            <p class="text-[15px] font-semibold text-accent">{{ t('organizationForm.adminIs') }}</p>
            <p class="mt-1 text-[15px]">{{ issued.email }}</p>
            <p class="tnum mt-1 select-all font-mono text-[18px]">{{ issued.password }}</p>
            <p class="mt-1 text-[14px] text-label-2">{{ t('admin.passwordOnce') }}</p>
        </div>

        <FormField :label="t('organizations.name')">
            <input
                v-model="form.name"
                type="text"
                :placeholder="t('eg.organizationName')"
                :class="inputClass"
            />
        </FormField>

        <FormField
            :label="t('organizations.code')"
            :hint="isNew ? t('organizationForm.codeHint') : t('organizationForm.codeFixed')"
        >
            <input
                v-model="form.code"
                type="text"
                autocapitalize="off"
                spellcheck="false"
                :placeholder="t('eg.organizationCode')"
                :disabled="!isNew"
                :class="[inputClass, isNew ? '' : 'opacity-60']"
            />
        </FormField>

        <FormField :label="t('organizationForm.timezone')" :hint="t('organizationForm.timezoneHint')">
            <input
                v-model="form.timezone"
                type="text"
                spellcheck="false"
                :placeholder="t('eg.timezone')"
                :class="inputClass"
            />
        </FormField>

        <FormField :label="t('organizationForm.country')">
            <input
                v-model="form.country_code"
                type="text"
                maxlength="2"
                autocapitalize="characters"
                :placeholder="t('eg.countryCode')"
                :class="inputClass"
            />
        </FormField>

        <template v-if="isNew">
            <FormField :label="t('organizations.adminName')" :hint="t('organizationForm.adminHint')">
                <input
                    v-model="form.admin_name"
                    type="text"
                    :placeholder="t('eg.personName')"
                    :class="inputClass"
                />
            </FormField>

            <FormField :label="t('organizations.adminEmail')">
                <input
                    v-model="form.admin_email"
                    type="email"
                    autocapitalize="off"
                    spellcheck="false"
                    :placeholder="t('eg.email')"
                    :class="inputClass"
                />
            </FormField>
        </template>

        <template #actions>
            <button
                v-if="!isNew"
                type="button"
                class="text-[15px]"
                :class="isActive ? 'text-no' : 'text-yes'"
                @click="onToggleActive"
            >
                {{ isActive ? t('admin.deactivate') : t('admin.activate') }}
            </button>
        </template>
    </FormPage>
</template>
