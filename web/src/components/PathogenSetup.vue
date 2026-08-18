<script setup lang="ts">
import { PhPlus, PhX } from '@phosphor-icons/vue'
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
        <!--
            The tests, as chips. A stacked list with a red Remove beside every
            line spent four rows and two red words on four facts; the chip
            carries the name and the way to drop it in one object, and red goes
            back to meaning No.
        -->
        <div v-if="modelValue.length > 0" class="flex flex-wrap gap-2">
            <span
                v-for="pathogen in modelValue"
                :key="pathogen.key"
                class="flex min-h-11 items-center gap-2 rounded-card border border-hairline bg-surface pl-4 pr-2 text-[16px]"
            >
                {{ pathogen.name }}
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-full text-label-3 transition-colors hover:bg-no-soft hover:text-no"
                    :aria-label="`${t('action.remove')} ${pathogen.name}`"
                    @click="remove(pathogen.key)"
                >
                    <PhX :size="14" weight="bold" aria-hidden="true" />
                </button>
            </span>
        </div>

        <p v-else class="text-[16px] text-label-2">{{ t('pathogens.empty') }}</p>

        <form class="flex items-center gap-2" @submit.prevent="add(draft)">
            <input
                v-model="draft"
                type="text"
                class="field flex-1"
                :placeholder="t('pathogens.placeholder')"
            />
            <button
                type="submit"
                class="flex min-h-11 shrink-0 items-center rounded-card bg-accent px-5 text-[15px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40"
                :disabled="draft.trim() === ''"
            >
                {{ t('action.add') }}
            </button>
        </form>

        <!-- The common four, one tap each. -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="name in COMMON.filter(
                    (candidate) =>
                        !modelValue.some((p) => p.name.toLowerCase() === candidate.toLowerCase()),
                )"
                :key="name"
                type="button"
                class="flex min-h-10 items-center gap-1.5 rounded-full border border-dashed border-hairline px-3.5 text-[14px] font-medium text-label-2 transition-colors hover:border-accent hover:bg-accent-soft hover:text-accent"
                @click="add(name)"
            >
                <PhPlus :size="12" weight="bold" aria-hidden="true" />
                {{ name }}
            </button>
        </div>

        <p class="tnum text-[14px] text-label-2">
            {{
                t('pathogens.repeatNote', {
                    number: repeatingSection,
                    count: modelValue.length * questionsPerPathogen,
                })
            }}
        </p>
    </div>
</template>
