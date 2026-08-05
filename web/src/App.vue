<script setup lang="ts">
import { computed, reactive, ref } from 'vue'

import rawTemplate from '@resources/templates/spi-rdt-1.0.0.json'

import QuestionRow from '@/components/QuestionRow.vue'
import { questionKey, score } from '@/scoring/engine'
import type { AnswerInput, Context, ResponseCode, Template } from '@/scoring/types'

/**
 * The section screen, wired to the real template and the real scoring rules.
 *
 * Answers live in memory here. The next step is Dexie, so an assessment
 * survives a closed tab and a dead battery — which on a site visit is the
 * difference between a wasted morning and a filed assessment.
 */

// Cast rather than let TypeScript infer a literal type for a 96 KB document.
const template = rawTemplate as unknown as Template

const locale = template.default_locale

// Stands in for the assessment record until Part A and the local store land.
const context: Context = { refers_specimens: 'no' }
const pathogens = ['hiv']

const activeSection = ref(template.sections[2]?.code ?? '1')

const responses = reactive(new Map<string, ResponseCode | null>())
const comments = reactive(new Map<string, string>())

const section = computed(
    () => template.sections.find((s) => s.code === activeSection.value) ?? template.sections[0]!,
)

/** Section 4 repeats per pathogen; every other section is answered once. */
const instance = computed(() => (section.value.scope === 'pathogen' ? pathogens[0]! : null))

function keyFor(code: string): string {
    return questionKey(code, instance.value)
}

function responseFor(code: string): ResponseCode | null {
    return responses.get(keyFor(code)) ?? null
}

function setResponse(code: string, value: ResponseCode | null): void {
    responses.set(keyFor(code), value)
}

function commentFor(code: string): string {
    return comments.get(keyFor(code)) ?? ''
}

function setComment(code: string, value: string): void {
    comments.set(keyFor(code), value)
}

const answers = computed<AnswerInput[]>(() =>
    [...responses.entries()]
        .filter(([, value]) => value !== null)
        .map(([key, value]) => {
            const [code, pathogen] = key.split('|')
            return {
                question_code: code!,
                pathogen: pathogen === '' ? null : pathogen!,
                response: value!,
            }
        }),
)

const result = computed(() => score(template, answers.value, context, pathogens))

const sectionTally = computed(() => result.value.sections.find((s) => s.code === section.value.code))

const answeredHere = computed(
    () => section.value.questions.filter((q) => responseFor(q.code) !== null).length,
)

const levelTone = computed(() => {
    const level = result.value.level
    if (level === null) return 'bg-track text-label-2'
    if (level >= 4) return 'bg-yes-soft text-yes'
    if (level === 3) return 'bg-accent-soft text-accent'
    if (level === 2) return 'bg-partial-soft text-partial'
    return 'bg-no-soft text-no'
})

function title(value: Record<string, string>): string {
    return value[locale] ?? Object.values(value)[0] ?? ''
}
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-[430px] flex-col bg-ground">
        <header class="flex flex-col gap-0.5 px-4 pb-2.5 pt-3">
            <span class="text-[13px] text-accent">Kanyama Clinic</span>
            <h1 class="text-[30px] font-bold tracking-tight">{{ title(section.title) }}</h1>
            <span class="tnum text-[13px] text-label-2">
                {{ answeredHere }} of {{ section.questions.length }} answered
            </span>
        </header>

        <nav class="flex gap-1.5 overflow-x-auto scroll-thin px-4 pb-3" aria-label="Sections">
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

        <main class="flex-1 overflow-y-auto scroll-thin px-4 pb-6">
            <div class="overflow-hidden rounded-card bg-surface">
                <div v-for="(question, index) in section.questions" :key="question.code">
                    <div v-if="index > 0" class="ml-[49px] border-t border-hairline"></div>
                    <QuestionRow
                        :question="question"
                        :locale="locale"
                        :response="responseFor(question.code)"
                        :comment="commentFor(question.code)"
                        @update:response="setResponse(question.code, $event)"
                        @update:comment="setComment(question.code, $event)"
                    />
                </div>

                <div class="flex justify-between border-t border-hairline px-3.5 py-3 text-[13px] text-label-2">
                    <span>Section score</span>
                    <strong class="tnum font-semibold text-label">
                        {{ sectionTally?.score ?? 0 }} / {{ sectionTally?.possible ?? 0 }}
                    </strong>
                </div>
            </div>
        </main>

        <footer class="flex items-center justify-between gap-3 border-t border-hairline bg-surface px-4 pb-4 pt-3">
            <div>
                <div class="tnum text-[22px] font-bold tracking-tight">
                    {{ result.percentage === null ? '—' : `${result.percentage.toFixed(2)}%` }}
                </div>
                <div class="tnum text-xs text-label-2">
                    {{ answers.length }} of {{ answers.length + result.missing.length }} questions
                </div>
            </div>
            <span :class="['rounded-full px-3 py-1.5 text-[13px] font-semibold', levelTone]">
                {{ result.level === null ? 'Not scorable' : `Level ${result.level}` }}
            </span>
        </footer>
    </div>
</template>
