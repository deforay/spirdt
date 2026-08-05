<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import rawTemplate from '@resources/templates/spi-rdt-1.0.0.json'

import QuestionRow from '@/components/QuestionRow.vue'
import StorageNotice from '@/components/StorageNotice.vue'
import { useAssessment } from '@/composables/useAssessment'
import type { StoredResponse } from '@/db/database'
import type { ResponseCode, Template } from '@/scoring/types'

/**
 * The section screen, backed by the local database.
 *
 * Answers are written as they are given. The footer says when the last one
 * landed, because an assessor working offline has no other way to tell.
 */

// Cast rather than let TypeScript infer a literal type for a 96 KB document.
const template = rawTemplate as unknown as Template

const locale = template.default_locale
const pathogens = ['hiv']

const assessment = useAssessment(template)
const activeSection = ref(template.sections[2]?.code ?? '1')
const ready = ref(false)

onMounted(async () => {
    await assessment.start({
        siteName: 'Kanyama Clinic',
        pathogens,
        context: { refers_specimens: 'no' },
    })
    ready.value = true
})

const section = computed(
    () => template.sections.find((s) => s.code === activeSection.value) ?? template.sections[0]!,
)

/** Section 4 repeats per pathogen; every other section is answered once. */
const instance = computed(() => (section.value.scope === 'pathogen' ? pathogens[0]! : null))

const sectionTally = computed(() =>
    assessment.result.value.sections.find((s) => s.code === section.value.code),
)

const answeredHere = computed(
    () =>
        section.value.questions.filter((q) => assessment.responseFor(q.code, instance.value) !== null)
            .length,
)

const levelTone = computed(() => {
    const level = assessment.result.value.level
    if (level === null) return 'bg-track text-label-2'
    if (level >= 4) return 'bg-yes-soft text-yes'
    if (level === 3) return 'bg-accent-soft text-accent'
    if (level === 2) return 'bg-partial-soft text-partial'
    return 'bg-no-soft text-no'
})

const savedLabel = computed(() => {
    if (assessment.saveState.value === 'error') return 'Not saved'
    if (assessment.saveState.value === 'saving') return 'Saving'
    if (assessment.lastSavedAt.value === null) return 'Nothing to save yet'

    return `Saved ${assessment.lastSavedAt.value.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    })}`
})

function title(value: Record<string, string>): string {
    return value[locale] ?? Object.values(value)[0] ?? ''
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground">
        <StorageNotice
            :storage="assessment.storage.value"
            :save-state="assessment.saveState.value"
            :save-error="assessment.saveError.value"
        />

        <header class="flex flex-col gap-0.5 px-4 pb-2.5 pt-3">
            <span class="text-[13px] text-accent">
                {{ assessment.assessment.value?.siteName ?? 'Loading' }}
            </span>
            <h1 class="text-[30px] font-bold tracking-tight">{{ title(section.title) }}</h1>
            <span class="tnum text-[13px] text-label-2">
                {{ answeredHere }} of {{ section.questions.length }} answered
            </span>
        </header>

        <nav class="scroll-thin flex gap-1.5 overflow-x-auto px-4 pb-3" aria-label="Sections">
            <button
                v-for="item in template.sections"
                :key="item.code"
                type="button"
                :aria-current="item.code === activeSection ? 'true' : undefined"
                :class="[
                    'shrink-0 rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors',
                    item.code === activeSection
                        ? 'bg-accent text-white'
                        : 'bg-surface text-label-2 hover:text-label',
                ]"
                @click="activeSection = item.code"
            >
                {{ item.number }}
            </button>
        </nav>

        <main class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
            <div v-if="ready" class="overflow-hidden rounded-card bg-surface">
                <div v-for="(question, index) in section.questions" :key="question.code">
                    <div v-if="index > 0" class="ml-[49px] border-t border-hairline"></div>
                    <QuestionRow
                        :question="question"
                        :locale="locale"
                        :response="assessment.responseFor(question.code, instance) as ResponseCode | null"
                        :comment="assessment.commentFor(question.code, instance)"
                        @update:response="
                            assessment.setResponse(question.code, instance, $event as StoredResponse | null)
                        "
                        @update:comment="assessment.setComment(question.code, instance, $event)"
                    />
                </div>

                <div
                    class="flex justify-between border-t border-hairline px-3.5 py-3 text-[13px] text-label-2"
                >
                    <span>Section score</span>
                    <strong class="tnum font-semibold text-label">
                        {{ sectionTally?.score ?? 0 }} / {{ sectionTally?.possible ?? 0 }}
                    </strong>
                </div>
            </div>
        </main>

        <footer
            class="flex items-center justify-between gap-3 border-t border-hairline bg-surface px-4 pb-4 pt-3"
        >
            <div>
                <div class="tnum text-[22px] font-bold tracking-tight">
                    {{
                        assessment.result.value.percentage === null
                            ? '—'
                            : `${assessment.result.value.percentage.toFixed(2)}%`
                    }}
                </div>
                <div
                    :class="[
                        'tnum text-xs',
                        assessment.saveState.value === 'error' ? 'font-semibold text-no' : 'text-label-2',
                    ]"
                >
                    {{ savedLabel }}
                </div>
            </div>
            <span :class="['rounded-full px-3 py-1.5 text-[13px] font-semibold', levelTone]">
                {{
                    assessment.result.value.level === null
                        ? 'Not scorable'
                        : `Level ${assessment.result.value.level}`
                }}
            </span>
        </footer>
    </div>
</template>
