<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { apiRequest } from '@/api/client'
import AdminShell from '@/components/admin/AdminShell.vue'
import FormField from '@/components/admin/FormField.vue'
import { localeName, t } from '@/i18n'

/**
 * How this installation is set up.
 *
 * THREE BLOCKS, AND TWO OF THEM ARE NOT THE SAME KIND OF THING. The instance
 * and mail blocks are the installation's, shared by every organisation on it.
 * The localisation block belongs to one organisation — the timezone and the
 * language have been columns on it since the beginning, and the activity trail
 * already reads the timezone to decide where a day begins. The block says whose
 * it is rather than leaving somebody to assume it is everyone's.
 *
 * Blocks rather than one long form, because these are answered at different
 * times by different people. Somebody names the deployment once; the mail
 * settings are filled in the day a mail server exists and not before.
 *
 * THE PASSWORD IS WRITE-ONLY. The server never sends it back, so the box is
 * always empty and an empty box means "leave it alone" — the alternative would
 * wipe the password every time somebody corrected the port. Removing it is its
 * own control, which is what makes the distinction visible instead of implied.
 */

interface Settings {
    instance: { name: string; contact_name: string; contact_email: string }
    localisation: { timezone: string; locale: string; country_code: string; organization: string }
    mail: {
        host: string
        port: number
        encryption: string
        username: string
        from_address: string
        from_name: string
        has_password: boolean
    }
    can_store_password: boolean
    timezones: string[]
    locales: string[]
    encryptions: string[]
}

const settings = ref<Settings | null>(null)
const password = ref('')
const clearPassword = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)

const encryptionLabels: Record<string, string> = { none: t('settings.none'), tls: 'TLS', ssl: 'SSL' }

const passwordHint = computed(() => {
    if (settings.value === null) {
        return ''
    }

    if (!settings.value.can_store_password) {
        return t('settings.noKey')
    }

    return settings.value.mail.has_password
        ? t('settings.passwordSet')
        : t('settings.passwordUnset')
})

async function load(): Promise<void> {
    error.value = ''

    try {
        settings.value = await apiRequest<Settings>('/admin/settings', { method: 'GET' })
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
    }
}

async function onSave(): Promise<void> {
    const current = settings.value

    if (current === null) {
        return
    }

    saving.value = true
    error.value = ''
    saved.value = false

    try {
        settings.value = await apiRequest<Settings>('/admin/settings', {
            method: 'PATCH',
            body: {
                instance: current.instance,
                localisation: {
                    timezone: current.localisation.timezone,
                    locale: current.localisation.locale,
                    country_code: current.localisation.country_code,
                },
                mail: {
                    host: current.mail.host,
                    port: current.mail.port,
                    encryption: current.mail.encryption,
                    username: current.mail.username,
                    from_address: current.mail.from_address,
                    from_name: current.mail.from_name,
                    password: password.value,
                    clear_password: clearPassword.value,
                },
            },
        })

        // Emptied on success rather than left in the box. A password sitting in
        // a field on a screen somebody walks away from is the one thing here
        // worth being careful about.
        password.value = ''
        clearPassword.value = false
        saved.value = true
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
    }

    saving.value = false
}

onMounted(load)

const inputClass = 'field'
</script>

