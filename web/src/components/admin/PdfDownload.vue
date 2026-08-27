<script setup lang="ts">
import { PhCaretDown, PhFilePdf } from '@phosphor-icons/vue'
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui'
import { ref } from 'vue'

import { downloadAssessmentPdf, type PdfVariant } from '@/api/reports'
import { locale, t } from '@/i18n'

/**
 * The visit, as a file to keep.
 *
 * ONE COMPONENT FOR BOTH PLACES. It hangs on a row of the reports list and at
 * the top of the report itself, and those are the same action — a person who
 * learns it in one screen must not have to learn it again in the other.
 *
 * THREE DOCUMENTS, because there are three readers. The record with its
 * evidence, for the file. The record without, for a mailbox — five pictures a
 * section at a phone camera's resolution is a file that bounces. And the short
 * one, for the laboratory manager who has to act: who was audited and what
 * they have to do about it, without seven pages of questions to find it in.
 *
 * The work happens here rather than in a link because every call to the API
 * carries a token in a header. An anchor pointed at the endpoint carries
 * nothing, answers 401, and shows the reader an empty tab.
 */

const props = withDefaults(
    defineProps<{
        assessmentId: string
        /** Quiet on a table row, and the full sentence above a document. */
        compact?: boolean
    }>(),
    { compact: false },
)

const working = ref(false)
const error = ref('')

async function download(photographs: boolean, variant: PdfVariant = 'full'): Promise<void> {
    working.value = true
    error.value = ''

    try {
        await downloadAssessmentPdf(props.assessmentId, locale.value, photographs, variant)
    } catch {
        // The message is ours rather than the server's: what comes back from a
        // failed file request is a status code, and "the report could not be
        // downloaded" is the only part of it anybody can act on.
        error.value = t('report.pdfFailed')
    } finally {
        working.value = false
    }
}
</script>

<template>
    <div>
        <DropdownMenuRoot>
            <DropdownMenuTrigger
                :disabled="working"
                :class="[
                    'inline-flex items-center gap-2 rounded-full font-semibold transition-colors disabled:opacity-50',
                    compact
                        ? 'min-h-9 border border-hairline bg-surface px-3 text-[14px] text-label-2 hover:border-accent hover:text-accent'
                        : 'min-h-10 bg-accent px-4 text-[15px] text-accent-ink hover:bg-accent-hover',
                ]"
            >
                <PhFilePdf :size="16" aria-hidden="true" />
                {{ working ? t('report.pdfPreparing') : compact ? t('report.pdf') : t('report.downloadPdf') }}
                <PhCaretDown :size="12" weight="bold" aria-hidden="true" />
            </DropdownMenuTrigger>

            <DropdownMenuPortal>
                <DropdownMenuContent
                    :side-offset="6"
                    align="end"
                    class="z-50 min-w-[260px] rounded-card border border-hairline bg-surface p-1.5 shadow-surface"
                >
                    <DropdownMenuItem
                        class="flex min-h-11 cursor-pointer select-none items-center rounded-card px-3 text-[15px] outline-none transition-colors data-[highlighted]:bg-accent-soft data-[highlighted]:text-accent"
                        @select="download(true)"
                    >
                        {{ t('report.pdfWithPhotographs') }}
                    </DropdownMenuItem>

                    <DropdownMenuItem
                        class="flex min-h-11 cursor-pointer select-none items-center rounded-card px-3 text-[15px] outline-none transition-colors data-[highlighted]:bg-accent-soft data-[highlighted]:text-accent"
                        @select="download(false)"
                    >
                        {{ t('report.pdfWithoutPhotographs') }}
                    </DropdownMenuItem>

                    <!-- Apart from the two above it, because it is a different
                         document rather than a lighter copy of the same one. -->
                    <div class="my-1.5 border-t border-hairline"></div>

                    <DropdownMenuItem
                        class="flex min-h-11 cursor-pointer select-none items-center rounded-card px-3 text-[15px] outline-none transition-colors data-[highlighted]:bg-accent-soft data-[highlighted]:text-accent"
                        @select="download(false, 'actions')"
                    >
                        {{ t('report.pdfActions') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenuPortal>
        </DropdownMenuRoot>

        <p v-if="error !== ''" class="mt-1 text-[13px] font-medium text-no">{{ error }}</p>
    </div>
</template>
