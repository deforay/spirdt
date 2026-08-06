<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { createTestingSite, getTestingSite, updateTestingSite } from '@/api/registry'
import FacilityPicker from '@/components/admin/FacilityPicker.vue'
import FormField from '@/components/admin/FormField.vue'
import FormPage from '@/components/admin/FormPage.vue'
import { t } from '@/i18n'

/**
 * One testing site — the bench an assessment is actually made against.
 *
 * Its facility can be changed. A bench recorded under the wrong building is a
 * real mistake, and moving it is safe: assessments reference the SITE, so its
 * history travels with it rather than being stranded.
 */

const route = useRoute()
const router = useRouter()

const id = computed(() => (route.params.id === undefined ? null : String(route.params.id)))
const isNew = computed(() => id.value === null)

const name = ref('')
const location = ref('')
const facilityId = ref('')
const facilityLabel = ref<string | null>(null)
const isActive = ref(true)
const saving = ref(false)
const error = ref('')

const backTo = { name: 'admin-sites' }

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
    if (name.value.trim() === '' || facilityId.value === '') {
        error.value = t('siteForm.required')

        return
    }

    saving.value = true

    const saved =
        id.value === null
            ? await act(() =>
                  createTestingSite({
                      name: name.value.trim(),
                      facility_id: facilityId.value,
                      location_description: location.value.trim() || null,
                  }),
              )
            : await act(() =>
                  updateTestingSite(id.value as string, {
                      name: name.value.trim(),
                      location_description: location.value.trim() || null,
                      facility_id: facilityId.value,
                  }),
              )

    saving.value = false

    if (saved !== null) {
        await router.push(backTo)
    }
}

async function onToggleActive(): Promise<void> {
    if (id.value === null) {
        return
    }

    const saved = await act(() =>
        updateTestingSite(id.value as string, { is_active: !isActive.value }),
    )

    if (saved !== null) {
        isActive.value = saved.is_active
    }
}

onMounted(async () => {
    if (id.value === null) {
        return
    }

    const existing = await act(() => getTestingSite(id.value as string))

    if (existing !== null) {
        name.value = existing.name
        location.value = existing.location_description ?? ''
        facilityId.value = existing.facility_id
        facilityLabel.value = existing.facility_name
        isActive.value = existing.is_active
    }
})

const inputClass =
    'w-full rounded-lg bg-ground px-3 py-2 text-[15px] outline-none placeholder:text-label-3'
</script>

<template>
    <FormPage
        :title="isNew ? t('siteForm.addTitle') : name"
        :subtitle="t('siteForm.subtitle')"
        :back-to="backTo"
        :saving="saving"
        :error="error"
        @save="onSave"
    >
        <FormField :label="t('siteForm.name')" :hint="t('siteForm.nameHint')">
            <input v-model="name" type="text" :class="inputClass" />
        </FormField>

        <FormField :label="t('siteForm.facility')">
            <FacilityPicker
                v-model="facilityId"
                :label="facilityLabel"
                @update:label="facilityLabel = $event"
            />
        </FormField>

        <FormField :label="t('siteForm.location')" :hint="t('siteForm.locationHint')" wide>
            <input v-model="location" type="text" :class="inputClass" />
        </FormField>

        <template #actions>
            <button
                v-if="!isNew"
                type="button"
                class="text-[14px]"
                :class="isActive ? 'text-no' : 'text-yes'"
                @click="onToggleActive"
            >
                {{ isActive ? t('admin.deactivate') : t('admin.activate') }}
            </button>
        </template>
    </FormPage>
</template>
