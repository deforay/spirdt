<script setup lang="ts">
import { PhBuildings, PhCheck } from '@phosphor-icons/vue'

import { formatPercent, t, text } from '@/i18n'
import type { Section } from '@/scoring/types'

/**
 * The map of the visit, down the side of every screen of one.
 *
 * It used to hang on the checklist alone. Site details put a form panel in the
 * same place instead, so walking from the first step of an audit into its
 * first section swapped the whole left third of the screen for a different
 * object — and Site details, which this rail's own first row calls the thing
 * that comes before Section 1, read as a screen from before the audit rather
 * than the beginning of it.
 *
 * So it is a component, and both screens wear it. Site details is the current
 * row there, lit the way a section is, because that is what it is.
 *
 * It holds what is being assessed, the sections of it, and where it stands.
 * The score sits at the foot rather than in a bar across the page: that put
 * the state of the visit as far from the list of sections as the layout
 * allowed, and spent a row of the working area on it.
 */

/** What a row's progress bar and its count are drawn from. */
export interface RailTally {
    answered: number
    expected: number
    done: boolean
}

const props = defineProps<{
    siteName: string
    sections: Section[]
    /** A section code, or 'site' for the setup screen itself. */
    activeCode: string
    tallies: Map<string, RailTally>
    answered: number
    total: number
    isComplete: boolean
    percentage: number | null
    roundDp: number
    level: number | null
    /** The colours for the level chip, decided by the screen that knows why. */
    levelTone: string
    /** How full the visit bar is, as a width. */
    visitFilled: string
    savedLabel: string
}>()

const emit = defineEmits<{ pick: [code: string] }>()

/**
 * A section nobody has opened has an expected count of zero until the engine
 * has seen it, and dividing by that is how a bar ends up full before a
 * question is answered.
 */
function filled(code: string): string {
    const tally = props.tallies.get(code)

    if (tally === undefined || tally.expected === 0) return '0%'

    return `${Math.min(100, Math.round((tally.answered / tally.expected) * 100))}%`
}
</script>

<template>
    <div class="hidden min-h-0 md:flex md:flex-col md:pb-6">
        <div class="mb-3 rounded-surface bg-accent-soft px-3.5 py-3">
            <span class="eyebrow text-accent">{{ t('checklist.currentVisit') }}</span>
            <p class="mt-1 text-[15px] font-semibold leading-snug">
                {{ siteName === '' ? t('checklist.loading') : siteName }}
            </p>
        </div>

        <span class="eyebrow px-3 pb-1.5 text-label-3">
            {{ t('checklist.sections') }}
        </span>

        <nav class="scroll-thin min-h-0 flex-1 md:overflow-y-auto" :aria-label="t('checklist.sections')">
            <!--
                Before the sections, because that is where it comes in the
                visit. It was a chip in the top corner, which on a wide screen
                is the furthest point from this list — and this list is where
                somebody looks for where a visit can be.

                It is lit like a section when it is the screen you are on. It
                was drawn as a quiet link on every screen including its own,
                which left the rail saying nothing at all about where you were
                standing exactly when you were standing here.
            -->
            <button
                type="button"
                :aria-current="activeCode === 'site' ? 'true' : undefined"
                :class="[
                    'mb-1 flex w-full items-center gap-2.5 rounded-card px-3 py-2.5 text-left text-[15px] transition-colors',
                    activeCode === 'site'
                        ? 'bg-surface font-semibold text-accent shadow-pick'
                        : 'text-label-2 hover:bg-surface hover:text-label',
                ]"
                @click="emit('pick', 'site')"
            >
                <PhBuildings :size="16" class="shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1">{{ t('checklist.editSetup') }}</span>
            </button>

            <button
                v-for="item in sections"
                :key="item.code"
                type="button"
                :aria-current="item.code === activeCode ? 'true' : undefined"
                :class="[
                    'relative flex w-full items-baseline gap-2.5 rounded-card px-3 py-2.5 text-left',
                    'text-[15px] transition-colors',
                    item.code === activeCode
                        ? 'bg-surface font-semibold text-accent shadow-pick'
                        : 'text-label-2 hover:bg-surface/70 hover:text-label',
                ]"
                @click="emit('pick', item.code)"
            >
                <span class="tnum shrink-0 font-bold">{{ item.number }}</span>

                <span class="min-w-0 flex-1">
                    {{ text(item.title) }}

                    <!--
                        How far through this section is, drawn rather than
                        counted. The count is still there and still exact; the
                        bar is what makes a rail of five sections answerable at
                        a glance, which a column of fractions never was. Hidden
                        from assistive technology because the fraction beside it
                        says the same thing in words.
                    -->
                    <span
                        aria-hidden="true"
                        class="mt-1.5 block h-1 w-full overflow-hidden rounded-full bg-track"
                    >
                        <span
                            class="block h-full rounded-full bg-accent transition-[width] duration-200"
                            :style="{ width: filled(item.code) }"
                        ></span>
                    </span>
                </span>

                <!-- A tick when there is nothing left, the count when there is.
                     Both in the faintest label colour: this is the answer to a
                     question the assessor asked, not something demanding to be
                     read. -->
                <PhCheck
                    v-if="tallies.get(item.code)?.done"
                    :size="14"
                    class="mt-0.5 shrink-0 self-start text-accent"
                    :aria-label="t('checklist.sectionDone')"
                />
                <span v-else class="tnum shrink-0 self-start text-[13px] text-label-3">
                    {{ tallies.get(item.code)?.answered ?? 0 }}/{{
                        tallies.get(item.code)?.expected ?? 0
                    }}
                </span>
            </button>
        </nav>

        <!--
            Where the visit stands, at the foot of the rail. Dark on a light
            page because it is the one figure somebody looks up rather than
            reads past, and a tile that dark is found without being hunted for.
        -->
        <div class="mt-3 rounded-surface bg-chrome px-4 py-3.5 text-chrome-ink">
            <span class="eyebrow text-chrome-ink/55">
                {{ isComplete ? t('checklist.runningScore') : t('checklist.progress2') }}
            </span>

            <div class="mt-1.5 flex items-baseline gap-2.5">
                <span class="tnum text-[30px] font-extrabold leading-none">
                    <template v-if="isComplete">
                        {{ percentage === null ? '—' : formatPercent(percentage, roundDp) }}
                    </template>
                    <template v-else>{{ answered }}/{{ total }}</template>
                </span>

                <span
                    v-if="isComplete"
                    :class="['tnum rounded-full px-2 py-0.5 text-[12px] font-semibold', levelTone]"
                >
                    {{ level === null ? t('score.notScorable') : t('score.level', { level }) }}
                </span>
            </div>

            <span
                aria-hidden="true"
                class="mt-3 block h-1.5 w-full overflow-hidden rounded-full bg-white/15"
            >
                <span
                    class="block h-full rounded-full bg-brass-fill transition-[width] duration-200"
                    :style="{ width: visitFilled }"
                ></span>
            </span>

            <p class="tnum mt-2.5 truncate text-[12.5px] text-chrome-ink/60">
                {{ savedLabel }}
            </p>
        </div>
    </div>
</template>
