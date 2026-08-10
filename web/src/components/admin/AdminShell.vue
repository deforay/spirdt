<script setup lang="ts">
import { PhCaretDown, PhList, PhSignOut, PhX } from '@phosphor-icons/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

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
 * THE NAVIGATION IS GROUPED, and it had to become so. Every capability added a
 * link, and at ten the bar wrapped mid-word — "Testing sites" broken across a
 * line break is not a navigation, it is a list that has outgrown its
 * container. The registry and who-may-do-what are each one idea with several
 * screens, so each is one item with a menu behind it.
 *
 * The current item is marked with a rule on the header's own bottom edge
 * rather than a filled pill. A pill competes with the buttons on the page
 * below it; a rule belongs to the chrome, and reads as position rather than as
 * something to press.
 *
 * The navigation lists only what this account can DO. Each entry names the
 * same permission its route does, so a link never leads somewhere that
 * refuses, and a group whose screens are all closed disappears rather than
 * opening onto an empty menu.
 */

const props = defineProps<{ title: string; subtitle?: string; eyebrow?: string }>()

const route = useRoute()
const user = computed(() => session.value?.user ?? null)

interface Entry {
    name: string
    label: string
    need: PermissionKey
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
            key: 'dashboard',
            label: t('dash.title'),
            entries: [
                { name: 'admin-dashboard', label: t('dash.title'), need: PERMISSION.reportsRead },
            ],
        },
        {
            key: 'reports',
            label: t('reports.title'),
            entries: [
                { name: 'admin-reports', label: t('reports.title'), need: PERMISSION.reportsRead },
            ],
        },
        {
            key: 'registry',
            label: t('nav.registry'),
            entries: [
                { name: 'admin-places', label: t('places.title'), need: PERMISSION.registryRead },
                {
                    name: 'admin-facilities',
                    label: t('facilities.title'),
                    need: PERMISSION.registryRead,
                },
                { name: 'admin-sites', label: t('sitesAdmin.title'), need: PERMISSION.registryRead },
                {
                    name: 'admin-assignments',
                    label: t('assignments.title'),
                    need: PERMISSION.registryRead,
                },
            ],
        },
        {
            key: 'access',
            label: t('nav.access'),
            entries: [
                { name: 'admin-users', label: t('admin.users'), need: PERMISSION.usersManage },
                { name: 'admin-roles', label: t('roles.title'), need: PERMISSION.rolesManage },
                { name: 'admin-audit', label: t('audit.title'), need: PERMISSION.auditRead },
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
                },
            ],
        },
    ]
        .map((group) => ({ ...group, entries: group.entries.filter((entry) => can(entry.need)) }))
        .filter((group) => group.entries.length > 0),
)

/**
 * Which group the current screen belongs to.
 *
 * Matched on a prefix so a form counts as its list — editing a facility is
 * still the registry, and losing the marker on the way into a form is how
 * somebody loses track of where they are.
 */
const activeGroup = computed(() => {
    const current = String(route.name ?? '')

    return (
        groups.value.find((group) =>
            group.entries.some((entry) => current.startsWith(entry.name)),
        )?.key ?? ''
    )
})

const openGroup = ref('')
const accountOpen = ref(false)
const mobileOpen = ref(false)

function toggleGroup(key: string): void {
    openGroup.value = openGroup.value === key ? '' : key
    accountOpen.value = false
}

function toggleAccount(): void {
    accountOpen.value = !accountOpen.value
    openGroup.value = ''
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

    openGroup.value = ''
    accountOpen.value = false
}

function onEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        openGroup.value = ''
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
        <header class="sticky top-0 z-30 border-b border-hairline bg-surface/85 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-[1200px] items-center gap-7 px-4 sm:px-6 lg:px-8">
                <RouterLink
                    :to="{ name: 'home' }"
                    class="shrink-0 whitespace-nowrap text-[17px] font-bold tracking-[-0.02em]"
                >
                    SPI-RDT
                </RouterLink>

                <nav class="hidden flex-1 items-center gap-7 self-stretch md:flex">
                    <template v-for="group in groups" :key="group.key">
                        <RouterLink
                            v-if="group.entries.length === 1"
                            :to="{ name: group.entries[0]!.name }"
                            class="relative inline-flex h-full items-center whitespace-nowrap text-[14px]"
                            :class="
                                activeGroup === group.key
                                    ? 'font-semibold text-label'
                                    : 'font-medium text-label-2 hover:text-label'
                            "
                        >
                            {{ group.label }}
                            <span
                                v-if="activeGroup === group.key"
                                class="absolute inset-x-0 -bottom-px h-[2.5px] rounded-t bg-accent"
                            ></span>
                        </RouterLink>

                        <div v-else data-menu class="relative inline-flex h-full items-center">
                            <button
                                type="button"
                                class="relative inline-flex h-full items-center gap-1 whitespace-nowrap text-[14px]"
                                :class="
                                    activeGroup === group.key
                                        ? 'font-semibold text-label'
                                        : 'font-medium text-label-2 hover:text-label'
                                "
                                :aria-expanded="openGroup === group.key"
                                @click="toggleGroup(group.key)"
                            >
                                {{ group.label }}
                                <PhCaretDown :size="11" weight="bold" aria-hidden="true" />
                                <span
                                    v-if="activeGroup === group.key"
                                    class="absolute inset-x-0 -bottom-px h-[2.5px] rounded-t bg-accent"
                                ></span>
                            </button>

                            <div
                                v-if="openGroup === group.key"
                                class="absolute left-0 top-full z-40 mt-1.5 min-w-[210px] rounded-surface border border-hairline bg-surface p-1.5 shadow-surface"
                            >
                                <RouterLink
                                    v-for="entry in group.entries"
                                    :key="entry.name"
                                    :to="{ name: entry.name }"
                                    class="block rounded-card px-3 py-2 text-[14px] text-label-2 hover:bg-accent-soft hover:text-accent"
                                    active-class="font-medium text-accent"
                                    @click="openGroup = ''"
                                >
                                    {{ entry.label }}
                                </RouterLink>
                            </div>
                        </div>
                    </template>
                </nav>

                <div class="flex flex-1 items-center justify-end gap-1.5 md:flex-none">
                    <LocaleSwitcher />

                    <div data-menu class="relative">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-medium text-label-2 hover:bg-accent-soft hover:text-accent"
                            :aria-expanded="accountOpen"
                            @click="toggleAccount"
                        >
                            <span class="hidden max-w-[150px] truncate sm:inline">
                                {{ user?.fullName }}
                            </span>
                            <PhCaretDown :size="11" weight="bold" aria-hidden="true" />
                        </button>

                        <div
                            v-if="accountOpen"
                            class="absolute right-0 top-full z-40 mt-1.5 min-w-[230px] rounded-surface border border-hairline bg-surface p-1.5 shadow-surface"
                        >
                            <p class="truncate px-3 pb-2 pt-1.5 text-[12px] text-label-3">
                                {{ user?.email }}
                            </p>
                            <div class="mb-1 border-t border-hairline"></div>

                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-card px-3 py-2 text-left text-[14px] text-label-2 hover:bg-no-soft hover:text-no"
                                @click="onSignOut"
                            >
                                <PhSignOut :size="16" aria-hidden="true" />
                                {{ t('admin.signOut') }}
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="-mr-2 p-2 text-label-2 md:hidden"
                        :aria-label="t('nav.menu')"
                        @click="mobileOpen = !mobileOpen"
                    >
                        <component :is="mobileOpen ? PhX : PhList" :size="22" />
                    </button>
                </div>
            </div>

            <!-- Flat on a phone. A menu inside a menu is two taps to reach a
                 list that fits on the screen as it is. -->
            <nav v-if="mobileOpen" class="border-t border-hairline bg-surface px-4 pb-3 md:hidden">
                <template v-for="group in groups" :key="group.key">
                    <p v-if="group.entries.length > 1" class="eyebrow px-2 pb-1 pt-3 text-label-3">
                        {{ group.label }}
                    </p>
                    <RouterLink
                        v-for="entry in group.entries"
                        :key="entry.name"
                        :to="{ name: entry.name }"
                        class="block rounded-card px-2 py-2.5 text-[15px] text-label-2"
                        active-class="font-medium text-accent"
                        @click="mobileOpen = false"
                    >
                        {{ entry.label }}
                    </RouterLink>
                </template>
            </nav>
        </header>

        <main class="mx-auto max-w-[1200px] px-4 py-9 sm:px-6 lg:px-8">
            <div class="mb-7">
                <p v-if="props.eyebrow" class="eyebrow mb-2 text-label-3">{{ props.eyebrow }}</p>
                <h1 class="text-[30px] font-bold tracking-[-0.02em]">{{ props.title }}</h1>
                <p v-if="props.subtitle" class="mt-1.5 text-[15px] text-label-2">
                    {{ props.subtitle }}
                </p>
            </div>

            <slot />
        </main>
    </div>
</template>
