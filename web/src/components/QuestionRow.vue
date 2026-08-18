<script setup lang="ts">
import { PhNotePencil, PhPlus } from '@phosphor-icons/vue'
import { computed, nextTick, ref, useTemplateRef } from 'vue'

import SegmentedControl from '@/components/SegmentedControl.vue'
import { t, text as localised } from '@/i18n'
import { commentRequired } from '@/scoring/engine'
import type { Question, ResponseCode } from '@/scoring/types'

/**
 * One question in a section list.
 *
 * Two rules are enforced here, and both come from the template rather than
 * from this file:
 *
 *   Not applicable appears only where na_allowed is set. Five questions in the
 *   instrument permit it; the other fifty-four do not offer the option at all.
 *   Offering it everywhere would let a site certify by declaring questions
 *   inapplicable, since every Not applicable narrows the denominator.
 *
 *   A comment may be written against ANY response, and none of them is
 *   obliged. The instrument prints a comments box beside every question and
 *   the predecessor tool showed one on every screen, because an observation
 *   worth recording against a Yes — a practice seen, a name, a lot number — is
 *   as useful as one against a No.
 *
 * What a gap obliges is a corrective action, and that is asked for at the end
 * of the section rather than here. Describing the same shortfall twice, once
 * under the question and once where it can be given an owner and a date, is
 * the thing this arrangement exists to avoid.
 *
 * The requirement is still read from the template — comment_required_for is
 * empty in this instrument and the machinery stays, so a country that does
 * want a mandatory comment gets one without a deploy.
 */

const props = defineProps<{ question: Question }>()

/**
 * Guidance is shown, not linked to.
 *
 * Every one of the 59 questions carries a paragraph in the template saying
 * what evidence to look for, and until now it sat behind a button that emitted
 * an event nobody listened to — the assessor tapped "What to look for" and
 * nothing happened at all. The judgement it supports is the one the whole
 * record rests on: whether what is in front of you is a Yes or a Partial.
 *
 * On a wide screen it stands open in a column of its own, clamped to five
 * lines so a section still scans as a list of questions rather than a wall of
 * prose. On a phone there is no such column, so it stays a disclosure — and
 * now it opens.
 */
const open = ref(false)

/**
 * The note field is asked for, not always present.
 *
 * An empty text box under all fifty-nine questions is fifty-nine invitations
 * to write nothing, and it doubled the height of a section for content that
 * exists on a minority of answers. It appears when there is something in it,
 * when the response obliges one, or when the assessor asks for it — and once
 * asked for it takes the caret, because the tap was the request to type.
 */
const noteOpen = ref(false)
const noteField = useTemplateRef<HTMLInputElement>('noteField')

async function openNote(): Promise<void> {
    noteOpen.value = true
    await nextTick()
    noteField.value?.focus()
}

const response = defineModel<ResponseCode | null>('response', { required: true })
const comment = defineModel<string>('comment', { required: true })

const text = computed(() => localised(props.question.text))

const commentRequiredHere = computed(() =>
    commentRequired(response.value, props.question.comment_required_for),
)

const commentMissing = computed(
    () => commentRequiredHere.value && comment.value.trim() === '',
)

const showNote = computed(
    () => noteOpen.value || comment.value !== '' || commentRequiredHere.value,
)

const placeholder = computed(() =>
    response.value === 'NA' ? t('question.whyNotApplicable') : t('question.comment'),
)

// Any translation counts, not only one in the current language: the button
// offers to show guidance, and guidance in the wrong language beats a button
// that is not there.
const hasGuidance = computed(() => Object.keys(props.question.guidance ?? {}).length > 0)

const guidanceText = computed(() => localised(props.question.guidance ?? {}))
</script>

<template>
    <div class="flex flex-col gap-3.5 px-4 py-4 md:px-5 md:py-5 xl:grid xl:grid-cols-[minmax(0,1fr)_18rem] xl:gap-7 xl:px-6">
        <div class="flex flex-col gap-2.5">
            <div class="flex items-start gap-2.5">
                <span
                    class="tnum min-w-[36px] shrink-0 rounded-[7px] bg-accent-soft px-1.5 py-1 text-center font-mono text-[13px] font-semibold text-accent"
                >
                    {{ question.code }}
                </span>
                <span class="flex-1 pt-0.5 text-[17px] font-medium leading-snug md:text-[16.5px]">
                    {{ text }}
                </span>
            </div>

            <button
                v-if="hasGuidance"
                type="button"
                class="ml-[46px] self-start text-left text-[13.5px] font-semibold text-accent transition-colors hover:text-accent-hover"
                :aria-expanded="open"
                @click="open = !open"
            >
                {{ open ? t('question.guidanceHide') : t('question.guidance') }}
                <span aria-hidden="true">{{ open ? '‹' : '›' }}</span>
            </button>

            <div class="ml-[46px]">
                <SegmentedControl
                    v-model="response"
                    :na-allowed="question.na_allowed"
                    :label="t('question.responseLabel', { code: question.code })"
                />
            </div>

            <div class="ml-[46px]">
                <button
                    v-if="!showNote"
                    type="button"
                    class="flex min-h-11 items-center gap-1.5 text-[14px] font-medium text-label-3 transition-colors hover:text-accent"
                    @click="openNote"
                >
                    <PhPlus :size="14" weight="bold" aria-hidden="true" />
                    {{ t('question.addNote') }}
                </button>

                <template v-else>
                    <div
                        :class="[
                            'flex items-center gap-2.5 rounded-card border px-3 transition-colors',
                            commentMissing
                                ? 'border-no bg-no-soft'
                                : 'border-hairline bg-surface-2 focus-within:border-accent focus-within:bg-surface',
                        ]"
                    >
                        <PhNotePencil
                            :size="15"
                            aria-hidden="true"
                            class="shrink-0 text-label-3"
                        />
                        <input
                            ref="noteField"
                            v-model="comment"
                            type="text"
                            :placeholder="placeholder"
                            :aria-label="t('question.noteLabel', { code: question.code })"
                            :aria-invalid="commentMissing"
                            class="min-h-11 w-full bg-transparent text-[14.5px] text-label outline-none placeholder:text-label-3"
                        />
                    </div>
                    <p v-if="commentMissing" class="mt-1 text-[12.5px] text-no">
                        {{ t('question.noteRequired') }}
                    </p>
                </template>
            </div>
        </div>

        <!--
            One node, in two places. On a phone the parent is a column and this
            is the disclosure under the question; past 1280px the parent is a
            grid and the same node is the third pane, standing open. Rendering
            it twice would put the same paragraph in the accessibility tree
            twice and let the two copies drift.
        -->
        <aside
            v-if="hasGuidance"
            :class="[
                'ml-[46px] rounded-card border-l-2 border-brass-fill/70 bg-surface-2 px-3 py-2.5 xl:ml-0 xl:block',
                open ? 'block' : 'hidden',
            ]"
        >
            <span class="eyebrow hidden text-brass xl:block">{{ t('question.guidance') }}</span>
            <p
                :class="[
                    'text-[13.5px] leading-relaxed text-label-2 xl:mt-1.5',
                    open ? '' : 'xl:line-clamp-5',
                ]"
            >
                {{ guidanceText }}
            </p>
        </aside>
    </div>
</template>
