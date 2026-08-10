import { accessTokenExpired, clearSession, deviceId, saveSession, session } from '../auth/session'

/**
 * Every call to the server goes through here.
 *
 * The job is to turn four different kinds of failure into something the caller
 * can act on, because the sync engine's whole behaviour depends on telling them
 * apart:
 *
 *   status 0    no network, or the request never arrived. Retry.
 *   401/403     the session is the problem. Refresh, then retry once.
 *   409/422     the payload is the problem. Retrying sends the same thing.
 *   5xx         our fault. Retry later.
 *
 * A device that cannot tell these apart either retries a permanently refused
 * payload forever — and so never syncs anything behind it — or gives up on a
 * flat tyre.
 */

const BASE_URL = String(import.meta.env.VITE_API_BASE ?? '/api').replace(/\/$/, '')

/** Long enough for a slow uplink, short enough to notice a dead one. */
const TIMEOUT_MS = 30_000

export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly retryAfter: number | null = null,
    ) {
        super(message)
        this.name = 'ApiError'
    }

    /** Whether sending exactly the same thing again could ever work. */
    get retryable(): boolean {
        return this.status === 0 || this.status === 408 || this.status === 429 || this.status >= 500
    }
}

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
    /**
     * Serialised as JSON, unless it is a FormData.
     *
     * An upload carries its own encoding, and the browser has to be the one to
     * write the Content-Type — the header has to name the multipart boundary
     * it chose, and setting it by hand is the classic way to produce a request
     * the server cannot parse.
     */
    body?: unknown
    /** Send the access token, and refresh it once if the server rejects it. */
    auth?: boolean
    signal?: AbortSignal
    /** Overrides the default. Uploads take longer than anything else here. */
    timeoutMs?: number
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const { auth = true } = options

    if (auth && accessTokenExpired()) {
        // Expired by the clock. Refreshing now saves a round trip that was
        // always going to come back 401, which on a weak uplink is the
        // difference between one slow request and two.
        await refresh()
    }

    let response = await send(path, options)

    if (response.status === 401 && auth) {
        const refreshed = await refresh()

        if (refreshed) {
            response = await send(path, options)
        }
    }

    return interpret<T>(response)
}

async function send(path: string, options: RequestOptions): Promise<Response> {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? TIMEOUT_MS)

    // The caller's own cancellation still has to work while the timeout runs.
    options.signal?.addEventListener('abort', () => controller.abort(), { once: true })

    const isForm = options.body instanceof FormData
    const headers: Record<string, string> = isForm ? {} : { 'Content-Type': 'application/json' }
    const token = session.value?.accessToken

    if ((options.auth ?? true) && token !== undefined) {
        headers.Authorization = `Bearer ${token}`
    }

    try {
        return await fetch(`${BASE_URL}${path}`, {
            // A request with no body is a read. Defaulting to POST sent every
            // list endpoint that omitted the method — the reports list among
            // them — at a route registered for GET, which answers 405 and
            // leaves a screen permanently empty with nothing on it explaining
            // why. A body means something is being written, so that keeps
            // POST; anything explicit still wins over both.
            method: options.method ?? (options.body === undefined ? 'GET' : 'POST'),
            headers,
            body:
                options.body === undefined
                    ? undefined
                    : isForm
                      ? (options.body as FormData)
                      : JSON.stringify(options.body),
            signal: controller.signal,
        })
    } catch (cause) {
        // Offline, DNS failure, timeout, TLS refusal. All of them mean the same
        // thing to a caller: it did not arrive, so send it again later.
        throw new ApiError(
            'Could not reach the server. This will be sent when there is a connection.',
            0,
        )
    } finally {
        clearTimeout(timer)
    }
}

async function interpret<T>(response: Response): Promise<T> {
    const text = await response.text()
    let parsed: unknown = null

    if (text !== '') {
        try {
            parsed = JSON.parse(text)
        } catch {
            parsed = null
        }
    }

    if (response.ok) {
        return parsed as T
    }

    const retryAfter = Number.parseInt(response.headers.get('Retry-After') ?? '', 10)

    throw new ApiError(
        messageFrom(parsed) ?? `The server returned ${response.status}.`,
        response.status,
        Number.isNaN(retryAfter) ? null : retryAfter,
    )
}

function messageFrom(body: unknown): string | null {
    if (typeof body !== 'object' || body === null) {
        return null
    }

    const error = (body as { error?: unknown }).error

    if (typeof error === 'object' && error !== null) {
        const message = (error as { message?: unknown }).message

        if (typeof message === 'string' && message !== '') {
            return message
        }
    }

    return null
}

/**
 * Exchange the refresh token, at most once at a time.
 *
 * The single-flight guard is not an optimisation. The server rotates refresh
 * tokens and treats a token presented twice as a stolen one — it revokes every
 * session for that user. Two requests hitting 401 together would each present
 * the same refresh token, which is indistinguishable from theft, and the device
 * would be signed out by its own retry logic.
 */
let inFlight: Promise<boolean> | null = null

export function refresh(): Promise<boolean> {
    inFlight ??= exchange().finally(() => {
        inFlight = null
    })

    return inFlight
}

async function exchange(): Promise<boolean> {
    const current = session.value

    if (current === null) {
        return false
    }

    let response: Response

    try {
        response = await send('/auth/refresh', {
            auth: false,
            body: { refresh_token: current.refreshToken, device_id: deviceId() },
        })
    } catch {
        // Offline. The session is not known to be bad, so it is kept — the
        // device will try again when there is a connection.
        return false
    }

    if (!response.ok) {
        // The server has rejected the refresh token itself. Only the tokens go;
        // local assessments are not this function's business and never will be.
        clearSession()

        return false
    }

    // Signed out while this was in flight, so there is nothing to refresh into.
    // A refresh takes a round trip, and a sign-out during one used to resolve
    // afterwards and write the session back — putting somebody straight back
    // into the account they had just left. Checked after the await rather than
    // before, because the whole window is the await.
    if (session.value === null) {
        return false
    }

    const body = (await response.json()) as {
        access_token: string
        refresh_token: string
        expires_in: number
        user: {
            id: number
            email: string
            full_name: string
            role: string
            permissions: string[]
            organization_id: number
            organization: string | null
            must_change_password: boolean
        }
    }

    saveSession({
        accessToken: body.access_token,
        refreshToken: body.refresh_token,
        expiresAt: Date.now() + body.expires_in * 1000,
        user: {
            id: body.user.id,
            email: body.user.email,
            fullName: body.user.full_name,
            role: body.user.role,
            // Carried across the refresh, and re-read from the server rather
            // than copied from the session being replaced. Dropping it here
            // would empty the navigation every fifteen minutes; copying it
            // would mean a permission granted this morning never appears until
            // the holder signs out.
            permissions: body.user.permissions ?? [],
            organizationId: body.user.organization_id,
            organization: body.user.organization,
            mustChangePassword: body.user.must_change_password,
        },
    })

    return true
}
