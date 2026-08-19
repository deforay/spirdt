<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { PhArrowRight } from '@phosphor-icons/vue'

import { cachedSites, fetchSites, type Site } from '@/api/sites'
import { formatDate, t } from '@/i18n'

/** An unfinished visit, as this screen needs to describe it. */
export interface DraftSummary {
    id: string
    siteName: string
    assessedOn: string
    answered: number
    total: number
    updatedAt: string
}

/**
 * Choose the site being assessed.
 *
 * Shows the cached list immediately and replaces it when the server answers, so
 * the screen is never empty while a request is timing out on a bad connection.
 *
 * Defaults to the sites assigned to this assessor, because a national registry
 * is hundreds of facilities and a flat list of them is unusable long before
 * that. Every other site stays one tap away: an assessor who arrives somewhere
 * unplanned must be able to work, and an administrative gap should not become a
 * wasted visit. Both lists come from the same cached payload, so the toggle
 * works with no signal.
 */

/**
 * Unfinished visits come first, above the site list.
 *
 * An assessment is written to the device as it is answered and survives a
 * refresh, a crash and a flat battery — that is the whole architecture. What it
 * did not survive was this screen, which offered no way back to it. Data that
 * is safe and unreachable is lost as far as the assessor is concerned, and the
 * obvious recovery is to start again and answer everything twice.
 *
 * Above the sites rather than beside them, because finishing a visit already
 * begun is almost always what somebody opening this app means to do.
 */
const props = defineProps<{ drafts?: DraftSummary[] }>()

const emit = defineEmits<{ chosen: [site: Site]; resume: [id: string] }>()

const drafts = computed(() => props.drafts ?? [])

const sites = ref<Site[]>(cachedSites())
const filter = ref('')
const loading = ref(false)
const showAll = ref(false)

onMounted(async () => {
    loading.value = true
    sites.value = await fetchSites()
    loading.value = false
})

const mine = computed(() => sites.value.filter((site) => site.assigned_to_me))

/**
 * Nothing assigned is not the same as nothing to do.
 *
 * Until somebody has planned a round, no site is assigned to anyone — and an
 * empty list with a "show all" link reads as a broken app. So with no
 * assignments at all, everything is shown and the toggle stays out of the way.
 */
const hasAssignments = computed(() => mine.value.length > 0)

const shown = computed(() => {
    const base = showAll.value || !hasAssignments.value ? sites.value : mine.value
    const needle = filter.value.trim().toLowerCase()

    if (needle === '') {
        return base
    }

    return base.filter(
        (site) =>
            site.name.toLowerCase().includes(needle) ||
            (site.facility_name ?? '').toLowerCase().includes(needle),
    )
})

const hiddenCount = computed(() => sites.value.length - mine.value.length)
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full flex-col bg-ground sm:max-w-[720px] sm:px-4">
        <header class="flex items-start justify-between gap-3 px-4 pb-5 pt-5 sm:px-0 sm:pt-8">
            <div>
                <h1 class="text-[30px] font-bold tracking-[-0.02em]">{{ t('sites.title') }}</h1>
                <p class="mt-1.5 text-[15px] text-label-2">{{ t('sites.subtitle') }}</p>
            </div>
        </header>

        <!-- Unfinished visits, above everything. Somebody opening this app
             usually means to finish one, not to start another. Each is its own
             card rather than a row in a list: there are rarely more than two,
             and the whole screen exists to make resuming one the easy move. -->
        <section v-if="drafts.length > 0" class="px-4 pb-6 sm:px-0">
            <h2 class="eyebrow pb-2.5 text-label-3">{{ t('sites.unfinished') }}</h2>

            <div class="flex flex-col gap-3">
                <button
                    v-for="draft in drafts"
                    :key="draft.id"
                    type="button"
                    class="flex w-full items-center gap-4 rounded-surface border border-hairline bg-surface p-4 text-left transition-colors hover:border-accent"
                    @click="emit('resume', draft.id)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[17px] font-semibold">
                            {{ draft.siteName }}
                        </span>
                        <span class="tnum mt-0.5 block text-[14px] text-label-2">
                            {{ formatDate(draft.assessedOn) }} ·
                            {{
                                t('sites.draftProgress', {
                                    answered: draft.answered,
                                    total: draft.total,
                                })
                            }}
                        </span>

                        <!-- How far in, drawn. A fraction says how much is
                             done; a bar says how much is left, which is what
                             somebody deciding whether to pick this one up
                             again is actually asking. -->
                        <span
                            aria-hidden="true"
                            class="mt-2.5 block h-1.5 w-full overflow-hidden rounded-full bg-track"
                        >
                            <span
                                class="block h-full rounded-full bg-accent"
                                :style="{
                                    width:
                                        draft.total === 0
                                            ? '0%'
                                            : `${Math.min(100, Math.round((draft.answered / draft.total) * 100))}%`,
                                }"
                            ></span>
                        </span>
                    </span>

                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent"
                    >
                        <PhArrowRight :size="17" weight="bold" aria-hidden="true" />
                    </span>
                </button>
            </div>
        </section>

        <div class="px-4 pb-3 sm:px-0">
            <h2 v-if="drafts.length > 0" class="eyebrow pb-2.5 text-label-3">
                {{ t('sites.startNew') }}
            </h2>
            <input
                v-model="filter"
                type="search"
                class="field"
                :placeholder="t('sites.search')"
            />
        </div>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 sm:px-0">
            <div
                v-if="shown.length > 0"
                class="overflow-hidden rounded-surface border border-hairline bg-surface"
            >
                <button
                    v-for="(site, index) in shown"
                    :key="site.id"
                    type="button"
                    class="flex w-full flex-col items-start gap-0.5 px-4 py-3.5 text-left transition-colors hover:bg-surface-2"
                    :class="index > 0 ? 'border-t border-hairline' : ''"
                    @click="emit('chosen', site)"
                >
                    <span class="text-[17px] font-medium">{{ site.name }}</span>
                    <span v-if="site.facility_name" class="text-[14px] text-label-2">
                        {{ site.facility_name }}
                    </span>
                    <span
                        v-if="showAll && hasAssignments && !site.assigned_to_me"
                        class="chip mt-1.5 bg-track text-label-2"
                    >
                        {{ site.assigned ? t('sites.assignedToColleague') : t('sites.unassigned') }}
                    </span>
                </button>
            </div>

            <button
                v-if="hasAssignments && hiddenCount > 0"
                type="button"
                class="mt-4 min-h-11 w-full rounded-card border border-hairline text-center text-[15px] font-medium text-accent transition-colors hover:bg-accent-soft"
                @click="showAll = !showAll"
            >
                {{ showAll ? t('sites.showMine') : t('sites.showAll', { count: hiddenCount }) }}
            </button>

            <p v-else-if="loading" class="pt-3 text-[16px] text-label-2">
                {{ t('sites.loading') }}
            </p>

            <p v-else class="pt-3 text-[16px] text-label-2">
                {{ t('sites.empty') }}
            </p>
        </main>
    </div>
</template>
