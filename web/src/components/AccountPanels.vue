<script setup lang="ts">
import { PhKey } from '@phosphor-icons/vue'
import { ref } from 'vue'

import { session } from '@/auth/session'
import ChangePassword from '@/components/ChangePassword.vue'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import ThemeChoice from '@/components/ThemeChoice.vue'
import { t } from '@/i18n'

/**
 * The account, as three cards: who the server thinks you are, how you want
 * this device to look, and the one credential you can change.
 *
 * It exists because the theme and language controls had nowhere to live except
 * a dropdown, where a three-way choice with icons is cramped and nobody looks
 * for it. Everything shown here was already on the client — this page reads
 * the session rather than asking the server for anything.
 */

const user = () => session.value?.user ?? null
const changing = ref(false)
</script>

<template>
    <div class="flex max-w-[900px] flex-col gap-5">
        <section class="rounded-surface border border-hairline bg-surface p-6">
            <h2 class="text-[17px] font-semibold">{{ t('account.profile') }}</h2>

            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-[14px] text-label-2">{{ t('account.name') }}</dt>
                    <dd class="mt-1 text-[16px] font-medium">{{ user()?.fullName }}</dd>
                </div>
                <div>
                    <dt class="text-[14px] text-label-2">{{ t('signIn.email') }}</dt>
                    <dd class="mt-1 text-[16px] font-medium">{{ user()?.email }}</dd>
                </div>
                <div>
                    <dt class="text-[14px] text-label-2">{{ t('admin.role') }}</dt>
                    <dd class="mt-1 text-[16px] font-medium">
                        {{ t(`role.${user()?.role}` as 'role.admin') }}
                    </dd>
                </div>
                <div v-if="user()?.organization">
                    <dt class="text-[14px] text-label-2">{{ t('signIn.organization') }}</dt>
                    <dd class="mt-1 text-[16px] font-medium">{{ user()?.organization }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-surface border border-hairline bg-surface">
            <h2 class="px-6 pt-6 text-[17px] font-semibold">{{ t('account.preferences') }}</h2>

            <!-- Label and help on the left, the control on the right: these are
                 settings rather than fields, and a setting reads as a sentence
                 with a switch at the end of it. -->
            <div class="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-hairline px-6 py-5">
                <div class="min-w-0">
                    <p class="text-[16px] font-medium">{{ t('theme.title') }}</p>
                    <p class="mt-0.5 text-[14px] text-label-2">{{ t('account.themeHint') }}</p>
                </div>
                <div class="w-full max-w-[300px]"><ThemeChoice /></div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-hairline px-6 py-5">
                <div class="min-w-0">
                    <p class="text-[16px] font-medium">{{ t('settings.language') }}</p>
                    <p class="mt-0.5 text-[14px] text-label-2">{{ t('account.languageHint') }}</p>
                </div>
                <LocaleSwitcher />
            </div>
        </section>

        <section class="rounded-surface border border-hairline bg-surface">
            <h2 class="px-6 pt-6 text-[17px] font-semibold">{{ t('account.security') }}</h2>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-hairline px-6 py-5">
                <div class="min-w-0">
                    <p class="text-[16px] font-medium">{{ t('account.changePassword') }}</p>
                    <p class="mt-0.5 text-[14px] text-label-2">
                        {{ t('password.signsOutOthers') }}
                    </p>
                </div>

                <button
                    v-if="!changing"
                    type="button"
                    class="flex min-h-11 items-center gap-2 rounded-card border border-hairline px-4 text-[15px] font-medium transition-colors hover:bg-accent-soft hover:text-accent"
                    @click="changing = true"
                >
                    <PhKey :size="16" aria-hidden="true" />
                    {{ t('account.changePassword') }}
                </button>
            </div>

            <div v-if="changing" class="border-t border-hairline px-6 py-5">
                <ChangePassword embedded @changed="changing = false" />
            </div>
        </section>
    </div>
</template>
