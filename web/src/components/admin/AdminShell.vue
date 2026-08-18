<script setup lang="ts">
import {
    PhBank,
    PhBuildings,
    PhCaretDown,
    PhCaretRight,
    PhChartBar,
    PhClipboardText,
    PhClockCounterClockwise,
    PhFileText,
    PhGauge,
    PhGear,
    PhList,
    PhMapPin,
    PhNotePencil,
    PhShieldCheck,
    PhSignOut,
    PhTestTube,
    PhUserCircle,
    PhUsers,
    PhX,
} from '@phosphor-icons/vue'
import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue'
import { RouterLink } from 'vue-router'

import { can, PERMISSION, type PermissionKey } from '@/auth/permissions'
import { session } from '@/auth/session'
import { signOut } from '@/auth/login'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { t } from '@/i18n'

/**
 * The frame every management screen sits in.
 *
 * Wider than the assessor app on purpose. That one is a 430px column because
 * it is held in one hand in a clinic; this is read at a desk, and the same
 * column here would waste two thirds of the screen on the tables it exists to
 * show.
 *
 * THE NAVIGATION IS A RAIL, not a bar. Ten screens across a top bar had
 * already outgrown it once — "Testing sites" broke mid-word — and the fix then
 * was to hide seven of them behind dropdowns. That works and it costs the
 * thing a management console is for: nobody could see what the application
 * held without opening three menus to find out.
 *
 * Down the side there is room to show every screen the account may open, under
 * the heading it belongs to, permanently. The registry stops being a menu and
 * becomes four lines of a list. It also gives the width back to the tables,
 * which is what the extra height of a bar was being spent on.
 *
 * Below 1024px the rail is a drawer behind one button, because at that width a
 * 248px column is a third of the screen.
 *
 * The navigation lists only what this account can DO. Each entry names the
 * same permission its route does, so a link never leads somewhere that
 * refuses, and a group whose screens are all closed disappears rather than
 * opening onto an empty menu.
 */

const props = defineProps<{ title: string; subtitle?: string; eyebrow?: string }>()

const user = computed(() => session.value?.user ?? null)

/**
 * What this deployment calls itself.
 *
 * Set on the settings screen, and "SPI-RDT" until somebody does. A ministry
 * running one installation and a partner running another should be able to tell
 * at a glance which one they are looking at, and the instrument's own name does
 * not do that.
 */
const brand = computed(() => {
    const name = session.value?.instance?.name ?? ''

    return name === '' ? 'SPI-RDT' : name
})

/** Its own item in the account menu rather than the navigation: it is where
 *  people look for settings, and the bar is already at five groups. */
const canOpenSettings = computed(() => can(PERMISSION.settingsManage))

/** Two letters off the name, for the circle in the header. */
const initials = computed(() => {
    const parts = (user.value?.fullName ?? '').trim().split(/\s+/).filter((part) => part !== '')

    if (parts.length === 0) return '?'

    const first = parts[0]![0] ?? ''
    const last = parts.length > 1 ? (parts[parts.length - 1]![0] ?? '') : ''

    return (first + last).toUpperCase()
})

interface Entry {
    name: string
    label: string
    need: PermissionKey
    icon: Component
}

interface Group {
    key: string
    label: string
    entries: Entry[]
}

/**
 * One idea per item, however many screens sit behind it.
 *
 * A group left with a single visible entry renders as a plain link rather than
 * a menu of one: an account that can read the registry but not the plan should
 * not have to open a dropdown to find the only thing in it.
 */
