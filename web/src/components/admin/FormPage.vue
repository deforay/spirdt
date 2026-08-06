<script setup lang="ts">
import { RouterLink } from 'vue-router'

import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'

/**
 * The frame every add-or-edit screen sits in.
 *
 * A page rather than a modal, and one component rather than a convention, so
 * "the same style everywhere" is enforced instead of remembered. A form of ten
 * fields in a modal is a scroll inside a scroll; a page is linkable, the back
 * button works, and on the desk these screens are used at there is room.
 *
 * Add and edit are the same form. The only difference is whether it arrived
 * with anything in it, which is a prop rather than a second component.
 */

defineProps<{
    title: string
    subtitle?: string
    /** Where cancel and the back link go. */
    backTo: { name: string }
    saving?: boolean
    error?: string
}>()

const emit = defineEmits<{ save: [] }>()
</script>

<template>
    <AdminShell :title="title" :subtitle="subtitle">
        <RouterLink :to="backTo" class="mb-4 inline-block text-[14px] text-accent">
            &lsaquo; {{ t('form.back') }}
        </RouterLink>

        <p v-if="error" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <form class="rounded-card bg-surface p-5" @submit.prevent="emit('save')">
            <div class="grid gap-5 sm:grid-cols-2">
                <slot />
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-hairline pt-4">
                <button
                    type="submit"
                    class="rounded-lg bg-accent px-5 py-2 text-[15px] font-semibold text-white disabled:opacity-40"
                    :disabled="saving"
                >
                    {{ saving ? t('form.saving') : t('form.save') }}
                </button>
                <RouterLink :to="backTo" class="text-[14px] text-label-2">
                    {{ t('action.cancel') }}
                </RouterLink>

                <span class="flex-1"></span>

                <!-- Anything destructive or unusual — deactivating, merging —
                     lives here, away from Save and after it in reading order. -->
                <slot name="actions" />
            </div>
        </form>
    </AdminShell>
</template>
