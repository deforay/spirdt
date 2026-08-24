<script setup lang="ts">
import { PhCaretDown, PhMagnifyingGlass, PhMapPin, PhPlus } from '@phosphor-icons/vue'
import { computed, nextTick, ref, useTemplateRef } from 'vue'

import { createGeoUnit, type GeoTree, type GeoUnit } from '@/api/registry'
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
 *
 * IT HAS TO LOOK LIKE A PICKER. A bare box with "Search places" written in it
 * is a text field to everybody who has not been told otherwise — it was read
 * as one on the facility form, where the two boxes above it really are text
 * fields. The pin says what kind of value belongs here and the caret says a
 * list opens, which is the pair of claims a native select makes for free.
 */

const props = defineProps<{
    tree: GeoTree
    modelValue: number | null
    /** Shown when nothing is chosen. */
    placeholder?: string
    /**
     * Offer to add a place from inside the picker.
     *
     * Off by default: a filter above a table has no business creating
     * registry rows, and most of these are filters. On a form it saves
     * abandoning a half-typed facility to go and add the district it sits in.
     */
    canCreate?: boolean
}>()

const emit = defineEmits<{
    'update:modelValue': [value: number | null]
    /** A place was added here. Whoever owns the tree should reload it. */
    created: [unit: GeoUnit]
}>()

const term = ref('')
const open = ref(false)
const search = useTemplateRef<HTMLInputElement>('search')

/**
 * Places added here, until the screen that owns the tree reloads it.
 *
 * The picker cannot show what it has just created otherwise — the tree is a
 * prop, and a consumer that ignores `created` would leave the field looking
 * empty after a successful save.
 */
const added = ref<GeoUnit[]>([])
const addedPaths = ref<Record<number, string>>({})

const units = computed(() => [
    ...props.tree.units,
    ...added.value.filter((unit) => !props.tree.units.some((known) => known.id === unit.id)),
])

const paths = computed(() => ({ ...props.tree.paths, ...addedPaths.value }))

const chosen = computed(() =>
    props.modelValue === null ? null : (paths.value[props.modelValue] ?? null),
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

    const active = units.value.filter((unit) => unit.is_active)

    if (words.length === 0) {
        // Not everything: the top of the tree, which is where somebody with no
        // search term is most likely to want to start.
        return active.filter((unit) => unit.parent_id === null).slice(0, 20)
    }

    return active
        .filter((unit) => {
            const haystack = (paths.value[unit.id] ?? unit.name).toLowerCase()

            return words.every((word) => haystack.includes(word))
        })
        .slice(0, 20)
})

function choose(id: number | null): void {
    emit('update:modelValue', id)
    term.value = ''
    open.value = false
}

async function reveal(): Promise<void> {
    open.value = true
    await nextTick()
    search.value?.focus()
}

/**
 * Adding a place without leaving the form.
 *
 * The whole panel is three fields because a place is three facts: what it is
 * called, what tier it belongs to, and what it sits inside. The parent is
 * chosen with another one of these, which is why `canCreate` is not passed
 * down — one level of adding at a time is enough, and a picker that could open
 * itself for ever is a picker somebody gets lost in.
 */
const adding = ref(false)
const saving = ref(false)
const error = ref('')
const draftName = ref('')
const draftLevel = ref('')
const draftParent = ref<number | null>(null)

/** Whatever the siblings under this parent are called — "District", usually. */
const suggestedLevel = computed(
    () => units.value.find((unit) => unit.parent_id === draftParent.value)?.level ?? '',
)

function startAdding(): void {
    // What was typed is almost always the name being looked for and not found.
    draftName.value = term.value.trim()
    draftLevel.value = ''
    draftParent.value = props.modelValue
    error.value = ''
    adding.value = true
}

async function onAdd(): Promise<void> {
    const name = draftName.value.trim()
    const level = draftLevel.value.trim() || suggestedLevel.value

    if (name === '' || level === '') {
        error.value = t('placePicker.required')

        return
    }

    saving.value = true
    error.value = ''

    try {
        const unit = await createGeoUnit({ name, level, parent_id: draftParent.value })
        const above = draftParent.value === null ? '' : (paths.value[draftParent.value] ?? '')

        added.value = [...added.value, unit]
        addedPaths.value = {
            ...addedPaths.value,
            [unit.id]: above === '' ? unit.name : `${above} › ${unit.name}`,
        }

        emit('created', unit)
        adding.value = false
        choose(unit.id)
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')
    }

    saving.value = false
}
</script>

