<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { type AuditFilters, type AuditRow, listAudit } from '@/api/admin'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t, type MessageKey } from '@/i18n'

/**
 * What has been done in this organisation.
 *
 * READ ONLY, AND THERE IS NO WAY TO MAKE IT OTHERWISE. There is no edit
 * control here and no endpoint behind one. A row is written by the service
 * that performed the action, from an actor taken off a verified token, and its
 * whole value is that nobody — including whoever is reading this screen — can
 * change what it says afterwards.
 *
 * Rows accumulate rather than paginate. Somebody reading a trail is following
 * a thread backwards in time, and a page control makes them lose their place
 * every time they reach the bottom. Filters reset the list; "show more"
 * extends it.
 *
 * The session column is eight characters of a sixty-four character hash. It is
 * a correlator and not a secret, but it is there to be compared by eye —
 * clicking one filters to everything that session did, which is the question
 * this screen exists to answer.
 */

const rows = ref<AuditRow[]>([])
const actions = ref<string[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(true)
const error = ref('')

const action = ref('')
const from = ref('')
const to = ref('')
const sessionHash = ref('')

const filters = computed<AuditFilters>(() => ({
    action: action.value,
    from: from.value,
    to: to.value,
    session_hash: sessionHash.value,
}))

const hasFilters = computed(
    () => action.value !== '' || from.value !== '' || to.value !== '' || sessionHash.value !== '',
)

const more = computed(() => rows.value.length < total.value)

async function load(append = false): Promise<void> {
    loading.value = true
    error.value = ''

    try {
        const body = await listAudit({ ...filters.value, page: page.value })

        rows.value = append ? [...rows.value, ...body.rows] : body.rows
        total.value = body.total
        actions.value = body.actions
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')
    } finally {
        loading.value = false
    }
}

async function showMore(): Promise<void> {
    page.value += 1
    await load(true)
}

/** Any filter change starts the list again from the top. */
watch(filters, () => {
    page.value = 1
    void load()
})

function focusSession(row: AuditRow): void {
    sessionHash.value = row.session_hash ?? ''
}

function clear(): void {
    action.value = ''
    from.value = ''
    to.value = ''
    sessionHash.value = ''
}

/**
 * An action this bundle has no wording for still appears, by its key.
 *
 * The list of actions comes from the server, so an installation running a
 * newer API than its bundle would otherwise show blank rows for the actions it
 * has not heard of — which is the opposite of what an audit trail is for.
 */
function label(value: string): string {
    const key = `action.${value}` as MessageKey
    const translated = t(key)

    return translated === key ? value : translated
}

/** Who acted. A removed account still has to read as somebody. */
function actor(row: AuditRow): string {
    if (row.actor_name !== null && row.actor_name !== '') {
        return row.actor_name
    }

    if (row.actor_id === null) {
        return t('audit.system')
    }

    return t('audit.deletedActor', { id: row.actor_id })
}

/**
 * The metadata, flattened to something readable in a table cell.
 *
 * Rendered generically rather than per action. A switch over twelve actions is
 * twelve things to remember to update, and the thirteenth would silently show
 * nothing — on the one screen whose purpose is that nothing is silent.
 */
function detail(row: AuditRow): string {
    if (row.metadata === null) {
        return ''
    }

    return Object.entries(row.metadata)
        .filter(([, value]) => value !== null && value !== '' && !(Array.isArray(value) && value.length === 0))
        .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : String(value)}`)
        .join(' · ')
}

function when(value: string): string {
    const parsed = new Date(value.replace(' ', 'T') + 'Z')

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
}

onMounted(() => load())
</script>

<template>
    <AdminShell :title="t('audit.title')" :subtitle="t('audit.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[14px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-[12px] uppercase tracking-wide text-label-2">{{ t('audit.what') }}</span>
                <select
                    v-model="action"
                    class="min-w-[220px] rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
                >
                    <option value="">{{ t('audit.allActions') }}</option>
                    <option v-for="value in actions" :key="value" :value="value">
                        {{ label(value) }}
                    </option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[12px] uppercase tracking-wide text-label-2">{{ t('audit.from') }}</span>
                <input
                    v-model="from"
                    type="date"
                    class="rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
                />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[12px] uppercase tracking-wide text-label-2">{{ t('audit.to') }}</span>
                <input
                    v-model="to"
                    type="date"
                    class="rounded-lg bg-surface px-3 py-2 text-[15px] outline-none"
                />
            </label>

            <button
                v-if="hasFilters"
                type="button"
                class="rounded-full px-3 py-2 text-[14px] text-accent"
                @click="clear"
            >
                {{ t('audit.clear') }}
            </button>
        </div>

        <p v-if="loading && rows.length === 0" class="text-[15px] text-label-2">
            {{ t('admin.loading') }}
        </p>

        <p v-else-if="rows.length === 0" class="rounded-card bg-surface px-4 py-3 text-[15px] text-label-2">
            {{ t('audit.none') }}
        </p>

        <template v-else>
            <div class="overflow-x-auto rounded-card bg-surface">
                <table class="w-full min-w-[860px] text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[12px] uppercase tracking-wide text-label-2">
                            <th class="px-4 py-2.5 font-semibold">{{ t('audit.when') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ t('audit.who') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ t('audit.what') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ t('audit.details') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ t('audit.session') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-hairline last:border-0">
                            <td class="tnum whitespace-nowrap px-4 py-3 text-[13px] text-label-2">
                                {{ when(row.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="block text-[15px]">{{ actor(row) }}</span>
                                <span v-if="row.actor_email" class="block text-[12px] text-label-3">
                                    {{ row.actor_email }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-[14px]">{{ label(row.action) }}</td>
                            <td class="px-4 py-3 text-[13px] text-label-2">
                                <span class="block">{{ detail(row) }}</span>
                                <span v-if="row.ip_address" class="block text-[12px] text-label-3">
                                    {{ row.ip_address
                                    }}<template v-if="row.browser"> · {{ row.browser }}</template
                                    ><template v-if="row.platform"> · {{ row.platform }}</template>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    v-if="row.session"
                                    type="button"
                                    class="tnum font-mono text-[12px] text-accent"
                                    @click="focusSession(row)"
                                >
                                    {{ row.session }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-[13px] text-label-3">
                    {{ t('audit.showing', { shown: rows.length, total }) }}
                </span>

                <button
                    v-if="more"
                    type="button"
                    class="rounded-full bg-surface px-4 py-2 text-[14px] font-medium disabled:opacity-40"
                    :disabled="loading"
                    @click="showMore"
                >
                    {{ loading ? t('admin.loading') : t('audit.more') }}
                </button>
            </div>
        </template>
    </AdminShell>
</template>
