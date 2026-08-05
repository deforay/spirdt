<script setup lang="ts">
import { computed, ref } from 'vue'

import { ApiError } from '@/api/client'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { signIn } from '@/auth/login'
import { t } from '@/i18n'

/**
 * Sign in.
 *
 * The organisation field appears only once the server has asked for it. Most
 * installations have one organisation, and a field that is nearly always blank
 * is a field people fill in wrongly.
 */

const emit = defineEmits<{ signedIn: [] }>()

const email = ref('')
const password = ref('')
const organization = ref('')
const needsOrganization = ref(false)
const error = ref('')
const busy = ref(false)

const canSubmit = computed(
    () => email.value.trim() !== '' && password.value !== '' && !busy.value,
)

async function submit() {
    if (!canSubmit.value) {
        return
    }

    busy.value = true
    error.value = ''

    try {
        await signIn({
            email: email.value.trim(),
            password: password.value,
            organization: organization.value.trim() || undefined,
        })

        emit('signedIn')
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 409) {
            needsOrganization.value = true
        }

        error.value = caught instanceof Error ? caught.message : t('signIn.failed')
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col justify-center bg-ground px-5">
        <header class="mb-7 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-[30px] font-bold tracking-tight">SPI-RDT</h1>
                <p class="mt-1 text-[15px] text-label-2">{{ t('signIn.subtitle') }}</p>
            </div>
            <div class="mt-1.5"><LocaleSwitcher /></div>
        </header>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <div class="overflow-hidden rounded-card bg-surface">
                <label class="flex items-center gap-3 px-3.5 py-3">
                    <span class="w-[76px] shrink-0 text-[15px] text-label-2">
                        {{ t('signIn.email') }}
                    </span>
                    <input
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        inputmode="email"
                        autocapitalize="off"
                        spellcheck="false"
                        class="w-full bg-transparent text-[17px] outline-none placeholder:text-label-3"
                        placeholder="you@example.org"
                    />
                </label>

                <div class="ml-[100px] border-t border-hairline"></div>

                <label class="flex items-center gap-3 px-3.5 py-3">
                    <span class="w-[76px] shrink-0 text-[15px] text-label-2">
                        {{ t('signIn.password') }}
                    </span>
                    <input
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full bg-transparent text-[17px] outline-none placeholder:text-label-3"
                        placeholder="••••••••••••"
                    />
                </label>

                <template v-if="needsOrganization">
                    <div class="ml-[100px] border-t border-hairline"></div>

                    <label class="flex items-center gap-3 px-3.5 py-3">
                        <span class="w-[76px] shrink-0 text-[15px] text-label-2">
                            {{ t('signIn.organization') }}
                        </span>
                        <input
                            v-model="organization"
                            type="text"
                            autocapitalize="off"
                            spellcheck="false"
                            class="w-full bg-transparent text-[17px] outline-none placeholder:text-label-3"
                            placeholder="demo"
                        />
                    </label>
                </template>
            </div>

            <p v-if="error !== ''" class="px-1 text-[13px] font-medium text-no">{{ error }}</p>

            <button
                type="submit"
                :disabled="!canSubmit"
                class="mt-1 rounded-card bg-accent py-3.5 text-[17px] font-semibold text-white transition-opacity disabled:opacity-40"
            >
                {{ busy ? t('signIn.submitting') : t('signIn.submit') }}
            </button>
        </form>
    </div>
</template>
