<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
    createFacility,
    type Facility,
    type FacilityInput,
    facilityOptions,
    type FacilityOptions,
    getFacility,
    type GeoTree,
    listFacilities,
    listGeoUnits,
    mergeFacility,
    updateFacility,
} from '@/api/registry'
import FormField from '@/components/admin/FormField.vue'
import FormPage from '@/components/admin/FormPage.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import { flash } from '@/composables/useFlash'
import { locale, t } from '@/i18n'

/**
 * One facility, added or edited.
 *
 * Add and edit are the same screen; the route decides whether it arrives
 * empty. Splitting them would mean two forms drifting apart field by field.
 *
 * The type, level and affiliation lists come from the PUBLISHED INSTRUMENT
 * rather than from constants here. They are the instrument's own vocabulary
 * and a country may customise it, so a list hard-coded in the client would be
 * one revision away from offering a value that scores against nothing.
 */

const route = useRoute()
const router = useRouter()

const id = computed(() => (route.params.id === undefined ? null : String(route.params.id)))
const isNew = computed(() => id.value === null)

const tree = ref<GeoTree>({ units: [], paths: {} })
const options = ref<FacilityOptions>({ facility_type: [], level: [], affiliation: [] })

const form = ref<FacilityInput>({
    name: '',
    code: null,
    geo_unit_id: null,
    facility_type: null,
    level: null,
    affiliation: null,
    address: null,
    contact_name: null,
    contact_phone: null,
    contact_email: null,
    latitude: null,
    longitude: null,
})

const isActive = ref(true)
const saving = ref(false)
const error = ref('')

/** Merging is deliberately behind a confirm: it changes what history points at. */
const merging = ref(false)
const mergeSearch = ref('')
const mergeMatches = ref<Facility[]>([])

const backTo = { name: 'admin-facilities' }

async function act<T>(run: () => Promise<T>): Promise<T | null> {
    error.value = ''

    try {
        return await run()
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.actionFailed')

        return null
    }
}

async function onSave(): Promise<void> {
    if ((form.value.name ?? '').trim() === '') {
        error.value = t('facilityForm.nameRequired')

        return
    }

    saving.value = true

    const wasNew = id.value === null
    const saved = wasNew
        ? await act(() => createFacility(form.value))
        : await act(() => updateFacility(id.value as string, form.value))

    saving.value = false

    if (saved !== null) {
        // Said on the list rather than here, because here is about to stop
        // existing. A facility filed under F lands on page one of a registry
        // ordered by name, where nothing visibly changed.
        flash(t(wasNew ? 'flash.added' : 'flash.saved', { name: saved.name }))
        await router.push(backTo)
    }
}

async function onToggleActive(): Promise<void> {
    if (id.value === null) {
        return
    }

    const saved = await act(() => updateFacility(id.value as string, { is_active: !isActive.value }))

    if (saved !== null) {
        isActive.value = saved.is_active
        flash(t(saved.is_active ? 'flash.activated' : 'flash.deactivated', { name: saved.name }))
    }
}

let mergeTimer: ReturnType<typeof setTimeout> | undefined

function onMergeSearch(): void {
    clearTimeout(mergeTimer)

    mergeTimer = setTimeout(async () => {
        if (mergeSearch.value.trim() === '') {
            mergeMatches.value = []

            return
        }

        await act(async () => {
            mergeMatches.value = (await listFacilities({ search: mergeSearch.value, perPage: 8 }))
                .rows.filter((row) => row.id !== id.value)
        })
    }, 250)
}

async function onMerge(into: Facility): Promise<void> {
    if (id.value === null) {
        return
    }

    const name = form.value.name ?? ''
    const merged = await act(() => mergeFacility(id.value as string, into.id))

    if (merged !== null) {
        flash(t('flash.merged', { name, into: into.name }))
        await router.push(backTo)
    }
}

onMounted(async () => {
    await act(async () => {
        tree.value = await listGeoUnits()
        options.value = await facilityOptions(locale.value)
    })

    if (id.value !== null) {
        const existing = await act(() => getFacility(id.value as string))

        if (existing !== null) {
            isActive.value = existing.is_active
            form.value = {
                name: existing.name,
                code: existing.code,
                geo_unit_id: existing.geo_unit_id,
                facility_type: existing.facility_type,
                level: existing.level,
                affiliation: existing.affiliation,
                address: existing.address,
                contact_name: existing.contact_name,
                contact_phone: existing.contact_phone,
                contact_email: existing.contact_email,
                latitude: existing.latitude,
                longitude: existing.longitude,
            }
        }
    }
})

const inputClass = 'field'
</script>

