import { toRaw } from 'vue'

/**
 * Strip Vue's reactivity before a value is written to IndexedDB.
 *
 * IndexedDB stores values by the structured clone algorithm, and that algorithm
 * refuses a Proxy. Everything the assessor types arrives here through a `ref`
 * or a `reactive`, which is exactly a Proxy — so handing one to Dexie throws
 * `DataCloneError: could not be cloned` and the write is lost.
 *
 * It is worth being clear about why this was not caught earlier. The device
 * tests run against `fake-indexeddb`, which stores whatever it is given and
 * does not apply the clone algorithm at all. Every test passed. The failure
 * appears only in a browser, on the setup screen, which is the first thing an
 * assessor touches.
 *
 * `toRaw` alone is not enough. It unwraps one level, and a nested object that
 * was itself made reactive stays a Proxy underneath — so this recurses.
 *
 * Blobs, Files and Dates are returned untouched. They are already cloneable,
 * and rebuilding them as plain objects would quietly destroy a signature: an
 * image would reach the server as `{}`, which no upload would refuse and no
 * screen would show as wrong.
 */
export function plain<T>(value: T): T {
    const raw = toRaw(value)

    if (raw === null || typeof raw !== 'object') {
        return raw
    }

    if (raw instanceof Blob || raw instanceof Date || raw instanceof ArrayBuffer) {
        return raw
    }

    if (Array.isArray(raw)) {
        return raw.map((entry) => plain(entry)) as T
    }

    const result: Record<string, unknown> = {}

    for (const [key, entry] of Object.entries(raw as Record<string, unknown>)) {
        result[key] = plain(entry)
    }

    return result as T
}
