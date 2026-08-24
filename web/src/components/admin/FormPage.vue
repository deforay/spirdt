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
        <RouterLink :to="backTo" class="mb-4 inline-block text-[15px] text-accent">
            &lsaquo; {{ t('form.back') }}
        </RouterLink>

        <p v-if="error" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <!-- Raised off the ground rather than drawn on it. The shadow is the
             one that already exists for surfaces; a form is a sheet on a desk
             and it should read as one. -->
        <form
            class="rounded-surface border border-hairline bg-surface shadow-surface"
            @submit.prevent="emit('save')"
        >
            <div class="grid gap-5 p-6 sm:grid-cols-2">
                <slot />
            </div>

            <!--
                The actions sit on their own base, not on the same white as the
                fields. It gives the primary button somewhere to be, and it
                ends the form somewhere definite — a run of ten controls that
                simply stops has no bottom to it.
            -->
            <div
                class="flex flex-wrap items-center gap-3 rounded-b-surface border-t border-hairline bg-surface-2 px-6 py-4"
            >
                <button
                    type="submit"
                    class="min-h-11 rounded-card bg-accent px-5 text-[16px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40"
                    :disabled="saving"
                >
                    {{ saving ? t('form.saving') : t('form.save') }}
                </button>
                <RouterLink :to="backTo" class="text-[15px] text-label-2">
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
