<script setup lang="ts">
import {
    PhArrowLeft,
    PhCaretDown,
    PhChartBar,
    PhDoorOpen,
    PhGauge,
    PhSignOut,
    PhUserCircle,
} from '@phosphor-icons/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { signOut } from '@/auth/login'
import { canManage, landing } from '@/auth/permissions'
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
 * scroll past. Sync state, language, a menu, and either the brand or — on a
 * phone, where a screen has somewhere to go back to — the way back. Nothing
 * else earns the height.
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
/**
 * The bar carries where you are, on a phone.
 *
 * A screen with somewhere to go back to used to draw its own row for it,
 * under a bar showing a logo. That is two rows of chrome above the work, and
 * on the checklist — where the header does not scroll away — it was two rows
 * for the length of a fifty-nine question visit. The logo is the part nobody
 * needs while they are working, so on a phone the way out takes its place and
 * the screen below gets the row back. From 768px up there is room for both,
 * and the brand comes back with the screen keeping its own way out.
 */
/**
 * The way out of a visit, as opposed to the way back a step.
 *
 * The back arrow means something different on every stage — out to the site
 * list from setup, back to the section you left, back to the checklist from
 * the review — and on two of them it does not lead out of the visit at all.
 * So an assessor standing in a laboratory who has to leave had to work out
 * which screen they were on and how many steps back the door was, and from
 * the review screen there was no door.
 *
 * This is that door, in the same place on every screen of a visit. Nothing is
 * lost by taking it: the work is already written to the device and the visit
 * stays in the drafts list, which is what the label says.
 */
defineProps<{
    storage?: StorageReport | null
    saveState?: SaveState
    saveError?: string
    /** What the phone bar says beside the back arrow. Absent, it shows the brand. */
    backLabel?: string
    /** Shown while a visit is open. Absent, there is nothing to leave. */
    exitLabel?: string
}>()

