import { apiRequest } from '../api/client'
import { clearSession, deviceId, saveSession, type Session } from './session'

/** The shape the auth endpoints return. Snake case, as it comes off the wire. */
interface TokenPair {
    access_token: string
    refresh_token: string
    expires_in: number
    user: {
        id: number
        email: string
        full_name: string
        role: string
        organization_id: number
        organization: string | null
        must_change_password: boolean
    }
}

export interface Credentials {
    email: string
    password: string
    /** Needed only where the same address exists in more than one organisation. */
    organization?: string
}

export async function signIn(credentials: Credentials): Promise<Session> {
    const body = await apiRequest<TokenPair>('/auth/login', {
        auth: false,
        body: {
            email: credentials.email,
            password: credentials.password,
            organization: credentials.organization ?? '',
            device_id: deviceId(),
        },
    })

    const next: Session = {
        accessToken: body.access_token,
        refreshToken: body.refresh_token,
        expiresAt: Date.now() + body.expires_in * 1000,
        user: {
            id: body.user.id,
            email: body.user.email,
            fullName: body.user.full_name,
            role: body.user.role,
            organizationId: body.user.organization_id,
            organization: body.user.organization,
            mustChangePassword: body.user.must_change_password,
        },
    }

    saveSession(next)

    return next
}

/**
 * Sign out.
 *
 * Tells the server first so the refresh token stops working even if this device
 * is never seen again, then forgets the tokens locally. A server that cannot be
 * reached does not stop the local half — someone handing a tablet back needs it
 * signed out now, not when there is a signal.
 *
 * Assessments stay on the device. Signing out is not a way to clear them, and
 * anyone who needs that has to be given something that says what it is doing.
 */
export async function signOut(refreshToken: string | null): Promise<void> {
    if (refreshToken !== null) {
        try {
            await apiRequest('/auth/logout', { auth: false, body: { refresh_token: refreshToken } })
        } catch {
            // Best effort. The token expires on its own.
        }
    }

    clearSession()
}
