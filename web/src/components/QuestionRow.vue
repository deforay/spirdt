<script setup lang="ts">
import { computed } from 'vue'

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

const response = defineModel<ResponseCode | null>('response', { required: true })
const comment = defineModel<string>('comment', { required: true })

const emit = defineEmits<{ guidance: [Question] }>()

const text = computed(() => localised(props.question.text))

const commentRequiredHere = computed(() =>
    commentRequired(response.value, props.question.comment_required_for),
)

const commentMissing = computed(
    () => commentRequiredHere.value && comment.value.trim() === '',
)

const placeholder = computed(() =>
    response.value === 'NA' ? t('question.whyNotApplicable') : t('question.comment'),
)

// Any translation counts, not only one in the current language: the button
// offers to show guidance, and guidance in the wrong language beats a button
// that is not there.
const hasGuidance = computed(() => Object.keys(props.question.guidance ?? {}).length > 0)
</script>

<template>
    <div class="flex flex-col gap-2.5 px-3.5 py-3">
        <div class="flex items-start gap-2.5">
            <span class="tnum min-w-[26px] pt-0.5 font-mono text-xs text-label-3">
                {{ question.code }}
            </span>
            <span class="flex-1 text-sm leading-snug">{{ text }}</span>
        </div>

        <button
            v-if="hasGuidance"
            type="button"
            class="ml-[35px] self-start text-left text-[12.5px] text-accent"
            @click="emit('guidance', question)"
        >
            {{ t('question.guidance') }} &rsaquo;
        </button>

        <div class="ml-[35px]">
            <SegmentedControl
                v-model="response"
                :na-allowed="question.na_allowed"
                :label="t('question.responseLabel', { code: question.code })"
            />
        </div>

        <div class="ml-[35px]">
            <input
                v-model="comment"
                type="text"
                :placeholder="placeholder"
                :aria-label="t('question.noteLabel', { code: question.code })"
                :aria-invalid="commentMissing"
                class="w-full rounded-lg border border-hairline bg-ground px-2.5 py-2 text-[13px] text-label placeholder:text-label-3"
            />
            <p v-if="commentMissing" class="mt-1 text-[11.5px] text-no">
                {{ t('question.noteRequired') }}
            </p>
        </div>
    </div>
</template>
