<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { cachedSites, fetchSites, type Site } from '@/api/sites'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { t } from '@/i18n'

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

const emit = defineEmits<{ chosen: [site: Site] }>()

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
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground sm:max-w-[620px] sm:px-4">
        <header class="flex items-start justify-between gap-3 px-4 pb-3 pt-4 sm:px-0 sm:pt-6">
            <div>
                <h1 class="text-[30px] font-bold tracking-tight">{{ t('sites.title') }}</h1>
                <p class="mt-0.5 text-[13px] text-label-2">{{ t('sites.subtitle') }}</p>
            </div>
            <div class="mt-1.5"><LocaleSwitcher /></div>
        </header>

        <div class="px-4 pb-3 sm:px-0">
            <input
                v-model="filter"
                type="search"
                class="w-full rounded-card bg-surface px-3.5 py-2.5 text-[17px] outline-none placeholder:text-label-3"
                :placeholder="t('sites.search')"
            />
        </div>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6 sm:px-0">
            <div v-if="shown.length > 0" class="overflow-hidden rounded-card bg-surface sm:rounded-surface sm:shadow-surface">
                <button
                    v-for="(site, index) in shown"
                    :key="site.id"
                    type="button"
                    class="flex w-full flex-col items-start gap-0.5 px-3.5 py-3 text-left"
                    :class="index > 0 ? 'border-t border-hairline' : ''"
                    @click="emit('chosen', site)"
                >
                    <span class="text-[17px]">{{ site.name }}</span>
                    <span v-if="site.facility_name" class="text-[13px] text-label-2">
                        {{ site.facility_name }}
                    </span>
                    <span
                        v-if="showAll && hasAssignments && !site.assigned_to_me"
                        class="text-[12px] text-label-3"
                    >
                        {{ site.assigned ? t('sites.assignedToColleague') : t('sites.unassigned') }}
                    </span>
                </button>
            </div>

            <button
                v-if="hasAssignments && hiddenCount > 0"
                type="button"
                class="mt-3 w-full py-2 text-center text-[15px] text-accent"
                @click="showAll = !showAll"
            >
                {{ showAll ? t('sites.showMine') : t('sites.showAll', { count: hiddenCount }) }}
            </button>

            <p v-else-if="loading" class="px-1 pt-2 text-[15px] text-label-2">
                {{ t('sites.loading') }}
            </p>

            <p v-else class="px-1 pt-2 text-[15px] text-label-2">
                {{ t('sites.empty') }}
                <code class="text-label">bin/dev/seed-sites</code>
            </p>
        </main>
    </div>
</template>
