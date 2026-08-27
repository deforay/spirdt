<script setup lang="ts">
import { PhCheckCircle, PhPaperPlaneTilt, PhWarningCircle, PhX } from '@phosphor-icons/vue'
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    DialogTrigger,
} from 'reka-ui'
import { computed, ref, watch } from 'vue'

import { sendAssessmentReport, type PdfVariant, type ReportSend } from '@/api/reports'
import { can, PERMISSION } from '@/auth/permissions'
import { locale, t } from '@/i18n'

/**
 * Sending the audit to the laboratory it is about.
 *
 * The site has no account here and never will, so until now the report reached
 * them because somebody downloaded a file and attached it to their own email.
 * That works, and leaves nothing in this system saying it happened — a year
 * later, "was the site ever told?" had no answer.
 *
 * THE ADDRESS IS A FIELD, NOT A QUESTION. The facility's recorded contact
 * fills it in when there is one, and it stays editable: the two cases — a
 * contact on file and no contact at all — are the same act with a different
 * starting value, and asking them differently would make the second one look
 * like an error.
 *
 * The history is under the form rather than behind a tab. Whether this report
 * has already gone, and to whom, is the thing somebody about to press send
 * most needs to know.
 */

const props = defineProps<{
    assessmentId: string
    /** The facility's contact, or empty when the registry holds none. */
    recipient: string
    /** Every attempt so far, newest first. */
    history: ReportSend[]
}>()

const emit = defineEmits<{
    /** Something was sent, so the report should be read again for the trail. */
    sent: []
}>()

/**
 * A stored moment, in the reader's own clock.
 *
 * The trail keeps UTC and MySQL writes it with a space where the standard
 * wants a T, so a browser left to parse it reads the string as local time and
 * shows a send an hour or five in the wrong place.
 */
function when(value: string): string {
    const parsed = new Date(value.replace(' ', 'T') + 'Z')

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
}

/** Keeping the address is a registry edit, and not everybody who may send may. */
const canRemember = can(PERMISSION.registryWrite)

const open = ref(false)
const field = ref<HTMLInputElement | null>(null)
const email = ref(props.recipient)
const variant = ref<PdfVariant>('full')
const photographs = ref(false)
const working = ref(false)
const error = ref('')
const done = ref('')

// Reopened rather than remounted: the dialog's content lives as long as the
// screen does, so the state from the last send has to be cleared on the way in
// or the next person sees somebody else's confirmation.
watch(open, (isOpen) => {
    if (isOpen) {
        email.value = props.recipient
        error.value = ''
        done.value = ''
    }
})

watch(
    () => props.recipient,
    (next) => {
        if (!open.value) {
            email.value = next
        }
    },
)

const documents = computed(
    (): { key: string; variant: PdfVariant; photographs: boolean; label: string }[] => [
        {
            key: 'full',
            variant: 'full',
            photographs: false,
            label: t('report.pdfWithoutPhotographs'),
        },
        {
            key: 'full-photos',
            variant: 'full',
            photographs: true,
            label: t('report.pdfWithPhotographs'),
        },
        { key: 'actions', variant: 'actions', photographs: false, label: t('report.pdfActions') },
    ],
)

const chosen = computed({
    get: () => (variant.value === 'actions' ? 'actions' : photographs.value ? 'full-photos' : 'full'),
    set: (key: string) => {
        const found = documents.value.find((entry) => entry.key === key)

        variant.value = found?.variant ?? 'full'
        photographs.value = found?.photographs ?? false
    },
})