const groups = computed<Group[]>(() =>
    [
        {
            // The way into the assessor app, for an account that holds both
            // sides. Administrators have always been allowed to record a visit
            // — the permission is in their grant and the route asks for
            // nothing else — but nothing in this console linked to it, so the
            // only way there was to type the address. A capability nobody can
            // find is one nobody has.
            key: 'assess',
            label: t('sites.startNew'),
            entries: [
                {
                    name: 'assess',
                    label: t('sites.startNew'),
                    need: PERMISSION.assessmentsSubmit,
                    icon: PhNotePencil,
                },
            ],
        },
        {
            key: 'dashboard',
            label: t('dash.title'),
            entries: [
                {
                    name: 'admin-dashboard',
                    label: t('dash.title'),
                    need: PERMISSION.reportsRead,
                    icon: PhGauge,
                },
            ],
        },
        {
            key: 'reports',
            label: t('reports.title'),
            entries: [
                {
                    name: 'admin-reports',
                    label: t('reports.title'),
                    need: PERMISSION.reportsRead,
                    icon: PhFileText,
                },
            ],
        },
        {
            key: 'registry',
            label: t('nav.registry'),
            entries: [
                {
                    name: 'admin-places',
                    label: t('places.title'),
                    need: PERMISSION.registryRead,
                    icon: PhMapPin,
                },
                {
                    name: 'admin-facilities',
                    label: t('facilities.title'),
                    need: PERMISSION.registryRead,
                    icon: PhBuildings,
                },
                {
                    name: 'admin-sites',
                    label: t('sitesAdmin.title'),
                    need: PERMISSION.registryRead,
                    icon: PhTestTube,
                },
                {
                    name: 'admin-assignments',
                    label: t('assignments.title'),
                    need: PERMISSION.registryRead,
                    icon: PhClipboardText,
                },
            ],
        },
        {
            key: 'access',
            label: t('nav.access'),
            entries: [
                {
                    name: 'admin-users',
                    label: t('admin.users'),
                    need: PERMISSION.usersManage,
                    icon: PhUsers,
                },
                {
                    name: 'admin-roles',
                    label: t('roles.title'),
                    need: PERMISSION.rolesManage,
                    icon: PhShieldCheck,
                },
                {
                    name: 'admin-audit',
                    label: t('audit.title'),
                    need: PERMISSION.auditRead,
                    icon: PhClockCounterClockwise,
                },
            ],
        },
        {
            key: 'organizations',
            label: t('organizations.title'),
            entries: [
                {
                    name: 'admin-organizations',
                    label: t('organizations.title'),
                    need: PERMISSION.organizationsManage,
                    icon: PhBank,
                },
            ],
        },
    ]
        .map((group) => ({ ...group, entries: group.entries.filter((entry) => can(entry.need)) }))
        .filter((group) => group.entries.length > 0),
)

const accountOpen = ref(false)
const mobileOpen = ref(false)

function toggleAccount(): void {
    accountOpen.value = !accountOpen.value
}

async function onSignOut(): Promise<void> {
    accountOpen.value = false
    await signOut(session.value?.refreshToken ?? null)
}

/**
 * Close on any click that is not inside an open menu.
 *
 * A menu left open behind the next click is one whose Sign out gets pressed by
 * somebody reaching for the table underneath it.
 */
function onDocumentClick(event: MouseEvent): void {
    const target = event.target

    if (target instanceof Element && target.closest('[data-menu]') !== null) {
        return
    }

    accountOpen.value = false
}

function onEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        accountOpen.value = false
        mobileOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick)
    document.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick)
    document.removeEventListener('keydown', onEscape)
})
</script>

