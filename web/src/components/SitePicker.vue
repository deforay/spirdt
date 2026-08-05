<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { cachedSites, fetchSites, type Site } from '@/api/sites'

/**
 * Choose the site being assessed.
 *
 * Shows the cached list immediately and replaces it when the server answers, so
 * the screen is never empty while a request is timing out on a bad connection.
 */

const emit = defineEmits<{ chosen: [site: Site] }>()

const sites = ref<Site[]>(cachedSites())
const filter = ref('')
const loading = ref(false)

onMounted(async () => {
    loading.value = true
    sites.value = await fetchSites()
    loading.value = false
})

const shown = computed(() => {
    const needle = filter.value.trim().toLowerCase()

    if (needle === '') {
        return sites.value
    }

    return sites.value.filter(
        (site) =>
            site.name.toLowerCase().includes(needle) ||
            (site.facility_name ?? '').toLowerCase().includes(needle),
    )
})
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground">
        <header class="px-4 pb-3 pt-4">
            <h1 class="text-[30px] font-bold tracking-tight">Testing sites</h1>
            <p class="mt-0.5 text-[13px] text-label-2">Choose the site you are assessing.</p>
        </header>

        <div class="px-4 pb-3">
            <input
                v-model="filter"
                type="search"
                class="w-full rounded-card bg-surface px-3.5 py-2.5 text-[17px] outline-none placeholder:text-label-3"
                placeholder="Search"
            />
        </div>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
            <div v-if="shown.length > 0" class="overflow-hidden rounded-card bg-surface">
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
                </button>
            </div>

            <p v-else-if="loading" class="px-1 pt-2 text-[15px] text-label-2">Loading sites.</p>

            <p v-else class="px-1 pt-2 text-[15px] text-label-2">
                No sites yet. An administrator adds them, or seed some locally with
                <code class="text-label">bin/dev/seed-sites</code>.
            </p>
        </main>
    </div>
</template>
