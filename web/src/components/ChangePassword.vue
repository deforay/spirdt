<script setup lang="ts">
import { computed, ref } from 'vue'

import { changePassword } from '@/auth/login'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { t } from '@/i18n'

/**
 * Replace a password somebody else chose.
 *
 * Shown instead of the app, not alongside it. `bin/provision-org` and
 * `bin/recover-access` both hand out a generated password that has been read
 * out loud or pasted into a message, so until it is replaced the account is a
 * shared secret — and the server refuses everything else until it is, so a
 * dismissible prompt would just produce a screen where nothing works.
 *
 * The current password is asked for even though the person is already signed
 * in. Holding the tablet is not the same as being the account holder.
 */

const emit = defineEmits<{ changed: [] }>()

/**
 * Two homes, one form.
 *
 * On its own it is the screen that blocks a first sign-in with a password
 * somebody else set, so it owns the viewport and says why it is there. Inside
 * the account page it is a panel in a card that already has a heading, and a
 * second heading plus a language switcher would be furniture repeated a
 * centimetre apart.
 */
withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

/** Kept in step with AuthService::MIN_PASSWORD_LENGTH. */
const MIN_LENGTH = 12

const current = ref('')
const next = ref('')
const confirmation = ref('')
const error = ref('')
const busy = ref(false)

// Checked here as well as on the server, to say so before a round trip rather
// than after one. The server is the one that decides.
const tooShort = computed(() => next.value !== '' && next.value.length < MIN_LENGTH)
const mismatched = computed(() => confirmation.value !== '' && confirmation.value !== next.value)

const canSubmit = computed(
    () =>
        current.value !== '' &&
        next.value.length >= MIN_LENGTH &&
        confirmation.value === next.value &&
        !busy.value,
)

async function submit() {
    if (!canSubmit.value) {
        return
    }

    busy.value = true
    error.value = ''

    try {
        await changePassword(current.value, next.value)
        emit('changed')
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('password.failed')
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div
        :class="
            embedded
                ? 'w-full'
                : 'mx-auto flex min-h-screen w-full max-w-[430px] flex-col justify-center bg-ground px-5'
        "
    >
        <header v-if="!embedded" class="mb-7 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-[32px] font-bold tracking-tight">{{ t('password.title') }}</h1>
                <p class="mt-1 text-[16px] text-label-2">{{ t('password.why') }}</p>
            </div>
            <div class="mt-1.5"><LocaleSwitcher /></div>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <!-- Label above the field rather than beside it. The row idiom
                 this replaces gave every input a fixed 104px gutter, which on
                 a phone left a password box a little over half the width of
                 the screen — and inside the account page it disagreed with
                 every other form in the application. -->
            <label class="flex flex-col gap-1.5">
                <span class="text-[14px] font-medium text-label-2">
                    {{ t('password.current') }}
                </span>
                <input
                    v-model="current"
                    type="password"
                    autocomplete="current-password"
                    class="field"
                />
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-[14px] font-medium text-label-2">{{ t('password.new') }}</span>
                <input v-model="next" type="password" autocomplete="new-password" class="field" />
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-[14px] font-medium text-label-2">
                    {{ t('password.confirm') }}
                </span>
                <input
                    v-model="confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="field"
                />
            </label>

            <p v-if="tooShort" class="text-[14px] text-label-2">
                {{ t('password.tooShort', { count: MIN_LENGTH }) }}
            </p>
            <p v-else-if="mismatched" class="text-[14px] text-label-2">
                {{ t('password.mismatch') }}
            </p>

            <p v-if="error !== ''" class="text-[14px] font-medium text-no">{{ error }}</p>

            <button
                type="submit"
                :disabled="!canSubmit"
                class="mt-1 min-h-12 rounded-card bg-accent px-5 text-[16px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40"
            >
                {{ busy ? t('password.saving') : t('password.save') }}
            </button>

            <p v-if="!embedded" class="pt-1 text-center text-[14px] text-label-2">
                {{ t('password.signsOutOthers') }}
            </p>
        </form>
    </div>
</template>