<template>
    <FormPage
        :title="isNew ? t('facilityForm.addTitle') : (form.name ?? '')"
        :subtitle="isNew ? t('facilityForm.addSubtitle') : t('facilityForm.editSubtitle')"
        :back-to="backTo"
        :saving="saving"
        :error="error"
        @save="onSave"
    >
        <FormField :label="t('facilityForm.name')">
            <input v-model="form.name" type="text" :class="inputClass" />
        </FormField>

        <FormField :label="t('facilityForm.code')" :hint="t('facilityForm.codeHint')">
            <input v-model="form.code" type="text" spellcheck="false" :class="inputClass" />
        </FormField>

        <FormField :label="t('facilityForm.place')" wide>
            <!-- The picker's model is strictly number | null; the form type has
                 every field optional, so the undefined case is normalised here
                 rather than loosening the picker for one caller. -->
            <PlacePicker
                :model-value="form.geo_unit_id ?? null"
                :tree="tree"
                @update:model-value="form.geo_unit_id = $event"
            />
        </FormField>

        <FormField :label="t('facilityForm.type')">
            <select v-model="form.facility_type" :class="inputClass">
                <option :value="null">{{ t('facilityForm.unset') }}</option>
                <option v-for="option in options.facility_type" :key="option.key" :value="option.key">
                    {{ option.label }}
                </option>
            </select>
        </FormField>

        <FormField :label="t('facilityForm.level')">
            <select v-model="form.level" :class="inputClass">
                <option :value="null">{{ t('facilityForm.unset') }}</option>
                <option v-for="option in options.level" :key="option.key" :value="option.key">
                    {{ option.label }}
                </option>
            </select>
        </FormField>

        <FormField :label="t('facilityForm.affiliation')">
            <select v-model="form.affiliation" :class="inputClass">
                <option :value="null">{{ t('facilityForm.unset') }}</option>
                <option v-for="option in options.affiliation" :key="option.key" :value="option.key">
                    {{ option.label }}
                </option>
            </select>
        </FormField>

        <FormField :label="t('facilityForm.address')" wide>
            <textarea v-model="form.address" rows="2" :class="inputClass"></textarea>
        </FormField>

        <FormField :label="t('facilityForm.contactName')" :hint="t('facilityForm.contactHint')">
            <input v-model="form.contact_name" type="text" :class="inputClass" />
        </FormField>

        <FormField :label="t('facilityForm.contactPhone')">
            <input v-model="form.contact_phone" type="tel" :class="inputClass" />
        </FormField>

        <FormField :label="t('facilityForm.contactEmail')">
            <input
                v-model="form.contact_email"
                type="email"
                autocapitalize="off"
                spellcheck="false"
                :class="inputClass"
            />
        </FormField>

        <FormField :label="t('facilityForm.coordinates')" :hint="t('facilityForm.coordinatesHint')">
            <div class="flex gap-2">
                <input
                    v-model.number="form.latitude"
                    type="number"
                    step="0.0000001"
                    :placeholder="t('facilityForm.latitude')"
                    :class="inputClass"
                />
                <input
                    v-model.number="form.longitude"
                    type="number"
                    step="0.0000001"
                    :placeholder="t('facilityForm.longitude')"
                    :class="inputClass"
                />
            </div>
        </FormField>

        <template #actions>
            <template v-if="!isNew">
                <button
                    type="button"
                    class="text-[15px]"
                    :class="isActive ? 'text-no' : 'text-yes'"
                    @click="onToggleActive"
                >
                    {{ isActive ? t('admin.deactivate') : t('admin.activate') }}
                </button>
                <button
                    type="button"
                    class="text-[15px] text-label-2"
                    @click="merging = !merging"
                >
                    {{ t('facilityForm.merge') }}
                </button>
            </template>
        </template>
    </FormPage>

    <!-- Merging is one decision, so it is a confirm rather than a page. -->
    <div
        v-if="merging"
        class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-6"
        role="dialog"
        @click.self="merging = false"
    >
        <div class="mt-[10vh] w-full max-w-[520px] rounded-card bg-surface p-5 shadow-lg">
            <h2 class="text-[18px] font-bold">{{ t('facilityForm.mergeTitle') }}</h2>
            <p class="mt-1 text-[15px] text-label-2">{{ t('facilityForm.mergeExplain') }}</p>

            <input
                v-model="mergeSearch"
                type="search"
                :placeholder="t('facilityForm.mergeFind')"
                :class="[inputClass, 'mt-3']"
                @input="onMergeSearch"
            />

            <div class="mt-2 max-h-[240px] overflow-y-auto">
                <button
                    v-for="candidate in mergeMatches"
                    :key="candidate.id"
                    type="button"
                    class="flex w-full flex-col items-start rounded-lg px-3 py-2 text-left hover:bg-ground"
                    @click="onMerge(candidate)"
                >
                    <span class="text-[16px]">{{ candidate.name }}</span>
                    <span class="text-[13px] text-label-2">{{ candidate.place }}</span>
                </button>
            </div>

            <button
                type="button"
                class="mt-3 text-[15px] text-label-2"
                @click="merging = false"
            >
                {{ t('action.cancel') }}
            </button>
        </div>
    </div>
</template>
