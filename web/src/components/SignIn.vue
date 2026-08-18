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
 *
 * Two layouts, one form. On a phone it is the form and nothing else, because
 * that is the screen an assessor signs in on at the start of a visit. Past
 * 1024px a navy panel carries the name of the programme beside it — that is
 * the width a stakeholder sees the app at, and this is the first screen they
 * see. The panel holds no controls, so nothing is lost when it is not there.
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
    <div class="flex min-h-screen bg-ground lg:items-stretch">
        <!--
            The panel is decoration in the strict sense: nothing here can be
            acted on, and it is hidden below 1024px rather than stacked, so a
            phone opens straight onto the form. aria-hidden for the same
            reason — the wordmark beside the form would otherwise be announced
            twice.
        -->
        <aside
            aria-hidden="true"
            class="hidden w-[46%] max-w-[560px] flex-col justify-between bg-accent px-12 py-14 text-accent-ink lg:flex"
        >
            <div>
                <span class="eyebrow text-white/60">SPI-RDT</span>
                <p class="rule-brass mt-6 max-w-[15ch] pb-6 text-[38px] font-extrabold leading-[1.12]">
                    {{ t('signIn.tagline') }}
                </p>
            </div>

            <!-- The levels, as the thing the app exists to produce. Five
                 squares on the brass-to-navy ramp say what a stepwise
                 assessment is faster than a sentence about it does. -->
            <div class="flex items-center gap-2 text-[13px] text-white/70">
                <span
                    v-for="level in [0, 1, 2, 3, 4]"
                    :key="level"
                    class="tnum flex h-7 w-7 items-center justify-center rounded-[7px] font-semibold"
                    :class="[
                        'bg-white/10',
                        level === 4 ? 'bg-brass-fill text-on-brass' : '',
                    ]"
                >
                    {{ level }}
                </span>
                <span class="ml-2">{{ t('score.level', { level: 4 }) }}</span>
            </div>
        </aside>

        <div class="flex min-h-screen w-full flex-col justify-center px-5 lg:px-16">
            <div class="mx-auto w-full max-w-[430px]">
                <header class="mb-7 flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-[32px] font-extrabold">SPI-RDT</h1>
                        <p class="mt-1 text-[16px] text-label-2">{{ t('signIn.subtitle') }}</p>
                    </div>
                    <div class="mt-1.5"><LocaleSwitcher /></div>
                </header>

                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <!-- Label above field, as everywhere else. The row idiom
                         this replaces put a fixed 76px gutter before every
                         input, which is a quarter of a phone's width spent on
                         the word "Email". -->
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[14px] font-medium text-label-2">
                            {{ t('signIn.email') }}
                        </span>
                        <input
                            v-model="email"
                            type="email"
                            autocomplete="username"
                            inputmode="email"
                            autocapitalize="off"
                            spellcheck="false"
                            class="field"
                            placeholder="you@example.org"
                        />
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[14px] font-medium text-label-2">
                            {{ t('signIn.password') }}
                        </span>
                        <input
                            v-model="password"
                            type="password"
                            autocomplete="current-password"
                            class="field"
                            placeholder="••••••••••••"
                        />
                    </label>

                    <label v-if="needsOrganization" class="flex flex-col gap-1.5">
                        <span class="text-[14px] font-medium text-label-2">
                            {{ t('signIn.organization') }}
                        </span>
                        <input
                            v-model="organization"
                            type="text"
                            autocapitalize="off"
                            spellcheck="false"
                            class="field"
                            placeholder="demo"
                        />
                    </label>

                    <p v-if="error !== ''" class="text-[14px] font-medium text-no">{{ error }}</p>

                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="mt-1 min-h-12 rounded-card bg-accent px-5 text-[17px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40"
                    >
                        {{ busy ? t('signIn.submitting') : t('signIn.submit') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
