<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { createGeoUnit, type GeoTree, listGeoUnits, updateGeoUnit } from '@/api/registry'
import FormField from '@/components/admin/FormField.vue'
import FormPage from '@/components/admin/FormPage.vue'
import PlacePicker from '@/components/admin/PlacePicker.vue'
import { flash } from '@/composables/useFlash'
import { t } from '@/i18n'

/**
 * One place in the hierarchy.
 *
 * `level` is free text and stays that way: the tiering is a per-country fact,
 * and an enum here would need a migration for the second country. The field
 * suggests whatever siblings already use so nobody types "District" forty
 * times, and stays editable because the first child at any depth has no
 * sibling to copy from.
 */

const route = useRoute()
const router = useRouter()

const id = computed(() => (route.params.id === undefined ? null : Number(route.params.id)))
const isNew = computed(() => id.value === null)

const tree = ref<GeoTree>({ units: [], paths: {} })
const name = ref('')
const level = ref('')
const parentId = ref<number | null>(null)
const isActive = ref(true)
const saving = ref(false)
const error = ref('')

const backTo = { name: 'admin-places' }

/** Whatever the siblings under this parent are called. */
const suggestedLevel = computed(
    () => tree.value.units.find((unit) => unit.parent_id === parentId.value)?.level ?? '',
)

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
    const chosenLevel = level.value.trim() || suggestedLevel.value

    if (name.value.trim() === '' || chosenLevel === '') {
        error.value = t('placeForm.required')

        return
    }

    saving.value = true

    const wasNew = id.value === null
    const saved = wasNew
        ? await act(() =>
              createGeoUnit({
                  name: name.value.trim(),
                  level: chosenLevel,
                  parent_id: parentId.value,
              }),
          )
        : await act(() =>
              updateGeoUnit(id.value as number, {
                  name: name.value.trim(),
                  level: chosenLevel,
              }),
          )

    saving.value = false

    if (saved !== null) {
        flash(t(wasNew ? 'flash.added' : 'flash.saved', { name: name.value.trim() }))
        await router.push(backTo)
    }
}

async function onToggleActive(): Promise<void> {
    if (id.value === null) {
        return
    }

    const saved = await act(() => updateGeoUnit(id.value as number, { is_active: !isActive.value }))

    if (saved !== null) {
        isActive.value = saved.is_active
        flash(t(saved.is_active ? 'flash.activated' : 'flash.deactivated', { name: name.value }))
    }
}

onMounted(async () => {
    await act(async () => {
        tree.value = await listGeoUnits()
    })

    if (route.query.parent !== undefined) {
        parentId.value = Number(route.query.parent)
    }

    if (id.value === null) {
        return
    }

    const existing = tree.value.units.find((unit) => unit.id === id.value)

    if (existing !== undefined) {
        name.value = existing.name
        level.value = existing.level
        parentId.value = existing.parent_id
        isActive.value = existing.is_active
    }
})

const inputClass = 'field'
</script>

<template>
    <FormPage
        :title="isNew ? t('placeForm.addTitle') : name"
        :subtitle="t('placeForm.subtitle')"
        :back-to="backTo"
        :saving="saving"
        :error="error"
        @save="onSave"
    >
        <FormField :label="t('placeForm.name')">
            <input v-model="name" type="text" :placeholder="t('eg.placeName')" :class="inputClass" />
        </FormField>

        <FormField :label="t('placeForm.level')" :hint="t('placeForm.levelHint')">
            <input
                v-model="level"
                type="text"
                :placeholder="suggestedLevel || t('eg.placeLevel')"
                :class="inputClass"
            />
        </FormField>

        <FormField :label="t('placeForm.parent')" :hint="t('placeForm.parentHint')" wide>
            <!-- Only when adding: moving a place would take every facility
                 under it somewhere else, which is a different operation from
                 correcting its name. -->
            <PlacePicker
                v-if="isNew"
                :model-value="parentId"
                :tree="tree"
                :placeholder="t('placeForm.topLevel')"
                @update:model-value="parentId = $event"
            />
            <span v-else class="text-[16px] text-label-2">
                {{ parentId === null ? t('placeForm.topLevel') : tree.paths[parentId] }}
            </span>
        </FormField>

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
