<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { session } from '@/auth/session'
import SignaturePad from '@/components/SignaturePad.vue'
import { loadAttachments, saveSignature } from '@/db/attachments'
import type { SignatureRole, StoredAttachment } from '@/db/database'
import { formatTime, t } from '@/i18n'

/**
 * Who signed off on the visit.
 *
 * Sits at the end of the review, after the gaps have been read out, because
 * that is the order the debrief happens in: the site is told what was found,
 * and then both sides put their name to it. Signing before the findings were
 * shown would be signing a blank page.
 *
 * Neither signature blocks submission. That is the documented design and it is
 * the right way round — media uploads separately and can fail on its own, so
 * requiring one here would let a broken camera or a dead canvas strand a
 * finished assessment on a tablet.
 *
 * Neither name is typed. The assessor is whoever is signed in, and the site
 * representative is the interviewee Part A already recorded — asking again
 * would be asking for a second version of a name already on file.
 */

const props = defineProps<{
    assessmentId: string
    /** Part A answers, for the interviewee's name. */
    context: Record<string, unknown>
}>()

const rows = ref<StoredAttachment[]>([])
const signing = ref<SignatureRole | null>(null)

const assessorName = computed(() => session.value?.user.fullName ?? '')

const siteRepresentativeName = computed(() => {
    const value = props.context.interviewee_name

    return typeof value === 'string' ? value.trim() : ''
})

const SLOTS = computed(() => [
    {
        role: 'assessor_1' as SignatureRole,
        label: t('signature.assessor'),
        name: assessorName.value,
        hint: '',
    },
    {
        role: 'site_representative' as SignatureRole,
        label: t('signature.siteRepresentative'),
        name: siteRepresentativeName.value,
        // Signing on behalf of an unnamed person is not a signature, so the
        // slot says where the name comes from rather than offering a blank.
        hint: siteRepresentativeName.value === '' ? t('signature.siteNameHint') : '',
    },
])

function rowFor(role: SignatureRole): StoredAttachment | undefined {
    return rows.value.find((row) => row.role === role)
}

/**
 * Object URLs, one per stored image, revoked when they stop being current.
 *
 * A URL that is not revoked pins its blob in memory for the life of the
 * document, and these are the only large objects the app holds.
 */
const previews = ref(new Map<string, string>())

function refreshPreviews(): void {
    const next = new Map<string, string>()

    for (const row of rows.value) {
        next.set(row.key, URL.createObjectURL(row.blob))
    }

    for (const url of previews.value.values()) {
        URL.revokeObjectURL(url)
    }

    previews.value = next
}

async function load(): Promise<void> {
    rows.value = (await loadAttachments(props.assessmentId)).filter(
        (row) => row.kind === 'signature',
    )
    refreshPreviews()
}

async function onSave(role: SignatureRole, name: string, blob: Blob): Promise<void> {
    await saveSignature({ assessmentId: props.assessmentId, role, signedName: name, blob })

    signing.value = null
    await load()
}

onMounted(load)

watch(() => props.assessmentId, load)

onBeforeUnmount(() => {
    for (const url of previews.value.values()) {
        URL.revokeObjectURL(url)
    }
})
</script>

<template>
    <section class="mb-4">
        <h2 class="px-1 pb-1.5 text-[13px] font-semibold uppercase tracking-wide text-label-2">
            {{ t('signature.heading') }}
        </h2>

        <div class="overflow-hidden rounded-card bg-surface">
            <div
                v-for="(slot, index) in SLOTS"
                :key="slot.role"
                :class="index > 0 ? 'border-t border-hairline' : ''"
            >
                <div class="flex items-center justify-between gap-3 px-3.5 py-3">
                    <div class="min-w-0 flex-1">
                        <span class="block text-[13px] text-label-2">{{ slot.label }}</span>
                        <span class="block truncate text-[17px]">
                            {{ slot.name === '' ? t('signature.noName') : slot.name }}
                        </span>

                        <span
                            v-if="rowFor(slot.role) === undefined"
                            class="block text-[13px] text-label-3"
                        >
                            {{ t('signature.unsigned') }}
                        </span>
                        <span v-else class="tnum block text-[13px] text-label-2">
                            {{
                                t('signature.signedAt', {
                                    time: formatTime(new Date(rowFor(slot.role)!.capturedAt)),
                                })
                            }}
                            <template v-if="rowFor(slot.role)!.dirty">
                                · {{ t('signature.pending') }}
                            </template>
                        </span>

                        <span
                            v-if="rowFor(slot.role)?.syncError"
                            class="block text-[13px] font-medium text-no"
                        >
                            {{ rowFor(slot.role)!.syncError }}
                        </span>

                        <span v-if="slot.hint !== ''" class="block text-[13px] text-label-3">
                            {{ slot.hint }}
                        </span>
                    </div>

                    <img
                        v-if="rowFor(slot.role) !== undefined"
                        :src="previews.get(rowFor(slot.role)!.key)"
                        :alt="t('signature.heading')"
                        class="h-12 w-28 shrink-0 rounded bg-white object-contain"
                    />

                    <button
                        type="button"
                        class="shrink-0 text-[15px] font-medium text-accent disabled:opacity-40"
                        :disabled="slot.name === ''"
                        @click="signing = signing === slot.role ? null : slot.role"
                    >
                        {{
                            rowFor(slot.role) === undefined
                                ? t('signature.sign')
                                : t('signature.signAgain')
                        }}
                    </button>
                </div>

                <div v-if="signing === slot.role" class="px-3.5 pb-3.5">
                    <SignaturePad
                        :signed-name="slot.name"
                        @save="(blob) => onSave(slot.role, slot.name, blob)"
                        @cancel="signing = null"
                    />
                </div>
            </div>
        </div>

        <p class="px-1 pt-1.5 text-[13px] text-label-2">{{ t('signature.optional') }}</p>
    </section>
</template>
