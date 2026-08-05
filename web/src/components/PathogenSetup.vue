<script setup lang="ts">
import { ref } from 'vue'

import type { StoredPathogen } from '@/db/database'
import { t } from '@/i18n'

/**
 * Which pathogens the visit covers.
 *
 * Section 4 repeats once per entry, so this decides the size of the
 * assessment — 23 questions each. It is also what makes the instrument
 * pathogen-agnostic: one visit covers every rapid test performed at the site,
 * so gaps affecting several tests surface together instead of once per
 * disease programme.
 *
 * Removing one does not delete its answers. They stop being expected and stop
 * being scored, and come back intact if the pathogen is added again — a
 * mis-tap here would otherwise discard 23 answers with nothing to undo it.
 */

const props = defineProps<{
    modelValue: StoredPathogen[]
    /** Which section repeats, and how big it is. Read off the template, not assumed. */
    repeatingSection: number
    questionsPerPathogen: number
}>()
const emit = defineEmits<{ 'update:modelValue': [value: StoredPathogen[]] }>()

const draft = ref('')

/** The tests people actually name at a site, offered to save typing. */
const COMMON = ['HIV', 'Malaria', 'Syphilis', 'Hepatitis B', 'Hepatitis C', 'Tuberculosis']

function add(name: string) {
    const trimmed = name.trim()

    if (trimmed === '') {
        return
    }

    // Case-insensitive, because "HIV" and "hiv" typed on two visits would score
    // as two different pathogens and double the expected questions.
    const exists = props.modelValue.some(
        (pathogen) => pathogen.name.toLowerCase() === trimmed.toLowerCase(),
    )

    if (exists) {
        draft.value = ''
        return
    }

    emit('update:modelValue', [...props.modelValue, { key: trimmed, name: trimmed }])
    draft.value = ''
}

function remove(key: string) {
    emit(
        'update:modelValue',
        props.modelValue.filter((pathogen) => pathogen.key !== key),
    )
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="modelValue.length > 0" class="overflow-hidden rounded-card bg-surface">
            <div
                v-for="(pathogen, index) in modelValue"
                :key="pathogen.key"
                class="flex items-center justify-between gap-3 px-3.5 py-3"
                :class="index > 0 ? 'border-t border-hairline' : ''"
            >
                <span class="text-[17px]">{{ pathogen.name }}</span>
                <button type="button" class="text-[15px] text-no" @click="remove(pathogen.key)">
                    {{ t('action.remove') }}
                </button>
            </div>
        </div>

        <p v-else class="px-1 text-[15px] text-label-2">{{ t('pathogens.empty') }}</p>

        <div class="overflow-hidden rounded-card bg-surface">
            <form class="flex items-center gap-3 px-3.5 py-3" @submit.prevent="add(draft)">
                <input
                    v-model="draft"
                    type="text"
                    class="w-full bg-transparent text-[17px] outline-none placeholder:text-label-3"
                    :placeholder="t('pathogens.placeholder')"
                />
                <button
                    type="submit"
                    class="shrink-0 text-[15px] font-semibold text-accent disabled:opacity-40"
                    :disabled="draft.trim() === ''"
                >
                    {{ t('action.add') }}
                </button>
            </form>
        </div>

        <div class="flex flex-wrap gap-1.5 px-1">
            <button
                v-for="name in COMMON.filter(
                    (candidate) =>
                        !modelValue.some((p) => p.name.toLowerCase() === candidate.toLowerCase()),
                )"
                :key="name"
                type="button"
                class="rounded-full bg-surface px-3 py-1.5 text-[13px] text-label-2"
                @click="add(name)"
            >
                + {{ name }}
            </button>
        </div>

        <p class="tnum px-1 text-[13px] text-label-2">
            {{
                t('pathogens.repeatNote', {
                    number: repeatingSection,
                    count: modelValue.length * questionsPerPathogen,
                })
            }}
        </p>
    </div>
</template>
