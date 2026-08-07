<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { type Facility, listFacilities } from '@/api/registry'
import { t } from '@/i18n'

/**
 * Choose a facility by typing its name.
 *
 * A select is impossible here: a country runs to thousands of facilities, so
 * the list is never fetched whole. Each search is a bounded, debounced request
 * and the results carry their place, because facility names repeat between
 * districts and the name alone does not identify one.
 */

const props = defineProps<{ modelValue: string; label?: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: string]; 'update:label': [value: string] }>()

const term = ref('')
const matches = ref<Facility[]>([])
const open = ref(false)

/**
 * Whether the registry holds any facility at all.
 *
 * A search box that finds nothing looks identical whether the answer is "no
 * facility called that" or "no facilities". The second is the ordinary state
 * of a newly provisioned organisation — its programme starts empty — and the
 * first person to meet it reported the screen as broken rather than as a step
 * they had not taken yet.
 *
 * Null until asked, so nothing is claimed before the answer is known.
 */
const anyExist = ref<boolean | null>(null)

/** A search has run and come back. Distinguishes no matches from not looked. */
const searched = ref(false)

onMounted(async () => {
    try {
        anyExist.value = (await listFacilities({ perPage: 1 })).rows.length > 0
    } catch {
        // Unreachable server. Saying "there are no facilities" would be a
        // claim about the registry made from a failed request.
        anyExist.value = null
    }
})

let timer: ReturnType<typeof setTimeout> | undefined

watch(term, () => {
    clearTimeout(timer)

    timer = setTimeout(async () => {
        if (term.value.trim() === '') {
            matches.value = []
            searched.value = false

            return
        }

        try {
            matches.value = (await listFacilities({ search: term.value, perPage: 8 })).rows
        } catch {
            matches.value = []
        }

        searched.value = true
    }, 250)
})

function choose(facility: Facility): void {
    emit('update:modelValue', facility.id)
    emit('update:label', facility.name)
    term.value = ''
    matches.value = []
    open.value = false
}
</script>

<template>
    <div class="relative">
        <div
            v-if="modelValue !== '' && !open"
            class="flex items-center gap-2 rounded-lg bg-ground px-3 py-2"
        >
            <span class="flex-1 truncate text-[15px]">{{ label ?? modelValue }}</span>
            <button type="button" class="text-[13px] text-accent" @click="open = true">
                {{ t('registry.change') }}
            </button>
        </div>

        <input
            v-else
            v-model="term"
            type="search"
            :placeholder="t('sitesAdmin.findFacility')"
            class="w-full rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3"
        />

        <!--
            The registry is empty, which is where a new organisation starts.
            Said as a step rather than as an absence, and with the way to take
            it, because an empty search box explains nothing on its own.
        -->
        <RouterLink
            v-if="anyExist === false"
            :to="{ name: 'admin-facilities' }"
            class="mt-1 block text-[13px] text-accent"
        >
            {{ t('sitesAdmin.noFacilitiesYet') }}
        </RouterLink>

        <p
            v-else-if="searched && matches.length === 0"
            class="mt-1 text-[13px] text-label-2"
        >
            {{ t('sitesAdmin.noFacilityMatch') }}
        </p>

        <div
            v-if="matches.length > 0"
            class="absolute z-20 mt-1 max-h-[240px] w-full overflow-y-auto rounded-card border border-hairline bg-surface shadow-lg"
        >
            <button
                v-for="facility in matches"
                :key="facility.id"
                type="button"
                class="flex w-full flex-col items-start px-3.5 py-2 text-left hover:bg-ground"
                @click="choose(facility)"
            >
                <span class="text-[15px]">{{ facility.name }}</span>
                <span class="text-[12px] text-label-2">{{ facility.place }}</span>
            </button>
        </div>
    </div>
</template>