const emit = defineEmits<{ back: []; exit: [] }>()

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
                <!-- On a phone, where you are instead of who made the app. -->
                <button
                    v-if="backLabel !== undefined"
                    type="button"
                    class="-ml-1 flex min-h-11 min-w-0 shrink items-center gap-1.5 pr-1 text-left text-[15px] font-medium text-accent md:hidden"
                    @click="emit('back')"
                >
                    <PhArrowLeft :size="15" class="shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ backLabel }}</span>
                </button>

                <span
                    :class="[
                        'size-8 shrink-0 items-center justify-center rounded-card bg-accent text-accent-ink',
                        backLabel === undefined ? 'flex' : 'hidden md:flex',
                    ]"
                >
                    <PhChartBar :size="17" weight="bold" aria-hidden="true" />
                </span>
                <!--
                    The wordmark is the first thing to go, not the last.
                    Where an account has a dashboard to get back to, a phone
                    has room for the mark and the link but not for the name of
                    the product as well — and of the two, the one nobody needs
                    while they are working is the one that says who made it.
                -->
                <span
                    :class="[
                        'whitespace-nowrap text-[17px] font-bold tracking-[-0.02em]',
                        backLabel !== undefined
                            ? 'hidden md:inline'
                            : canManage()
                              ? 'hidden sm:inline'
                              : '',
                    ]"
                    >SPI-RDT</span
                >

                <!--
                    The way to the dashboard, for an account that holds both
                    sides.

                    It has been in the menu under the name for a while, and
                    that was not enough: an administrator who opened the
                    assessor app — or landed on it from a bookmark — reported
                    no way out of it at all, with the link sitting two taps
                    away behind their own initials. A capability nobody can
                    find is one nobody has, and a menu is where a capability
                    goes to not be found.

                    It keeps the brand company, so it appears exactly where
                    there is room for it: always on a screen with nothing else
                    on the left, and from 768px up during a visit, where the
                    phone bar is already carrying the way back. It stays in the
                    menu too, for the widths where it is not here.
                -->
                <RouterLink
                    v-if="canManage()"
                    :to="{ name: landing() }"
                    :class="[
                        'min-h-11 shrink-0 items-center gap-1.5 rounded-full px-2.5 text-[15px] font-medium text-accent transition-opacity hover:opacity-80',
                        backLabel === undefined ? 'flex' : 'hidden md:flex',
                    ]"
                >
                    <PhGauge :size="17" weight="bold" class="shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ t('dash.title') }}</span>
                </RouterLink>

                <span class="flex-1"></span>

                <!--
                    Narrow enough to sit beside the sync badge on a phone: the
                    door alone, with the promise spelled out from 640px up. It
                    keeps its own label as the accessible name either way, so
                    the icon is never the whole of what it says.

                    Icons accompany words rather than replacing them
                    (DESIGN.md), and this is the exception that rule allows: a
                    single action whose name never changes, drawn as the thing
                    it does. The sync badge beside it keeps its word at every
                    width for the opposite reason — what it says varies, and in
                    one state it is a count.

                    Filled and in the accent, because grey text was not a
                    control. It sat in a row of grey chrome — a badge saying
                    Synced, a language, a name — and read as one more label
                    about the state of things rather than the way out. Every
                    other thing on this bar reports; this is the only one that
                    does something, and it is the thing an assessor needs to
                    find while standing up in a laboratory holding a tablet.
                -->
                <button
                    v-if="exitLabel !== undefined"
                    type="button"
                    class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-full bg-accent-soft px-2.5 text-[15px] font-semibold text-accent transition-opacity hover:opacity-80 sm:px-3.5"
                    :aria-label="exitLabel"
                    @click="emit('exit')"
                >
                    <PhDoorOpen :size="18" weight="bold" class="shrink-0" aria-hidden="true" />
                    <span class="hidden sm:inline">{{ exitLabel }}</span>
                </button>

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
                             it is what survives when the screen is narrow —
                             unless the bar is already carrying where you are,
                             in which case the circle stands for it and the
                             menu underneath spells it out. -->
                        <!--
                            The name gives way sooner than it used to. The left
                            of this bar now carries a link as well as a mark, and
                            at 390px the two of them plus a full name pushed the
                            wordmark onto a second line. The circle stands for
                            the name at that width and the menu underneath spells
                            it out, which is the same trade the bar already made
                            during a visit.
                        -->
                        <span
                            :class="[
                                'max-w-[9rem] truncate',
                                backLabel === undefined
                                    ? 'hidden sm:inline'
                                    : 'hidden md:inline',
                            ]"
                            >{{ user?.fullName }}</span
                        >
                        <PhCaretDown
                            :size="13"
                            :class="[
                                'shrink-0',
                                backLabel === undefined ? 'hidden sm:block' : 'hidden md:block',
                            ]"
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        v-if="open"
                        role="menu"
                        class="absolute right-0 top-full z-10 mt-1 w-56 overflow-hidden rounded-surface bg-surface shadow-surface"
                    >
                        <p class="truncate px-3.5 pt-3 text-[14px] font-semibold">
                            {{ user?.fullName }}
                        </p>
                        <p class="truncate px-3.5 pb-1 text-[13px] text-label-3">
                            {{ user?.email }}
                        </p>



                        <!--
                            The way back to the dashboard, for an account that
                            holds both sides.

                            The console has linked into the assessor app since
                            it grew a menu; nothing linked the other way, so an
                            administrator who started a visit — or opened one
                            from a bookmark — could reach their own account and
                            the door out, and no management screen at all
                            without typing an address. A capability nobody can
                            find is one nobody has, and that was as true in this
                            direction as it was in the other.

                            It goes wherever this account belongs rather than
                            to the dashboard by name: the same answer the app
                            gives at sign-in, so somebody who cannot read
                            reports lands on the first screen they can open
                            instead of one that refuses them.
                        -->
                        <RouterLink
                            v-if="canManage()"
                            :to="{ name: landing() }"
                            role="menuitem"
                            class="flex w-full items-center gap-2.5 px-3.5 py-3 text-left text-[16px]"
                            @click="open = false"
                        >
                            <PhGauge :size="16" class="shrink-0 text-label-3" aria-hidden="true" />
                            {{ t('dash.title') }}
                        </RouterLink>

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