<template>
    <AdminShell :title="t('settings.title')" :subtitle="t('settings.subtitle')">
        <p v-if="error" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <form v-if="settings" class="flex flex-col gap-5" @submit.prevent="onSave">
            <section class="rounded-surface border border-hairline bg-surface p-6">
                <h2 class="text-[18px] font-semibold">{{ t('settings.instance') }}</h2>
                <p class="mb-4 mt-1 text-[14px] text-label-2">{{ t('settings.instanceHint') }}</p>

                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField :label="t('settings.name')" :hint="t('settings.nameHint')">
                        <input v-model="settings.instance.name" type="text" :class="inputClass" />
                    </FormField>

                    <FormField :label="t('settings.contactName')">
                        <input
                            v-model="settings.instance.contact_name"
                            type="text"
                            :class="inputClass"
                        />
                    </FormField>

                    <FormField
                        :label="t('settings.contactEmail')"
                        :hint="t('settings.contactEmailHint')"
                    >
                        <input
                            v-model="settings.instance.contact_email"
                            type="email"
                            autocapitalize="off"
                            spellcheck="false"
                            :class="inputClass"
                        />
                    </FormField>
                </div>
            </section>

            <section class="rounded-surface border border-hairline bg-surface p-6">
                <h2 class="text-[18px] font-semibold">{{ t('settings.localisation') }}</h2>
                <p class="mb-4 mt-1 text-[14px] text-label-2">
                    {{
                        t('settings.localisationHint', {
                            organization: settings.localisation.organization,
                        })
                    }}
                </p>

                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField :label="t('settings.timezone')" :hint="t('settings.timezoneHint')">
                        <select v-model="settings.localisation.timezone" :class="inputClass">
                            <option v-for="zone in settings.timezones" :key="zone" :value="zone">
                                {{ zone }}
                            </option>
                        </select>
                    </FormField>

                    <FormField :label="t('settings.language')" :hint="t('settings.languageHint')">
                        <select v-model="settings.localisation.locale" :class="inputClass">
                            <option v-for="code in settings.locales" :key="code" :value="code">
                                {{ localeName(code) }}
                            </option>
                        </select>
                    </FormField>

                    <FormField :label="t('settings.country')" :hint="t('settings.countryHint')">
                        <input
                            v-model="settings.localisation.country_code"
                            type="text"
                            maxlength="2"
                            autocapitalize="characters"
                            spellcheck="false"
                            :class="inputClass"
                        />
                    </FormField>
                </div>
            </section>

            <section class="rounded-surface border border-hairline bg-surface p-6">
                <h2 class="text-[18px] font-semibold">{{ t('settings.mail') }}</h2>
                <p class="mb-4 mt-1 text-[14px] text-label-2">{{ t('settings.mailHint') }}</p>

                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField :label="t('settings.host')">
                        <input
                            v-model="settings.mail.host"
                            type="text"
                            autocapitalize="off"
                            spellcheck="false"
                            placeholder="smtp.example.org"
                            :class="inputClass"
                        />
                    </FormField>

                    <FormField :label="t('settings.port')">
                        <input
                            v-model.number="settings.mail.port"
                            type="number"
                            min="1"
                            max="65535"
                            :class="[inputClass, 'tnum']"
                        />
                    </FormField>

                    <FormField :label="t('settings.encryption')">
                        <select v-model="settings.mail.encryption" :class="inputClass">
                            <option v-for="mode in settings.encryptions" :key="mode" :value="mode">
                                {{ encryptionLabels[mode] ?? mode }}
                            </option>
                        </select>
                    </FormField>

                    <FormField :label="t('settings.username')">
                        <input
                            v-model="settings.mail.username"
                            type="text"
                            autocapitalize="off"
                            spellcheck="false"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </FormField>

                    <FormField :label="t('settings.password')" :hint="passwordHint">
                        <input
                            v-model="password"
                            type="password"
                            autocomplete="new-password"
                            :disabled="!settings.can_store_password"
                            :class="[inputClass, settings.can_store_password ? '' : 'opacity-60']"
                        />
                    </FormField>

                    <div v-if="settings.mail.has_password" class="flex items-end">
                        <label class="flex items-center gap-2 text-[15px] text-label-2">
                            <input v-model="clearPassword" type="checkbox" />
                            {{ t('settings.passwordClear') }}
                        </label>
                    </div>

                    <FormField :label="t('settings.fromAddress')">
                        <input
                            v-model="settings.mail.from_address"
                            type="email"
                            autocapitalize="off"
                            spellcheck="false"
                            :class="inputClass"
                        />
                    </FormField>

                    <FormField :label="t('settings.fromName')">
                        <input v-model="settings.mail.from_name" type="text" :class="inputClass" />
                    </FormField>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="min-h-11 rounded-card bg-accent px-5 text-[16px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40"
                    :disabled="saving"
                >
                    {{ saving ? t('form.saving') : t('form.save') }}
                </button>

                <span v-if="saved" class="text-[15px] text-label-2">{{ t('settings.saved') }}</span>
            </div>
        </form>
    </AdminShell>
</template>
