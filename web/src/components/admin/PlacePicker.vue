<script setup lang="ts">
import { computed, ref } from 'vue'

import type { GeoTree } from '@/api/registry'
import { t } from '@/i18n'

/**
 * Choose a place by typing its name.
 *
 * REPLACES A CASCADE OF SELECTS, and the reason is scale. Twenty provinces of
 * thirty districts is six hundred places: a cascade makes finding one of them
 * two guesses in a fixed order, and it only works if you already know which
 * province a district is in. Somebody looking for Kitwe types "kit".
 *
 * Matching is on the full path, so "copper kit" finds Kitwe in Copperbelt and
 * a district whose name you half-remember is still reachable by its parent.
 * Every result shows its whole path, because district names repeat across
 * provinces and the name alone does not identify one.
 */

const props = defineProps<{
    tree: GeoTree
    modelValue: number | null
    /** Shown when nothing is chosen. */
    placeholder?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()

const term = ref('')
const open = ref(false)

const chosen = computed(() =>
    props.modelValue === null ? null : (props.tree.paths[props.modelValue] ?? null),
)

/**
 * Every word has to appear somewhere in the path, in any order.
 *
 * A single substring match would fail on "kitwe copperbelt" — the words are
 * there, in the other order, which is exactly how somebody types a place they
 * half-remember.
 */
const matches = computed(() => {
    const words = term.value.trim().toLowerCase().split(/\s+/).filter(Boolean)

    const active = props.tree.units.filter((unit) => unit.is_active)

    if (words.length === 0) {
        // Not everything: the top of the tree, which is where somebody with no
        // search term is most likely to want to start.
        return active.filter((unit) => unit.parent_id === null).slice(0, 20)
    }

    return active
        .filter((unit) => {
            const haystack = (props.tree.paths[unit.id] ?? unit.name).toLowerCase()

            return words.every((word) => haystack.includes(word))
        })
        .slice(0, 20)
})

function choose(id: number | null): void {
    emit('update:modelValue', id)
    term.value = ''
    open.value = false
}
</script>

<template>
    <div class="relative">
        <div
            v-if="chosen !== null && !open"
            class="field flex items-center gap-2"
        >
            <span class="flex-1 truncate text-[16px]">{{ chosen }}</span>
            <button type="button" class="text-[14px] text-accent" @click="open = true">
                {{ t('registry.change') }}
            </button>
            <button type="button" class="text-[14px] text-label-2" @click="choose(null)">
                {{ t('registry.clear') }}
            </button>
        </div>

        <input
            v-else
            v-model="term"
            type="search"
            autocapitalize="off"
            spellcheck="false"
            :placeholder="placeholder ?? t('registry.searchPlaces')"
            class="field"
            @focus="open = true"
        />

        <!-- Blur is deliberately delayed: a click on a result fires after blur,
             and closing immediately would swallow the selection. -->
        <div
            v-if="open"
            class="absolute z-20 mt-1 max-h-[280px] w-full overflow-y-auto rounded-card border border-hairline bg-surface shadow-lg"
        >
            <p v-if="matches.length === 0" class="px-3.5 py-2.5 text-[15px] text-label-2">
                {{ t('registry.noPlacesFound') }}
            </p>
            <button
                v-for="unit in matches"
                :key="unit.id"
                type="button"
                class="flex w-full flex-col items-start px-3.5 py-2 text-left hover:bg-ground"
                @click="choose(unit.id)"
            >
                <span class="text-[16px]">{{ unit.name }}</span>
                <span class="text-[13px] text-label-2">
                    {{ tree.paths[unit.id] }} · {{ unit.level }}
                </span>
            </button>

            <button
                type="button"
                class="w-full border-t border-hairline px-3.5 py-2 text-left text-[14px] text-label-2 hover:bg-ground"
                @click="open = false"
            >
                {{ t('action.cancel') }}
            </button>
        </div>
    </div>
</template>
