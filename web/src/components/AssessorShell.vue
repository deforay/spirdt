<script setup lang="ts">
import { PhCaretDown, PhChartBar, PhSignOut, PhUserCircle } from '@phosphor-icons/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { signOut } from '@/auth/login'
import { session } from '@/auth/session'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import StorageNotice from '@/components/StorageNotice.vue'
import SyncBadge from '@/components/SyncBadge.vue'
import type { SaveState } from '@/composables/useAssessment'
import type { StorageReport } from '@/db/storage'
import { t } from '@/i18n'
import { syncAll } from '@/sync/engine'

/**
 * The frame around every assessor screen.
 *
 * The management side has had one since the beginning; the assessor side had
 * none, and the cost was not cosmetic. Signing out lived only on the admin
 * shell, so an assessor could not leave at all — on a shared tablet the next
 * person to pick it up was signed in as the last, and every visit they
 * recorded was filed against that name.
 *
 * It also gives the identity somewhere to live. An application whose output is
 * a record of who assessed what should say who it thinks you are, on the
 * screen, without being asked.
 *
 * Deliberately slim. This sits above a fifty-nine question form worked
 * standing up, and every row it takes is a row of questions somebody has to
 * scroll past. Brand, sync state, language, and a menu — nothing else earns
 * the height.
 *
 * The sync badge and the language switcher moved here from the screens below.
 * They were repeated on three of them and absent from the fourth, which is
 * what a shell is for.
 */

/**
 * The storage notice belongs to the frame, not to a screen.
 *
 * It was rendered twice inside the view below and floated between the top bar
 * and the page heading, attached to neither. What it says is true of the
 * device rather than of whatever screen happens to be open, so it sits under
 * the bar, in the same column as everything else, and is written once.
 */
defineProps<{
    storage?: StorageReport | null
    saveState?: SaveState
    saveError?: string
}>()

const open = ref(false)

const user = computed(() => session.value?.user ?? null)

/** Two letters off the name, for the circle beside it. */
const initials = computed(() => {
    const parts = (user.value?.fullName ?? '').trim().split(/\s+/).filter((part) => part !== '')

    if (parts.length === 0) return '?'

    const first = parts[0]![0] ?? ''
    const last = parts.length > 1 ? (parts[parts.length - 1]![0] ?? '') : ''

    return (first + last).toUpperCase()
})

async function onSignOut(): Promise<void> {
    open.value = false
    await signOut(session.value?.refreshToken ?? null)
}

/**
 * Close on any click that is not inside the menu.
 *
 * A menu that stays open behind the next tap is a menu whose Sign out gets
 * pressed by somebody reaching for a question underneath it.
 */
const root = ref<HTMLElement | null>(null)

function onDocumentClick(event: MouseEvent): void {
    if (open.value && root.value !== null && !root.value.contains(event.target as Node)) {
        open.value = false
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
    <div class="flex min-h-screen flex-col bg-ground">
        <!--
            The same bar the management side wears: white, hairline under it,
            the mark on the left and who you are on the right. The two halves
            of the application are one product and an assessor moving between
            them should not feel the join.
        -->
        <header class="border-b border-hairline bg-surface">
            <div class="mx-auto flex h-[64px] w-full max-w-[1536px] items-center gap-3 px-4 sm:px-6">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-card bg-accent text-accent-ink"
                >
                    <PhChartBar :size="17" weight="bold" aria-hidden="true" />
                </span>
                <span class="text-[17px] font-bold tracking-[-0.02em]">SPI-RDT</span>

                <span class="flex-1"></span>

                <SyncBadge @retry="syncAll()" />
                <LocaleSwitcher />

                <div ref="root" class="relative">
                    <button
                        type="button"
                        class="flex min-h-11 items-center gap-2 rounded-full py-1 pl-1 pr-2 text-[14px] font-medium text-label transition-colors hover:bg-accent-soft"
                        :aria-expanded="open"
                        aria-haspopup="menu"
                        @click="open = !open"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-[13px] font-semibold text-accent-ink"
                            aria-hidden="true"
                        >
                            {{ initials }}
                        </span>
                        <!-- The name is the point of showing anything here, so
                             it is what survives when the screen is narrow. -->
                        <span class="max-w-[9rem] truncate">{{ user?.fullName }}</span>
                        <PhCaretDown :size="13" class="shrink-0" aria-hidden="true" />
                    </button>

                    <div
                        v-if="open"
                        role="menu"
                        class="absolute right-0 top-full z-10 mt-1 w-56 overflow-hidden rounded-surface bg-surface shadow-surface"
                    >
                        <p class="truncate px-3.5 pb-1 pt-3 text-[14px] text-label-3">
                            {{ user?.email }}
                        </p>



                        <RouterLink
                            :to="{ name: 'account' }"
                            role="menuitem"
                            class="flex w-full items-center gap-2.5 px-3.5 py-3 text-left text-[16px]"
                            @click="open = false"
                        >
                            <PhUserCircle
                                :size="16"
                                class="shrink-0 text-label-3"
                                aria-hidden="true"
                            />
                            {{ t('account.title') }}
                        </RouterLink>

                        <button
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-2.5 border-t border-hairline px-3.5 py-3 text-left text-[16px] text-no"
                            @click="onSignOut"
                        >
                            <PhSignOut :size="16" class="shrink-0" aria-hidden="true" />
                            {{ t('admin.signOut') }}
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="mx-auto w-full max-w-[1536px] px-4 sm:px-6">
            <StorageNotice
                :storage="storage ?? null"
                :save-state="saveState ?? 'idle'"
                :save-error="saveError ?? ''"
            />
        </div>

        <div class="flex min-h-0 flex-1 flex-col">
            <slot />
        </div>
    </div>
</template>
