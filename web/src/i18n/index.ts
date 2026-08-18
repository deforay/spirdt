import { computed, ref } from 'vue'

import * as en from './en'
import * as es from './es'
import * as fr from './fr'
import * as pt from './pt'

import type { Localised } from '@/scoring/types'

/**
 * Language, for the app and for the instrument.
 *
 * These are two different things and the app deliberately drives both from one
 * switch. The instrument is a versioned document that carries its own
 * translations — every label in it is a map keyed by locale, and the scoring
 * engine never reads text, so a translation can never move a score. The app's
 * own wording lives here.
 *
 * They fall back independently. An assessor working in French on a checklist
 * published only in English gets French buttons and English questions, which is
 * strictly better than an English app or a blank screen, and the switcher says
 * so rather than leaving them to work it out.
 *
 * The choice is stored per device rather than per user. It describes the person
 * holding the tablet, and on a shared tablet the next person picking it up
 * wants the last person's language far less often than they want their own —
 * but they are also the one who can see the switch.
 */

export type PluralForm = 'one' | 'other'

export type MessageKey = keyof typeof en.messages

/**
 * Every catalogue is shaped by the English one: same keys, and a key that
 * needs a plural in English needs one everywhere. Both are compile errors,
 * which is the only point at which a missing translation is cheap to find.
 */
export type Messages = {
    [K in MessageKey]: (typeof en.messages)[K] extends string
        ? string
        : Record<PluralForm, string>
}

export interface Catalogue {
    /** In its own language — a switcher that reads "French" to a French speaker is no help. */
    name: string
    plural: (count: number) => PluralForm
    messages: Messages
}

const CATALOGUES: Record<string, Catalogue> = { en, fr, pt, es }

export const FALLBACK_LOCALE = 'en'

/** Offered in the switcher, in this order. */
export const availableLocales = Object.keys(CATALOGUES)

export function localeName(code: string): string {
    return CATALOGUES[code]?.name ?? code
}

const STORAGE_KEY = 'spirdt.locale'

function stored(): string | null {
    try {
        const saved = localStorage.getItem(STORAGE_KEY)

        return saved !== null && saved in CATALOGUES ? saved : null
    } catch {
        return null
    }
}

/** `pt-BR` and `pt` are the same catalogue to us; we do not carry regional variants. */
function fromBrowser(): string | null {
    if (typeof navigator === 'undefined') {
        return null
    }

    for (const tag of navigator.languages ?? [navigator.language]) {
        const base = (tag ?? '').split('-')[0] ?? ''

        if (base in CATALOGUES) {
            return base
        }
    }

    return null
}

export const locale = ref<string>(stored() ?? fromBrowser() ?? FALLBACK_LOCALE)

export const catalogue = computed<Catalogue>(
    () => CATALOGUES[locale.value] ?? CATALOGUES[FALLBACK_LOCALE]!,
)

/** Keeps the document in step, so screen readers and hyphenation follow the switch. */
function applyToDocument(code: string): void {
    if (typeof document !== 'undefined') {
        document.documentElement.lang = code
    }
}

export function setLocale(code: string): void {
    if (!(code in CATALOGUES)) {
        return
    }

    locale.value = code
    applyToDocument(code)

    try {
        localStorage.setItem(STORAGE_KEY, code)
    } catch {
        // A device that will not remember the choice still honours it for this
        // session. Nothing here is worth failing a render over.
    }
}

/**
 * The organisation's language, for a device that has never chosen one.
 *
 * ONLY WHEN NOTHING IS STORED. A tablet that has been switched to French keeps
 * French — the person who switched it is the one holding it, and an
 * organisation default that reasserted itself at every sign-in would undo that
 * choice every morning. This decides what a device out of the box starts in,
 * which until now was whatever the browser happened to be set to.
 *
 * Called after sign-in and on a restored session, so it lands after this
 * module's own initial guess and can correct it.
 */
export function applyDefaultLocale(code: string | undefined): void {
    if (code === undefined || code === '' || stored() !== null) {
        return
    }

    if (!(code in CATALOGUES)) {
        return
    }

    locale.value = code
    applyToDocument(code)
}

applyToDocument(locale.value)

export type Params = Record<string, string | number>

function interpolate(template: string, params: Params | undefined): string {
    if (params === undefined) {
        return template
    }

    return template.replace(/\{(\w+)\}/g, (whole, name: string) => {
        const value = params[name]

        if (value === undefined) {
            return whole
        }

        return typeof value === 'number' ? formatNumber(value) : value
    })
}

/**
 * An app string.
 *
 * Reads `locale` on every call, so a template using it re-renders when the
 * language changes — that is what makes the switch immediate rather than
 * needing a reload.
 */
export function t(key: MessageKey, params?: Params): string {
    const message = catalogue.value.messages[key] ?? en.messages[key]

    if (message === undefined) {
        return key
    }

    if (typeof message === 'string') {
        return interpolate(message, params)
    }

    const count = typeof params?.count === 'number' ? params.count : 0

    return interpolate(message[catalogue.value.plural(count)], params)
}

/**
 * A string from the instrument.
 *
 * English is the named second choice, ahead of "whatever the template lists
 * first". Instruments are authored in English and translated from it, so it is
 * both the likeliest to be present and the one an assessor is likeliest to
 * have some of. Falling straight through to the first key would hand a
 * Portuguese speaker French or English depending on nothing but the order the
 * JSON happened to be written in.
 *
 * The last resort is any translation at all. An empty string is only ever
 * returned for a field the template omits entirely.
 */
export function text(value: Localised | undefined): string {
    if (value === undefined) {
        return ''
    }

    return value[locale.value] ?? value[FALLBACK_LOCALE] ?? Object.values(value)[0] ?? ''
}

export function formatNumber(value: number, decimals = 0): string {
    return new Intl.NumberFormat(locale.value, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value)
}

/** The score, written the way the reader's language writes it: 78.26% or 78,26 %. */
export function formatPercent(value: number, decimals: number): string {
    return new Intl.NumberFormat(locale.value, {
        style: 'percent',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value / 100)
}

export function formatTime(value: Date): string {
    return value.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}

/**
 * A stored date, written the way the reader's language writes it.
 *
 * Takes the `YYYY-MM-DD` string the device stores rather than a Date, and
 * splits it by hand instead of parsing. `new Date('2026-08-07')` is parsed as
 * UTC midnight and then rendered in local time, which west of Greenwich prints
 * the day before — so a visit made on the 7th is filed, correctly, and shown
 * as the 6th.
 */
export function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number)

    if (!year || !month || !day) {
        return value
    }

    return new Date(year, month - 1, day).toLocaleDateString(locale.value, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}
