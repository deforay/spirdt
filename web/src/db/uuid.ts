/**
 * UUIDv7, generated on the device.
 *
 * v7 rather than v4 because the id is created offline and the server accepts
 * it as given. v7 sorts by creation time, so the primary key stays in insert
 * order and the index does not fragment the way random ids do.
 *
 * Layout, per RFC 9562: 48 bits of Unix milliseconds, 4 bits of version,
 * 12 bits of counter, 2 bits of variant, 62 bits of random.
 *
 * Those 12 bits are a counter rather than random, which is the part worth
 * explaining. Ids minted inside the same millisecond share a timestamp, so
 * with random bits there they sort arbitrarily among themselves — and the
 * ordering guarantee above would be false exactly when it is most used, since
 * starting an assessment writes several rows in one burst. The counter makes
 * up to 4096 ids per millisecond strictly ordered.
 */

let lastMillis = 0
let counter = 0

export function uuidv7(): string {
    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)

    let millis = Date.now()

    if (millis === lastMillis) {
        counter += 1

        // More than 4096 in one millisecond. Borrow from the next one rather
        // than emit an id that sorts before the one issued just before it.
        if (counter > 0xfff) {
            lastMillis += 1
            millis = lastMillis
            counter = 0
        }
    } else {
        if (millis < lastMillis) {
            // The clock went backwards, which happens across a daylight saving
            // change or an NTP correction. Keep counting forward: an id that
            // sorts backwards is worse than one whose timestamp is a few
            // milliseconds optimistic.
            millis = lastMillis
            counter += 1
        } else {
            lastMillis = millis
            counter = 0
        }
    }

    bytes[0] = (millis / 2 ** 40) & 0xff
    bytes[1] = (millis / 2 ** 32) & 0xff
    bytes[2] = (millis / 2 ** 24) & 0xff
    bytes[3] = (millis / 2 ** 16) & 0xff
    bytes[4] = (millis / 2 ** 8) & 0xff
    bytes[5] = millis & 0xff

    // Version 7 in the high nibble of byte 6, counter across the rest.
    bytes[6] = 0x70 | ((counter >> 8) & 0x0f)
    bytes[7] = counter & 0xff
    // RFC 9562 variant in the top two bits of byte 8. The rest stays random.
    bytes[8] = (bytes[8]! & 0x3f) | 0x80

    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20, 32),
    ].join('-')
}
