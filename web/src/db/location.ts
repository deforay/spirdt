/**
 * Where the assessor is standing, when the device is willing to say.
 *
 * The predecessor collected a geopoint on every submission and plotted one
 * marker per assessment on a map coloured by score band. That map is the
 * certification picture of a country at a glance, and this is the reading it
 * needs.
 *
 * NOTHING HERE CAN FAIL A VISIT. Every path resolves — refused permission, no
 * fix, no hardware, a browser without the API, a timeout — and every one of
 * them resolves to null. A visit refused for want of a satellite is a visit
 * that does not happen, and the assessor is standing in the laboratory either
 * way.
 *
 * Which is also why it is not awaited before the checklist opens. The reading
 * is asked for when the visit starts and written to the assessment whenever it
 * arrives; the assessor never waits on it.
 */

export interface Fix {
    latitude: number
    longitude: number
    /** Metres. A coordinate without this is not interpretable — five metres
     *  and two kilometres plot identically and mean different things. */
    accuracyM: number | null
    /** ISO, from the device clock. When the fix was taken, not when it synced. */
    takenAt: string
}

/**
 * Long enough for a cold start, short enough not to be waiting at the bench.
 *
 * A first fix indoors can take thirty seconds or never arrive. Twenty is the
 * point past which the answer is almost always "never" — and because nothing
 * waits on this, being wrong costs a null rather than a delay.
 */
const TIMEOUT_MS = 20_000

/**
 * A fix good to two kilometres is a cell tower, not a building.
 *
 * Stored anyway, because accuracy is recorded alongside and a coarse position
 * still says which district. What it must not do is silently pass as a
 * location of the site.
 */
export const COARSE_ACCURACY_M = 2_000

export async function currentFix(): Promise<Fix | null> {
    if (typeof navigator === 'undefined' || navigator.geolocation === undefined) {
        return null
    }

    // Requires a secure context, so it is unavailable over plain http on
    // anything but localhost. That is a deployment property rather than an
    // error, and it resolves to null like every other refusal.
    return new Promise<Fix | null>((resolve) => {
        let settled = false

        const finish = (fix: Fix | null): void => {
            if (!settled) {
                settled = true
                resolve(fix)
            }
        }

        navigator.geolocation.getCurrentPosition(
            (position) =>
                finish({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracyM:
                        typeof position.coords.accuracy === 'number'
                            ? Math.round(position.coords.accuracy)
                            : null,
                    takenAt: new Date(position.timestamp).toISOString(),
                }),
            // Refused, unavailable, or timed out. All the same answer here:
            // there is no position, and the visit carries on regardless.
            () => finish(null),
            {
                enableHighAccuracy: true,
                timeout: TIMEOUT_MS,
                // A position from the last two minutes is the same place. Asking
                // for a fresh fix costs battery and a wait for no better answer.
                maximumAge: 120_000,
            },
        )

        // The browser's own timeout is not always honoured — Safari has left
        // the callback unfired on a denied prompt — so this is the one that
        // guarantees the promise settles.
        setTimeout(() => finish(null), TIMEOUT_MS + 1_000)
    })
}