<template>
    <div class="min-h-screen bg-ground">
        <!--
            Fixed rail, scrolling page. The rail does not move with the table
            beside it, which is the difference between a console and a page
            with links down the side. Not printed: a report is handed to a
            laboratory on paper and a navigation column is a strip of ink
            saying nothing to somebody holding a sheet.
        -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 w-[290px] border-r border-hairline bg-surface',
                'flex flex-col transition-transform duration-200 print:hidden',
                mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
        >
            <div class="flex h-[72px] shrink-0 items-center gap-2.5 px-6">
                <RouterLink
                    :to="{ name: 'home' }"
                    class="flex min-w-0 items-center gap-2.5"
                    @click="mobileOpen = false"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-card bg-accent text-accent-ink"
                    >
                        <PhChartBar :size="19" weight="bold" aria-hidden="true" />
                    </span>
                    <span class="truncate text-[20px] font-bold tracking-[-0.02em]">
                        {{ brand }}
                    </span>
                </RouterLink>
            </div>

            <nav class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
                <p class="eyebrow px-2 pb-2 pt-2 text-label-3">{{ t('nav.menu') }}</p>

                <template v-for="group in groups" :key="group.key">
                    <!--
                        A heading only where it names more than one screen.
                        "Reports" written above a single link called Reports is
                        a label for itself.
                    -->
                    <p
                        v-if="group.entries.length > 1"
                        class="px-2 pb-1.5 pt-4 text-[13px] font-semibold uppercase tracking-[0.06em] text-label-3"
                    >
                        {{ group.label }}
                    </p>

                    <RouterLink
                        v-for="entry in group.entries"
                        :key="entry.name"
                        :to="{ name: entry.name }"
                        class="mb-1 flex min-h-11 items-center gap-3 rounded-card px-3 text-[15.5px] font-medium text-label-2 transition-colors hover:bg-accent-soft hover:text-label"
                        active-class="bg-accent-soft !text-accent !font-semibold"
                        @click="mobileOpen = false"
                    >
                        <component :is="entry.icon" :size="19" class="shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate">{{ entry.label }}</span>
                    </RouterLink>
                </template>
            </nav>
        </aside>

        <!-- The scrim only exists while the rail is over the content. -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-label/40 lg:hidden"
            aria-hidden="true"
            @click="mobileOpen = false"
        ></div>

        <div class="lg:pl-[290px]">
            <header
                class="sticky top-0 z-20 flex h-[72px] items-center gap-3 border-b border-hairline bg-surface px-4 sm:px-6 print:hidden"
            >
                <button
                    type="button"
                    class="-ml-1 flex size-11 items-center justify-center rounded-card border border-hairline text-label-2 transition-colors hover:bg-accent-soft hover:text-accent lg:hidden"
                    :aria-label="t('nav.menu')"
                    :aria-expanded="mobileOpen"
                    @click="mobileOpen = !mobileOpen"
                >
                    <component :is="mobileOpen ? PhX : PhList" :size="20" />
                </button>

                <span class="flex-1"></span>

                <LocaleSwitcher />

                <div data-menu class="relative">
                    <button
                        type="button"
                        class="flex min-h-11 items-center gap-2.5 rounded-full py-1 pl-1 pr-2.5 text-[15px] font-medium text-label transition-colors hover:bg-accent-soft"
                        :aria-expanded="accountOpen"
                        @click="toggleAccount"
                    >
                        <!-- Initials, not a photograph. Nobody uploads one to
                             an assessment tool, and an empty circle where a
                             face should be reads as a broken image. -->
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-[14px] font-semibold text-accent-ink"
                            aria-hidden="true"
                        >
                            {{ initials }}
                        </span>
                        <span class="hidden max-w-[160px] truncate sm:inline">
                            {{ user?.fullName }}
                        </span>
                        <PhCaretDown :size="12" weight="bold" aria-hidden="true" />
                    </button>

                    <div
                        v-if="accountOpen"
                        class="absolute right-0 top-full z-40 mt-2 min-w-[240px] rounded-surface border border-hairline bg-surface p-2 shadow-surface"
                    >
                        <p class="truncate px-3 pb-2 pt-1.5 text-[13.5px] text-label-3">
                            {{ user?.email }}
                        </p>

                        <div class="mb-1 border-t border-hairline"></div>

                        <RouterLink
                            :to="{ name: 'account' }"
                            class="flex items-center gap-2.5 rounded-card px-3 py-2.5 text-[14px] text-label-2 hover:bg-accent-soft hover:text-accent"
                            active-class="font-medium text-accent"
                            @click="accountOpen = false"
                        >
                            <PhUserCircle :size="17" aria-hidden="true" />
                            {{ t('account.title') }}
                        </RouterLink>

                        <RouterLink
                            v-if="canOpenSettings"
                            :to="{ name: 'admin-settings' }"
                            class="flex items-center gap-2.5 rounded-card px-3 py-2.5 text-[15px] text-label-2 hover:bg-accent-soft hover:text-accent"
                            active-class="font-medium text-accent"
                            @click="accountOpen = false"
                        >
                            <PhGear :size="17" aria-hidden="true" />
                            {{ t('settings.title') }}
                        </RouterLink>

                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-card px-3 py-2.5 text-left text-[15px] text-label-2 hover:bg-no-soft hover:text-no"
                            @click="onSignOut"
                        >
                            <PhSignOut :size="17" aria-hidden="true" />
                            {{ t('admin.signOut') }}
                        </button>
                    </div>
                </div>
            </header>

            <main class="mx-auto max-w-[1536px] p-4 sm:p-6">
                <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p v-if="props.eyebrow" class="eyebrow mb-2 text-label-3">
                            {{ props.eyebrow }}
                        </p>
                        <h1 class="text-[28px] font-bold tracking-[-0.02em]">{{ props.title }}</h1>
                        <p v-if="props.subtitle" class="mt-1.5 text-[16px] text-label-2">
                            {{ props.subtitle }}
                        </p>
                    </div>

                    <!-- Where this screen sits, and the way back up. Two levels
                         because that is all there is: this console is a rail of
                         screens rather than a tree, and a trail that invents
                         depth is worse than none. -->
                    <nav
                        class="flex shrink-0 items-center gap-2 pt-1.5 text-[14px] text-label-3"
                        :aria-label="t('nav.breadcrumb')"
                    >
                        <RouterLink
                            :to="{ name: 'home' }"
                            class="transition-colors hover:text-accent"
                        >
                            {{ t('nav.home') }}
                        </RouterLink>
                        <PhCaretRight :size="12" weight="bold" aria-hidden="true" />
                        <span class="truncate font-medium text-label">{{ props.title }}</span>
                    </nav>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
