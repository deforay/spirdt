<script setup lang="ts">
import { ref, watch } from 'vue'

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

let timer: ReturnType<typeof setTimeout> | undefined

watch(term, () => {
    clearTimeout(timer)

    timer = setTimeout(async () => {
        if (term.value.trim() === '') {
            matches.value = []

            return
        }

        try {
            matches.value = (await listFacilities({ search: term.value, perPage: 8 })).rows
        } catch {
            matches.value = []
        }
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
