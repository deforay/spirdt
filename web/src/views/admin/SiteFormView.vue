<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { createTestingSite, getTestingSite, updateTestingSite } from '@/api/registry'
import { type AssessmentRow, listAssessments } from '@/api/reports'
import { can, PERMISSION } from '@/auth/permissions'
import FacilityPicker from '@/components/admin/FacilityPicker.vue'
import FormField from '@/components/admin/FormField.vue'
import FormPage from '@/components/admin/FormPage.vue'
import SitePhoto from '@/components/admin/SitePhoto.vue'
import { flash } from '@/composables/useFlash'
import { t } from '@/i18n'

/**
 * One testing site — the bench an assessment is actually made against.
 *
 * Its facility can be changed. A bench recorded under the wrong building is a
 * real mistake, and moving it is safe: assessments reference the SITE, so its
 * history travels with it rather than being stranded.
 *
 * The screen also answers "where is this, and what does it look like", which
 * the fields above cannot. A name and a line of free text are what somebody
 * typed; the coordinates are where an assessor was standing and the photograph
 * is the bench itself. Both are shown here rather than only on the dashboard
 * because this is the page somebody opens when they are trying to work out
 * which bench a row refers to.
 */

/** Leaflet arrives only when this site has something to plot. See DashboardView. */
const AuditMap = defineAsyncComponent(() => import('@/components/admin/AuditMap.vue'))

const route = useRoute()
const router = useRouter()

const id = computed(() => (route.params.id === undefined ? null : String(route.params.id)))
const isNew = computed(() => id.value === null)

const name = ref('')
const location = ref('')
const facilityId = ref('')
const facilityLabel = ref<string | null>(null)
const isActive = ref(true)
const hasPhoto = ref(false)
const saving = ref(false)
const error = ref('')

/** Submitted visits to this bench, for the map. Empty when nobody may read them. */
const visits = ref<AssessmentRow[]>([])

const backTo = { name: 'admin-sites' }

const canEdit = can(PERMISSION.registryWrite)

/**
 * The visits that carry a position.
 *
 * Shaped as the map's points rather than the report's rows, and filtered on
 * having coordinates at all: a visit indoors, in a basement, or on a laptop
 * with no satellite has none, and that is the normal case rather than an
 * error.
 */
const points = computed(() =>
    visits.value
        .filter((row) => row.latitude !== null && row.longitude !== null)
        .map((row) => ({
            id: row.id,
            lat: row.latitude as number,
            lng: row.longitude as number,
            accuracy_m: row.accuracy_m,
            source: row.location_source,
            site: row.site,
            facility: row.facility,
            assessed_on: row.assessed_on ?? '',
            percentage: row.percentage,
            level: row.level,
        })),
)

/**
 * The one worth quoting in words, which is not simply the newest.
 *
 * A position inherited from the facility record is what an administrator
 * typed, and it is the same coordinate for every bench in the building. Where
 * a device fix exists it is preferred however old it is, because it is the
 * only one of the two that says anybody was ever here.
 */
const latest = computed(
    () => points.value.find((point) => point.source === 'device') ?? points.value[0] ?? null,
)

function coordinates(point: { lat: number; lng: number }): string {
    return `${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}`
}

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

    const wasNew = id.value === null
    const saved = wasNew
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
        flash(t(wasNew ? 'flash.added' : 'flash.saved', { name: name.value.trim() }))
        await router.push(wasNew ? { name: 'admin-site', params: { id: saved.id } } : backTo)
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
        flash(t(saved.is_active ? 'flash.activated' : 'flash.deactivated', { name: name.value }))
    }
}

/**
 * Where the audits of this bench happened.
 *
 * Best effort, and deliberately silent when it fails. Somebody may hold the
 * registry without holding the reports — a data clerk maintaining the national
 * list has no business reading scores — and for them this panel is simply not
 * there. An error banner across a screen whose actual job is editing a name
 * would be reporting a permission boundary as a fault.
 */
async function loadVisits(siteId: string): Promise<void> {
    if (!can(PERMISSION.reportsRead)) {
        return
    }

    try {
        // Submitted only, matching the dashboard: a draft is a visit somebody
        // is part-way through, and a pin for one claims a completed audit that
        // has not happened.
        const page = await listAssessments({
            testingSiteId: siteId,
            status: 'submitted',
            perPage: 100,
        })

        visits.value = page.rows
    } catch {
        visits.value = []
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
        hasPhoto.value = existing.has_photo
    }

    await loadVisits(id.value as string)
})

const inputClass = 'field'
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
            <input v-model="name" type="text" :placeholder="t('eg.siteName')" :class="inputClass" />
        </FormField>

        <FormField :label="t('siteForm.facility')">
            <FacilityPicker
                v-model="facilityId"
                :label="facilityLabel"
                @update:label="facilityLabel = $event"
            />
        </FormField>

        <FormField :label="t('siteForm.location')" :hint="t('siteForm.locationHint')" wide>
            <input
                v-model="location"
                type="text"
                :placeholder="t('eg.siteLocation')"
                :class="inputClass"
            />
        </FormField>

        <!-- A section rather than a FormField, because the control inside it is
             itself a label wrapping a file input and labels do not nest. -->
        <section class="flex flex-col gap-1.5 sm:col-span-2">
            <span class="text-[14px] font-medium text-label-2">{{ t('sitePhoto.label') }}</span>

            <SitePhoto
                :site-id="id"
                :has-photo="hasPhoto"
                :site-name="name"
                :editable="canEdit"
                @change="hasPhoto = $event"
            />

            <span class="text-[13px] text-label-3">{{ t('sitePhoto.hint') }}</span>
        </section>

        <section v-if="!isNew" class="flex flex-col gap-1.5 sm:col-span-2">
            <span class="text-[14px] font-medium text-label-2">{{ t('siteForm.visits') }}</span>

            <p v-if="points.length === 0" class="text-[15px] text-label-3">
                {{ t('siteForm.visitsEmpty') }}
            </p>

            <template v-else>
                <AuditMap :points="points" height="300px" />

                <p v-if="latest !== null" class="text-[14px] text-label-2">
                    <!-- The numbers as well as the pin. A coordinate is what
                         gets pasted into a vehicle's navigation or a report,
                         and a map cannot be copied. -->
                    <span class="tabular-nums">{{ coordinates(latest) }}</span>
                    <span v-if="latest.accuracy_m !== null">
                        · {{ t('siteForm.accuracy', { metres: latest.accuracy_m }) }}
                    </span>
                    ·
                    {{ latest.source === 'facility' ? t('map.facility') : t('map.device') }}
                </p>

                <span class="text-[13px] text-label-3">{{ t('siteForm.visitsHint') }}</span>
            </template>
        </section>

        <template #actions>
            <button
                v-if="!isNew"
                type="button"
                class="text-[15px]"
                :class="isActive ? 'text-no' : 'text-yes'"
                @click="onToggleActive"
            >
                {{ isActive ? t('admin.deactivate') : t('admin.activate') }}
            </button>
        </template>
    </FormPage>
</template>
