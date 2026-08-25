<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { listRequests, type RequestFilters, type RequestRow } from '@/api/admin'
import AdminShell from '@/components/admin/AdminShell.vue'
import { t } from '@/i18n'

/**
 * What the server has been asked.
 *
 * THE DIAGNOSTIC, NOT THE EVIDENCE. Its sibling screen — Activity — is the
 * audit trail: a narrow record of things with consequences, written once and
 * kept. This is every request the API handled, pruned by housekeeping, and it
 * exists to answer the question the trail cannot: not "what was done" but
 * "what was asked, and what did the server say back".
 *
 * It was built because that question had to be answered with a hand-written
 * database script. A sign-in appeared to do nothing; the table already knew
 * the request had succeeded and that the browser had gone quiet afterwards,
 * which is the whole diagnosis, and there was no way to look.
 *
 * Rows accumulate rather than paginate, and filters reset the list — the same
 * shape as the trail, because it is the same act of following a thread
 * backwards and a page control loses your place every time you reach the
 * bottom.
 *
 * THE SESSION IS THE THREAD. Clicking one shows every request that session
 * made, and that view alone includes the requests made before it was signed in
 * — signing in, refreshing, the ones that answered 401 — because those carry
 * no organisation and are reachable no other way. The server decides whether
 * the session is this organisation's before widening; see
 * RequestLogReadService.
 */