<template>
    <div class="relative">
        <div v-if="chosen !== null && !open" class="field flex items-center gap-2">
            <PhMapPin :size="17" class="shrink-0 text-label-3" aria-hidden="true" />
            <span class="flex-1 truncate text-[15px]">{{ chosen }}</span>
            <button type="button" class="shrink-0 text-[14px] text-accent" @click="reveal">
                {{ t('registry.change') }}
            </button>
            <button type="button" class="shrink-0 text-[14px] text-label-2" @click="choose(null)">
                {{ t('registry.clear') }}
            </button>
        </div>

        <!--
            The affordance, in three parts: a magnifier saying this box is
            searched rather than typed into, a caret saying a list opens below
            it, and a whole row that is clickable so the caret is not the only
            way in.
        -->
        <div
            v-else
            class="field flex cursor-text items-center gap-2"
            @click="reveal"
        >
            <PhMagnifyingGlass :size="17" class="shrink-0 text-label-3" aria-hidden="true" />
            <input
                ref="search"
                v-model="term"
                type="search"
                role="combobox"
                autocapitalize="off"
                spellcheck="false"
                :aria-expanded="open"
                :placeholder="placeholder ?? t('registry.searchPlaces')"
                class="min-w-0 flex-1 bg-transparent text-[15px] outline-none placeholder:text-label-3"
                @focus="open = true"
            />
            <PhCaretDown :size="14" class="shrink-0 text-label-3" aria-hidden="true" />
        </div>

        <div
            v-if="open"
            class="absolute z-20 mt-1 w-full overflow-hidden rounded-card border border-hairline bg-surface shadow-lg"
        >
            <!-- Adding takes over the list rather than sitting under it: it is
                 a different question, and answering two at once in a 280px
                 panel is how a dropdown becomes a scroll inside a scroll. -->
            <div v-if="adding" class="p-3">
                <p class="mb-2 text-[14px] font-semibold">{{ t('placePicker.addTitle') }}</p>

                <p v-if="error !== ''" class="mb-2 text-[13px] font-medium text-no">{{ error }}</p>

                <label class="mb-2 block">
                    <span class="mb-1 block text-[13px] text-label-2">{{ t('placeForm.name') }}</span>
                    <input v-model="draftName" type="text" class="field" />
                </label>

                <label class="mb-2 block">
                    <span class="mb-1 block text-[13px] text-label-2">{{ t('placeForm.level') }}</span>
                    <input
                        v-model="draftLevel"
                        type="text"
                        :placeholder="suggestedLevel || t('placePicker.levelExample')"
                        class="field"
                    />
                </label>

                <!-- A div rather than a label: this control is itself a box
                     with buttons in it, and a label would hand every click
                     inside it to the search field. -->
                <div class="mb-3">
                    <span class="mb-1 block text-[13px] text-label-2">{{ t('placePicker.inside') }}</span>
                    <PlacePicker
                        :model-value="draftParent"
                        :tree="tree"
                        :placeholder="t('placeForm.topLevel')"
                        @update:model-value="draftParent = $event"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="min-h-9 rounded-card bg-accent px-4 text-[14px] font-semibold text-accent-ink disabled:opacity-40"
                        :disabled="saving"
                        @click="onAdd"
                    >
                        {{ saving ? t('form.saving') : t('placePicker.addAction') }}
                    </button>
                    <button
                        type="button"
                        class="text-[14px] text-label-2"
                        @click="adding = false"
                    >
                        {{ t('action.cancel') }}
                    </button>
                </div>
            </div>

            <template v-else>
                <div class="max-h-[280px] overflow-y-auto">
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
                        <span class="text-[15px]">{{ unit.name }}</span>
                        <span class="text-[13px] text-label-2">
                            {{ paths[unit.id] }} · {{ unit.level }}
                        </span>
                    </button>
                </div>

                <div class="flex items-center gap-3 border-t border-hairline px-3.5 py-2">
                    <button
                        v-if="canCreate"
                        type="button"
                        class="flex items-center gap-1.5 text-[14px] font-medium text-accent"
                        @click="startAdding"
                    >
                        <PhPlus :size="14" weight="bold" aria-hidden="true" />
                        {{ t('placePicker.add') }}
                    </button>

                    <span class="flex-1"></span>

                    <button type="button" class="text-[14px] text-label-2" @click="open = false">
                        {{ t('action.cancel') }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