async function send(): Promise<void> {
    working.value = true
    error.value = ''
    done.value = ''

    try {
        const result = await sendAssessmentReport(props.assessmentId, {
            locale: locale.value,
            variant: variant.value,
            photographs: photographs.value,
            email: email.value.trim(),
        })

        done.value = t('report.sendDone', { email: result.to })
        emit('sent')
    } catch (failure) {
        // The server's own sentence, which for this action is worth showing:
        // "there is no email address for this facility" and "the mail server
        // refused the message" are different problems for different people.
        error.value = failure instanceof Error ? failure.message : t('report.sendFailed')
    } finally {
        working.value = false
    }
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogTrigger
            class="inline-flex min-h-10 items-center gap-2 rounded-full border border-hairline bg-surface px-4 text-[15px] font-semibold text-label-2 transition-colors hover:border-accent hover:text-accent"
        >
            <PhPaperPlaneTilt :size="16" aria-hidden="true" />
            {{ t('report.send') }}
        </DialogTrigger>

        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-chrome/40" />

            <!-- Focus goes to the address rather than to the first thing in
                 the box, which is the close button. A dialog that opens with
                 the way out highlighted reads as a warning. -->
            <DialogContent
                class="scroll-thin fixed left-1/2 top-1/2 z-50 max-h-[85vh] w-[min(560px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-surface bg-surface p-6 shadow-surface"
                @open-auto-focus="$event.preventDefault(); field?.focus()"
            >
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <DialogTitle class="text-[18px] font-bold">
                            {{ t('report.send') }}
                        </DialogTitle>
                        <DialogDescription class="mt-1 text-[14px] text-label-2">
                            {{ t('report.sendHint') }}
                        </DialogDescription>
                    </div>

                    <DialogClose
                        :aria-label="t('action.cancel')"
                        class="-mr-1 -mt-1 flex size-9 shrink-0 items-center justify-center rounded-card text-label-2 transition-colors hover:bg-track"
                    >
                        <PhX :size="18" aria-hidden="true" />
                    </DialogClose>
                </div>

                <label class="mb-1.5 block px-1 text-[15px] font-medium" for="send-to">
                    {{ t('report.sendTo') }}
                </label>
                <input
                    id="send-to"
                    ref="field"
                    v-model="email"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    class="field"
                    :placeholder="t('report.sendToPlaceholder')"
                />
                <!-- Only promised to somebody who can keep it. Remembering an
                     address writes to the facility, which is registry data
                     shared across the programme — so a role that may send but
                     not correct records sends without saving, and must not be
                     told otherwise. -->
                <p v-if="recipient === ''" class="mt-1.5 px-1 text-[13px] text-label-2">
                    {{ canRemember ? t('report.sendToUnknown') : t('report.sendToUnknownReadOnly') }}
                </p>

                <fieldset class="mt-5">
                    <legend class="mb-1.5 px-1 text-[15px] font-medium">
                        {{ t('report.sendWhat') }}
                    </legend>

                    <div class="flex flex-col gap-2">
                        <label
                            v-for="document in documents"
                            :key="document.key"
                            :class="[
                                'flex min-h-11 cursor-pointer items-center gap-3 rounded-card border-2 px-4 text-[15px] transition-colors',
                                chosen === document.key
                                    ? 'border-accent bg-accent-soft font-semibold text-accent'
                                    : 'border-hairline bg-surface hover:border-label-3/40',
                            ]"
                        >
                            <input
                                v-model="chosen"
                                type="radio"
                                name="send-what"
                                :value="document.key"
                                class="sr-only"
                            />
                            {{ document.label }}
                        </label>
                    </div>
                </fieldset>

                <p
                    v-if="error !== ''"
                    class="mt-4 flex items-start gap-2 rounded-card bg-no-soft px-4 py-3 text-[14px] font-medium text-no"
                >
                    <PhWarningCircle :size="18" weight="fill" class="mt-px shrink-0" aria-hidden="true" />
                    <span>{{ error }}</span>
                </p>

                <p
                    v-if="done !== ''"
                    class="mt-4 flex items-start gap-2 rounded-card bg-yes-soft px-4 py-3 text-[14px] font-medium text-yes"
                    role="status"
                >
                    <PhCheckCircle :size="18" weight="fill" class="mt-px shrink-0" aria-hidden="true" />
                    <span>{{ done }}</span>
                </p>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <DialogClose
                        class="min-h-11 rounded-full px-4 text-[15px] font-medium text-label-2 transition-colors hover:bg-track"
                    >
                        {{ t('action.cancel') }}
                    </DialogClose>

                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center gap-2 rounded-full bg-accent px-5 text-[15px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-50"
                        :disabled="working || email.trim() === ''"
                        @click="send"
                    >
                        <PhPaperPlaneTilt :size="16" aria-hidden="true" />
                        {{ working ? t('report.sending') : t('report.send') }}
                    </button>
                </div>

                <!-- Where it has already been. Under the form because somebody
                     about to press send needs to know whether they are about to
                     send it twice. -->
                <div v-if="history.length > 0" class="mt-6 border-t border-hairline pt-4">
                    <p class="eyebrow mb-2 text-label-3">{{ t('report.sendHistory') }}</p>

                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="(entry, index) in history"
                            :key="index"
                            class="text-[14px]"
                            :class="entry.sent ? 'text-label-2' : 'text-no'"
                        >
                            {{
                                entry.sent
                                    ? t('report.sentTo', { email: entry.to })
                                    : t('report.sendFailedTo', { email: entry.to })
                            }}
                            <span class="text-label-3">
                                · {{ when(entry.at) }}
                                <template v-if="entry.by"> · {{ entry.by }}</template>
                            </span>
                            <span v-if="entry.reason" class="block text-[13px] text-label-3">
                                {{ entry.reason }}
                            </span>
                        </li>
                    </ul>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
