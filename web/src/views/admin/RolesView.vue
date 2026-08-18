<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { type AdminRole, listRoles, updateRolePermissions } from '@/api/admin'
import { session } from '@/auth/session'
import { PERMISSION } from '@/auth/permissions'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t, type MessageKey } from '@/i18n'

/**
 * What each role may do.
 *
 * A MATRIX RATHER THAN A PAGE PER ROLE. The question anybody opens this screen
 * with is comparative — "who can change the registry?" — and five separate
 * pages answer it only by being read five times and held in the head. Roles
 * across the top, capabilities down the side, so the answer is a row.
 *
 * Nothing here is the control. Every one of these boxes is re-checked by the
 * API against the same three guards, and the screen's job is to not offer what
 * would be refused: a box that cannot be ticked says why in its tooltip rather
 * than accepting a click and returning an error a second later.
 *
 * Saving sends the whole set for a role rather than the difference, and only
 * for the roles that actually changed. Two administrators editing at once
 * therefore last-write-wins per role instead of interleaving into a state
 * neither of them chose.
 */

const roles = ref<AdminRole[]>([])
const catalogue = ref<string[]>([])
const grantable = ref<string[]>([])

/** Role id → the permissions currently ticked. The edit buffer. */
const draft = ref<Map<number, Set<string>>>(new Map())

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)

const myRole = computed(() => session.value?.user.role ?? '')

async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        const body = await listRoles()

        roles.value = body.roles
        catalogue.value = body.catalogue
        grantable.value = body.grantable
        draft.value = new Map(body.roles.map((role) => [role.id, new Set(role.permissions)]))
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')
    } finally {
        loading.value = false
    }
}

function ticked(role: AdminRole, permission: string): boolean {
    return draft.value.get(role.id)?.has(permission) === true
}

/**
 * Why this box cannot be touched, or an empty string when it can.
 *
 * Three reasons, in the order the API checks them, so the message a person
 * reads is the one they would have been sent.
 */
function lockedBecause(role: AdminRole, permission: string): string {
    if (!role.editable) {
        return t('roles.notEditable')
    }

    if (
        permission === PERMISSION.rolesManage &&
        role.key === myRole.value &&
        ticked(role, permission)
    ) {
        return t('roles.ownLock')
    }

    // Only when it would be a grant. Taking away something you do not hold
    // yourself is allowed — it makes the organisation no more powerful.
    if (!grantable.value.includes(permission) && !ticked(role, permission)) {
        return t('roles.cannotGrant')
    }

    return ''
}

function toggle(role: AdminRole, permission: string): void {
    if (lockedBecause(role, permission) !== '') {
        return
    }

    const held = draft.value.get(role.id)

    if (held === undefined) {
        return
    }

    if (held.has(permission)) {
        held.delete(permission)
    } else {
        held.add(permission)
    }

    // The Map holds Sets, and mutating a Set inside it is invisible to Vue.
    draft.value = new Map(draft.value)
    saved.value = false
}

/** The roles whose ticks no longer match what the server returned. */
const changed = computed(() =>
    roles.value.filter((role) => {
        const held = draft.value.get(role.id)

        if (held === undefined) {
            return false
        }

        return (
            held.size !== role.permissions.length ||
            role.permissions.some((permission) => !held.has(permission))
        )
    }),
)

async function save(): Promise<void> {
    if (changed.value.length === 0 || saving.value) {
        return
    }

    saving.value = true
    error.value = ''
    saved.value = false

    try {
        for (const role of changed.value) {
            const updated = await updateRolePermissions(role.id, [
                ...(draft.value.get(role.id) ?? []),
            ])

            // Replaced with what came back rather than with what was sent, so
            // the screen shows what was stored.
            roles.value = roles.value.map((row) => (row.id === updated.id ? updated : row))
            draft.value.set(updated.id, new Set(updated.permissions))
        }

        draft.value = new Map(draft.value)
        saved.value = true
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')

        // Something was refused, and anything before it in the loop was not.
        // Re-reading is the only way to say truthfully what the roles now hold.
        await load()
    } finally {
        saving.value = false
    }
}

/**
 * A permission this version has no wording for still appears, by its key.
 *
 * The catalogue comes from the server, so an installation running a newer API
 * than its bundle would otherwise silently hide a capability that is real.
 */
function label(permission: string): string {
    const key = `perm.${permission}` as MessageKey
    const translated = t(key)

    return translated === key ? permission : translated
}

onMounted(load)
</script>

<template>
    <AdminShell :title="t('roles.title')" :subtitle="t('roles.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <p v-if="loading" class="text-[16px] text-label-2">{{ t('admin.loading') }}</p>

        <template v-else>
            <div class="data-card data-scroll">
                <table class="data-table min-w-[720px]">
                    <thead>
                        <tr>
                            <th>{{ t('admin.role') }}</th>
                            <th
                                v-for="role in roles"
                                :key="role.id"
                                class="text-center font-semibold"
                            >
                                <span class="block normal-case text-[15px] text-label">
                                    {{ role.name }}
                                </span>
                                <span class="block text-[12px] font-normal normal-case text-label-3">
                                    {{
                                        role.user_count === 0
                                            ? t('roles.nobody')
                                            : `${role.user_count} ${t('roles.people')}`
                                    }}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="permission in catalogue"
                            :key="permission"
                        >
                            <td class="text-[16px]">{{ label(permission) }}</td>
                            <td
                                v-for="role in roles"
                                :key="role.id"
                                class="text-center"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4 accent-accent disabled:opacity-30"
                                    :checked="ticked(role, permission)"
                                    :disabled="lockedBecause(role, permission) !== ''"
                                    :title="lockedBecause(role, permission)"
                                    :aria-label="`${label(permission)} — ${role.name}`"
                                    @change="toggle(role, permission)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-end gap-3">
                <span v-if="saved" class="text-[15px] text-label-2">{{ t('roles.saved') }}</span>
                <span v-else-if="changed.length === 0" class="text-[15px] text-label-3">
                    {{ t('roles.noChanges') }}
                </span>

                <button
                    type="button"
                    class="rounded-full bg-accent px-4 py-2 text-[15px] font-semibold text-accent-ink disabled:opacity-40"
                    :disabled="changed.length === 0 || saving"
                    @click="save"
                >
                    {{ saving ? t('roles.saving') : t('roles.save') }}
                </button>
            </div>
        </template>
    </AdminShell>
</template>
