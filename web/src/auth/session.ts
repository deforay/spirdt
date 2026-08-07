import { ref } from 'vue'
import { uuidv7 } from '../db/uuid'

/**
 * Who is signed in on this device.
 *
 * One rule governs this file, and it is the rule the whole application is built
 * around: SIGNING OUT NEVER TOUCHES LOCAL WORK. Not on expiry, not on a
 * rejected refresh, not on an explicit sign-out. Tokens live here, assessments
 * live in IndexedDB, and nothing here can reach them.
 *
 * The failure that rule prevents is specific and it is the worst one available:
 * an access token quietly expires during a visit in a building with no signal,
 * the app treats that as a sign-out, clears its storage, and an hour of work
 * that was never going to be lost to a crash is lost to a clock.
 *
 * Tokens are in localStorage rather than a cookie because the API is called
 * cross-origin from a PWA and read back after a cold start. That does mean any
 * script running on this origin could read them, which is why the app ships no
 * third-party scripts and no remote code.
 */

const SESSION_KEY = 'spirdt.session'
const DEVICE_KEY = 'spirdt.device'

export interface SessionUser {
    id: number
    email: string
    fullName: string
    role: string
    /**
     * What the account may do, as the server had it at sign-in.
     *
     * Optional because a session saved before this existed has none. Read
     * through auth/permissions.ts, which treats absent as holding nothing.
     */
    permissions?: string[]
    organizationId: number
    organization: string | null
    mustChangePassword: boolean
}

export interface Session {
    accessToken: string
    refreshToken: string
    /** Epoch milliseconds. Local clock — treated as a hint, never as proof. */
    expiresAt: number
    user: SessionUser
}

export const session = ref<Session | null>(read())

function read(): Session | null {
    try {
        const raw = localStorage.getItem(SESSION_KEY)

        if (raw === null) {
            return null
        }

        const parsed = JSON.parse(raw) as Partial<Session>

        if (typeof parsed.accessToken !== 'string' || typeof parsed.refreshToken !== 'string') {
            return null
        }

        return parsed as Session
    } catch {
        // Unreadable or corrupt. Signed out is the safe reading, and the
        // assessments are not in here.
        return null
    }
}

export function saveSession(next: Session): void {
    session.value = next

    try {
        localStorage.setItem(SESSION_KEY, JSON.stringify(next))
    } catch {
        // A full or blocked localStorage means this session lasts until the tab
        // closes. Worth continuing with — the alternative is refusing to sign
        // someone in at a site they have already travelled to.
    }
}

/** Forgets the tokens. Assessments are untouched, deliberately and always. */
export function clearSession(): void {
    session.value = null

    try {
        localStorage.removeItem(SESSION_KEY)
    } catch {
        // Nothing to do. The in-memory session is already gone.
    }
}

export function isSignedIn(): boolean {
    return session.value !== null
}

/**
 * Whether the access token is worth trying.
 *
 * A minute of slack absorbs clock skew and the round trip itself. Being wrong
 * in this direction costs one refresh; being wrong the other way costs a
 * request that fails after the payload has already been uploaded.
 */
export function accessTokenExpired(now: number = Date.now()): boolean {
    const current = session.value

    return current === null || current.expiresAt - 60_000 <= now
}

/**
 * A stable id for this device, kept across sign-ins.
 *
 * Recorded against every assessment and every refresh token, so a tablet that
 * goes missing can be identified in the audit trail and its sessions revoked
 * without disturbing anyone else's.
 */
export function deviceId(): string {
    try {
        const existing = localStorage.getItem(DEVICE_KEY)

        if (existing !== null && existing !== '') {
            return existing
        }

        const minted = uuidv7()
        localStorage.setItem(DEVICE_KEY, minted)

        return minted
    } catch {
        return 'unknown-device'
    }
}