const rows = ref<RequestRow[]>([])
const methods = ref<string[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref('')

/**
 * Which load is current.
 *
 * Filters change faster than the server answers and the earlier request is not
 * guaranteed to finish first, so without this a response for a filter somebody
 * has moved on from lands last and replaces the table — leaving the controls
 * saying one thing and the rows showing another.
 */
let generation = 0

const method = ref('')
const status = ref('')
const path = ref('')
const sessionHash = ref('')
const from = ref('')
const to = ref('')

/** Which row is open. One at a time: two expanded rows cannot be compared anyway. */
const opened = ref<number | null>(null)

const filters = computed<RequestFilters>(() => ({
    method: method.value,
    status: status.value,
    path: path.value,
    session_hash: sessionHash.value,
    from: from.value,
    to: to.value,
}))

const hasFilters = computed(() =>
    Object.values(filters.value).some((value) => value !== '' && value !== undefined),
)

const more = computed(() => rows.value.length < total.value)

async function load(append = false): Promise<void> {
    const mine = ++generation

    loading.value = true
    error.value = ''

    try {
        const body = await listRequests({
            ...filters.value,
            // The oldest row already shown, so the server walks back from it.
            ...(append && rows.value.length > 0
                ? { before_id: rows.value[rows.value.length - 1]!.id }
                : {}),
        })

        if (mine !== generation) {
            return
        }

        rows.value = append ? [...rows.value, ...body.rows] : body.rows
        total.value = body.total
        methods.value = body.methods
    } catch (caught) {
        if (mine !== generation) {
            return
        }

        error.value = caught instanceof Error ? caught.message : t('admin.loadFailed')
    } finally {
        if (mine === generation) {
            loading.value = false
        }
    }
}

async function showMore(): Promise<void> {
    await load(true)
}

/** Any filter change starts the list again from the newest row. */
watch(filters, () => {
    opened.value = null
    void load()
})

function focusSession(row: RequestRow): void {
    sessionHash.value = row.session_hash ?? ''
}

function toggle(row: RequestRow): void {
    opened.value = opened.value === row.id ? null : row.id
}

function clear(): void {
    method.value = ''
    status.value = ''
    path.value = ''
    sessionHash.value = ''
    from.value = ''
    to.value = ''
}

/**
 * Colour by outcome, not by exact code.
 *
 * Three states are all a reader is scanning for: it worked, the caller was
 * refused, the server broke. A palette per status code would be a legend
 * nobody reads.
 */
function tone(code: number): string {
    if (code >= 500) {
        return 'bg-no-soft text-no'
    }

    if (code >= 400) {
        return 'bg-partial-soft text-partial'
    }

    return 'bg-yes-soft text-yes'
}

/**
 * Slow enough to be worth noticing.
 *
 * A number in a column is only useful if something tells you which numbers
 * matter. A second is the point at which an assessor on a weak uplink starts
 * wondering whether the tap registered.
 */
function slow(row: RequestRow): boolean {
    return row.duration_ms !== null && row.duration_ms >= 1000
}

/** Who made it. A request made before signing in was made by nobody yet. */
function who(row: RequestRow): string {
    if (row.user_name !== null && row.user_name !== '') {
        return row.user_name
    }

    return row.user_id === null ? t('requests.unauthenticated') : t('audit.deletedActor', { id: row.user_id })
}

/**
 * The body, laid out rather than printed as one line.
 *
 * Stored as JSON and shown as JSON: this is a diagnostic screen and the shape
 * is part of what is being diagnosed. Anything unparseable is shown as it was
 * stored — a truncated body is still worth reading, and the middleware
 * truncates at eight kilobytes.
 */
function body(row: RequestRow): string {
    if (row.body === null || row.body === '') {
        return ''
    }

    try {
        return JSON.stringify(JSON.parse(row.body), null, 2)
    } catch {
        return row.body
    }
}

function when(value: string): string {
    const parsed = new Date(value.replace(' ', 'T') + 'Z')

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
}

onMounted(() => load())
</script>

<template>
    <AdminShell :title="t('requests.title')" :subtitle="t('requests.subtitle')">
        <p v-if="error !== ''" class="mb-4 text-[15px] font-medium text-no">{{ error }}</p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-[13px] uppercase tracking-wide text-label-2">
                    {{ t('requests.outcome') }}
                </span>
                <select v-model="status" class="field w-auto min-w-[170px]">
                    <option value="">{{ t('requests.allOutcomes') }}</option>
                    <option value="failed">{{ t('requests.failed') }}</option>
                    <option value="4xx">{{ t('requests.refused') }}</option>
                    <option value="5xx">{{ t('requests.broke') }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[13px] uppercase tracking-wide text-label-2">
                    {{ t('requests.method') }}
                </span>
                <select v-model="method" class="field w-auto min-w-[130px]">
                    <option value="">{{ t('requests.allMethods') }}</option>
                    <option v-for="value in methods" :key="value" :value="value">{{ value }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[13px] uppercase tracking-wide text-label-2">
                    {{ t('requests.path') }}
                </span>
                <input v-model="path" type="search" class="field w-auto min-w-[200px]" placeholder="eg. /sync" />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[13px] uppercase tracking-wide text-label-2">{{ t('audit.from') }}</span>
                <input v-model="from" type="date" class="field w-auto" />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[13px] uppercase tracking-wide text-label-2">{{ t('audit.to') }}</span>
                <input v-model="to" type="date" class="field w-auto" />
            </label>

            <button
                v-if="hasFilters"
                type="button"
                class="rounded-full px-3 py-2 text-[15px] text-accent"
                @click="clear"
            >
                {{ t('audit.clear') }}
            </button>
        </div>

        <!--
            Said out loud, because it changes what the list contains. Following
            a session is the only view that shows the requests made before
            anybody was signed in, and a reader who does not know that will
            wonder where the sign-in went when they clear the filter.
        -->
        <p
            v-if="sessionHash !== ''"
            class="mb-4 rounded-card bg-accent-soft px-4 py-3 text-[14px] leading-snug text-label-2"
        >
            {{ t('requests.followingSession') }}
        </p>

        <p v-if="loading && rows.length === 0" class="text-[16px] text-label-2">
            {{ t('admin.loading') }}
        </p>

        <p
            v-else-if="rows.length === 0"
            class="rounded-surface border border-hairline bg-surface px-5 py-4 text-[16px] text-label-2"
        >
            {{ t('requests.none') }}
        </p>

        <template v-else>
            <div class="data-card data-scroll">
                <table class="data-table min-w-[900px]">
                    <thead>
                        <tr>
                            <th>{{ t('audit.when') }}</th>
                            <th>{{ t('requests.request') }}</th>
                            <th>{{ t('requests.answer') }}</th>
                            <th>{{ t('requests.took') }}</th>
                            <th>{{ t('audit.who') }}</th>
                            <th>{{ t('audit.session') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.id">
                            <tr>
                                <td class="tnum whitespace-nowrap px-5 py-4 text-[14px] text-label-2">
                                    {{ when(row.created_at) }}
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="text-left"
                                        :aria-expanded="opened === row.id"
                                        @click="toggle(row)"
                                    >
                                        <span class="font-mono text-[13px] text-label-3">{{ row.method }}</span>
                                        <span class="ml-2 text-[15px]">{{ row.path }}</span>
                                    </button>
                                </td>
                                <td>
                                    <span
                                        class="tnum inline-block rounded-full px-2.5 py-1 text-[13px] font-semibold"
                                        :class="tone(row.status)"
                                    >
                                        {{ row.status }}
                                    </span>
                                </td>
                                <td class="tnum whitespace-nowrap text-[14px]" :class="slow(row) ? 'text-partial' : 'text-label-2'">
                                    {{ row.duration_ms === null ? '' : `${row.duration_ms} ms` }}
                                </td>
                                <td>
                                    <span class="block text-[15px]">{{ who(row) }}</span>
                                    <span v-if="row.user_email" class="block text-[13px] text-label-3">
                                        {{ row.user_email }}
                                    </span>
                                </td>
                                <td>
                                    <button
                                        v-if="row.session"
                                        type="button"
                                        class="tnum font-mono text-[13px] text-accent"
                                        @click="focusSession(row)"
                                    >
                                        {{ row.session }}
                                    </button>
                                </td>
                            </tr>

                            <!--
                                Everything that does not earn a column of its
                                own. It is all per-request rather than
                                per-reader, so it belongs under the row it
                                describes rather than in a panel beside it.
                            -->
                            <tr v-if="opened === row.id">
                                <td colspan="6" class="bg-ground px-5 py-4">
                                    <dl class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                                        <div v-if="row.request_uid">
                                            <dt class="text-[13px] text-label-3">{{ t('requests.uid') }}</dt>
                                            <dd class="font-mono text-[13px]">{{ row.request_uid }}</dd>
                                        </div>
                                        <div v-if="row.ip_address">
                                            <dt class="text-[13px] text-label-3">{{ t('requests.origin') }}</dt>
                                            <dd class="text-[14px]">
                                                {{ row.ip_address
                                                }}<template v-if="row.browser"> · {{ row.browser }}</template
                                                ><template v-if="row.platform"> · {{ row.platform }}</template>
                                            </dd>
                                        </div>
                                        <div v-if="row.device_id">
                                            <dt class="text-[13px] text-label-3">{{ t('requests.device') }}</dt>
                                            <dd class="font-mono text-[13px]">{{ row.device_id }}</dd>
                                        </div>
                                        <div v-if="row.app_version">
                                            <dt class="text-[13px] text-label-3">{{ t('requests.appVersion') }}</dt>
                                            <dd class="text-[14px]">{{ row.app_version }}</dd>
                                        </div>
                                    </dl>

                                    <template v-if="body(row) !== ''">
                                        <p class="mt-4 text-[13px] text-label-3">{{ t('requests.body') }}</p>
                                        <!--
                                            Scrolls inside its own box. A sync
                                            payload is the largest body this API
                                            takes and it must not be allowed to
                                            widen the page under the table.
                                        -->
                                        <pre class="scroll-thin mt-1 max-h-64 overflow-auto rounded-card bg-surface p-3 font-mono text-[12px] leading-relaxed">{{ body(row) }}</pre>
                                    </template>

                                    <p v-if="row.user_agent" class="mt-3 break-all text-[12px] text-label-3">
                                        {{ row.user_agent }}
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-[14px] text-label-3">
                    {{ t('audit.showing', { shown: rows.length, total }) }}
                </span>

                <button
                    v-if="more"
                    type="button"
                    class="rounded-full bg-surface px-4 py-2 text-[15px] font-medium disabled:opacity-40"
                    :disabled="loading"
                    @click="showMore"
                >
                    {{ loading ? t('admin.loading') : t('audit.more') }}
                </button>
            </div>
        </template>
    </AdminShell>
</template>
