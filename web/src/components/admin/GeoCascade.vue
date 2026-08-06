<script setup lang="ts">
import { computed } from 'vue'

import type { GeoUnit } from '@/api/registry'
import { t } from '@/i18n'

/**
 * Narrow down through the geographic hierarchy, however deep it happens to be.
 *
 * DELIBERATELY NOT TWO DROPDOWNS. `geo_units` is an arbitrary-depth tree with a
 * free-text `level`, because the tiering is a per-country fact — Zambia is
 * Province → District, Ethiopia is Region → Zone → Woreda, some programmes run
 * four. A component with a Province select and a District select works for
 * exactly one country and is rewritten for the second.
 *
 * So the chain is derived: start at the roots, and every time a selection has
 * children, offer another select. Each one is labelled from the `level` of the
 * options it is showing, so the words on screen come from the data rather than
 * from a constant here.
 *
 * The value is the DEEPEST selection, which is what a filter wants. Choosing a
 * province and stopping is a legitimate answer — it means the whole province.
 */

const props = defineProps<{ units: GeoUnit[]; modelValue: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()

const byParent = computed(() => {
    const map = new Map<number | null, GeoUnit[]>()

    for (const unit of props.units) {
        if (!unit.is_active) {
            continue
        }

        const siblings = map.get(unit.parent_id) ?? []
        siblings.push(unit)
        map.set(unit.parent_id, siblings)
    }

    return map
})

const byId = computed(() => new Map(props.units.map((unit) => [unit.id, unit])))

/** From the selection back up to a root, so each ancestor's select can show its choice. */
const chain = computed(() => {
    const path: GeoUnit[] = []
    let current = props.modelValue === null ? undefined : byId.value.get(props.modelValue)

    while (current !== undefined) {
        path.unshift(current)
        current = current.parent_id === null ? undefined : byId.value.get(current.parent_id)
    }

    return path
})

/**
 * One entry per select to render: the options, and which of them is chosen.
 *
 * The trailing entry is the children of the deepest selection — that is what
 * makes the next level appear as soon as there is one to appear.
 */
const levels = computed(() => {
    const rendered: Array<{ options: GeoUnit[]; selected: number | null }> = []
    let parent: number | null = null

    for (const step of chain.value) {
        rendered.push({ options: byParent.value.get(parent) ?? [], selected: step.id })
        parent = step.id
    }

    const next = byParent.value.get(parent) ?? []

    if (next.length > 0) {
        rendered.push({ options: next, selected: null })
    }

    return rendered
})

/**
 * Selecting at depth N discards everything below it.
 *
 * Keeping the tail would leave a district selected under a different province —
 * visible, wrong, and the sort of thing nobody notices until a report is empty.
 */
function choose(depth: number, value: string): void {
    if (value === '') {
        const parent = chain.value[depth - 1]

        emit('update:modelValue', parent === undefined ? null : parent.id)

        return
    }

    emit('update:modelValue', Number(value))
}

/** The level name of whatever this select is offering. */
function labelFor(options: GeoUnit[]): string {
    return options[0]?.level ?? t('registry.place')
}
</script>

<template>
    <div class="flex flex-wrap items-end gap-2">
        <label v-for="(level, depth) in levels" :key="depth" class="flex flex-col gap-1">
            <span class="text-[12px] uppercase tracking-wide text-label-2">
                {{ labelFor(level.options) }}
            </span>
            <select
                :value="level.selected ?? ''"
                class="min-w-[150px] rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
                @change="choose(depth, ($event.target as HTMLSelectElement).value)"
            >
                <option value="">{{ t('registry.all') }}</option>
                <option v-for="unit in level.options" :key="unit.id" :value="unit.id">
                    {{ unit.name }}
                </option>
            </select>
        </label>

        <button
            v-if="modelValue !== null"
            type="button"
            class="py-2 text-[14px] text-accent"
            @click="emit('update:modelValue', null)"
        >
            {{ t('registry.clear') }}
        </button>
    </div>
</template>
