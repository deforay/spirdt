<script setup lang="ts">
import { computed, reactive, watch } from 'vue'

import type { Context, ContextField, Localised } from '@/scoring/types'

/**
 * Part A — everything asked before the checklist starts.
 *
 * Driven off the template's `context_fields` rather than hand-built, for the
 * same reason the questions are: the instrument is a versioned document, and a
 * form written by hand goes stale the first time a field is added and nobody
 * notices until a country's data is missing it.
 *
 * One field earns its keep more than the rest. `refers_specimens` decides
 * whether Section 5 applies at all, so answering it wrongly silently removes
 * nine questions from the assessment and moves the percentage. It is marked on
 * screen as affecting the checklist, because nothing else here does.
 */

const props = defineProps<{
    fields: ContextField[]
    locale: string
    modelValue: Context
    /** Codes of fields whose value changes which sections apply. */
    applicabilityFields?: string[]
}>()

const emit = defineEmits<{ 'update:modelValue': [value: Context] }>()

const draft = reactive<Record<string, unknown>>({ ...props.modelValue })

watch(
    () => props.modelValue,
    (next) => Object.assign(draft, next),
)

function text(value: Localised | undefined): string {
    if (value === undefined) {
        return ''
    }

    return value[props.locale] ?? Object.values(value)[0] ?? ''
}

function commit() {
    emit('update:modelValue', { ...draft })
}

/** "Other" and the like need a free-text companion, stored as `<code>_other`. */
function needsSpecify(field: ContextField): boolean {
    const chosen = draft[field.code]

    return (field.options ?? []).some((option) => option.key === chosen && option.specify === true)
}

function rows(field: ContextField): Array<Record<string, string>> {
    const value = draft[field.code]

    return Array.isArray(value) ? (value as Array<Record<string, string>>) : []
}

function addRow(field: ContextField) {
    draft[field.code] = [...rows(field), {}]
    commit()
}

function removeRow(field: ContextField, index: number) {
    draft[field.code] = rows(field).filter((_, at) => at !== index)
    commit()
}

function setRowValue(field: ContextField, index: number, code: string, value: string) {
    draft[field.code] = rows(field).map((row, at) => (at === index ? { ...row, [code]: value } : row))
    commit()
}

const missing = computed(() =>
    props.fields.filter((field) => {
        if (field.required !== true) {
            return false
        }

        const value = draft[field.code]

        return value === undefined || value === null || value === ''
    }),
)

defineExpose({ missing })

const inputClass =
    'w-full bg-transparent text-[17px] outline-none placeholder:text-label-3 tnum'
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-for="field in fields" :key="field.code" class="flex flex-col gap-1.5">
            <div class="flex items-baseline justify-between gap-3 px-1">
                <label :for="field.code" class="text-[15px] font-medium">
                    {{ text(field.label) }}
                    <span v-if="field.required" class="text-no">*</span>
                </label>
                <span
                    v-if="(applicabilityFields ?? []).includes(field.code)"
                    class="shrink-0 rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold text-accent"
                >
                    Changes the checklist
                </span>
            </div>

            <p v-if="field.hint" class="px-1 text-[13px] text-label-2">{{ text(field.hint) }}</p>

            <!-- One choice, shown as rows rather than a dropdown: a select on a
                 tablet hides its options behind a tap, and these lists are short. -->
            <div v-if="field.type === 'select_one'" class="overflow-hidden rounded-card bg-surface">
                <button
                    v-for="(option, index) in field.options ?? []"
                    :key="option.key"
                    type="button"
                    class="flex w-full items-center justify-between px-3.5 py-3 text-left text-[17px]"
                    :class="index > 0 ? 'border-t border-hairline' : ''"
                    @click="
                        draft[field.code] = draft[field.code] === option.key ? '' : option.key;
                        commit()
                    "
                >
                    <span>{{ text(option.label) }}</span>
                    <span v-if="draft[field.code] === option.key" class="font-semibold text-accent">
                        Selected
                    </span>
                </button>

                <div v-if="needsSpecify(field)" class="border-t border-hairline px-3.5 py-3">
                    <input
                        :value="(draft[`${field.code}_other`] as string) ?? ''"
                        type="text"
                        :class="inputClass"
                        placeholder="Please specify"
                        @input="
                            draft[`${field.code}_other`] = ($event.target as HTMLInputElement).value;
                            commit()
                        "
                    />
                </div>
            </div>

            <!-- A repeating group: the staff who do the testing. -->
            <div v-else-if="field.type === 'repeat'" class="flex flex-col gap-2">
                <div
                    v-for="(row, index) in rows(field)"
                    :key="index"
                    class="overflow-hidden rounded-card bg-surface"
                >
                    <label
                        v-for="(sub, subIndex) in field.fields ?? []"
                        :key="sub.code"
                        class="flex items-center gap-3 px-3.5 py-3"
                        :class="subIndex > 0 ? 'border-t border-hairline' : ''"
                    >
                        <span class="w-[92px] shrink-0 text-[15px] text-label-2">
                            {{ text(sub.label) }}
                        </span>
                        <input
                            :value="row[sub.code] ?? ''"
                            type="text"
                            :class="inputClass"
                            @input="
                                setRowValue(
                                    field,
                                    index,
                                    sub.code,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>

                    <button
                        type="button"
                        class="w-full border-t border-hairline px-3.5 py-2.5 text-left text-[15px] text-no"
                        @click="removeRow(field, index)"
                    >
                        Remove
                    </button>
                </div>

                <button
                    type="button"
                    class="rounded-card bg-surface px-3.5 py-3 text-left text-[17px] text-accent"
                    @click="addRow(field)"
                >
                    Add {{ text(field.label).toLowerCase() }}
                </button>
            </div>

            <div v-else-if="field.type === 'textarea'" class="overflow-hidden rounded-card bg-surface">
                <textarea
                    :id="field.code"
                    :value="(draft[field.code] as string) ?? ''"
                    rows="3"
                    class="scroll-thin w-full resize-none bg-transparent px-3.5 py-3 text-[17px] outline-none placeholder:text-label-3"
                    @input="
                        draft[field.code] = ($event.target as HTMLTextAreaElement).value;
                        commit()
                    "
                ></textarea>
            </div>

            <div v-else class="overflow-hidden rounded-card bg-surface">
                <input
                    :id="field.code"
                    :value="(draft[field.code] as string) ?? ''"
                    :type="
                        field.type === 'date'
                            ? 'date'
                            : field.type === 'time'
                              ? 'time'
                              : field.type === 'integer'
                                ? 'number'
                                : 'text'
                    "
                    :inputmode="field.type === 'integer' ? 'numeric' : undefined"
                    class="w-full bg-transparent px-3.5 py-3 text-[17px] outline-none placeholder:text-label-3 tnum"
                    @input="
                        draft[field.code] = ($event.target as HTMLInputElement).value;
                        commit()
                    "
                />
            </div>
        </div>
    </div>
</template>
