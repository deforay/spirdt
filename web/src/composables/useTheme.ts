import { ref } from 'vue'

/**
 * Light, dark, or whatever the device says.
 *
 * The palette has had a dark half since the beginning and nothing in the
 * application could reach it: the tokens answered `prefers-color-scheme` and
 * that was the whole story, so a ministry laptop set to light could not see
 * dark and an assessor working a night shift could not turn it on. docs/design
 * has described this control as existing for months.
 *
 * The choice belongs to the device, not the account. It is a preference about
 * a screen in a room — a shared tablet on a bench in daylight wants light, the
 * same person's laptop at home may not — so it lives in localStorage and never
 * goes near the server.
 *
 * `auto` removes the attribute rather than writing a value, which hands the
 * question back to the media query. Writing the resolved value instead would
 * freeze whatever the system happened to be at the moment somebody chose Auto.
 */

export type ThemeChoice = 'auto' | 'light' | 'dark'

/** Shared with the pre-paint stamp in index.html. Change both or neither. */
export const THEME_KEY = 'spirdt.theme'

function stored(): ThemeChoice {
    try {
        const value = localStorage.getItem(THEME_KEY)

        return value === 'light' || value === 'dark' ? value : 'auto'
    } catch {
        // Private browsing throws on access rather than returning null.
        return 'auto'
    }
}

export const theme = ref<ThemeChoice>(stored())

export function applyTheme(choice: ThemeChoice): void {
    const root = document.documentElement

    if (choice === 'auto') {
        delete root.dataset.theme
    } else {
        root.dataset.theme = choice
    }
}

export function setTheme(choice: ThemeChoice): void {
    theme.value = choice
    applyTheme(choice)

    try {
        localStorage.setItem(THEME_KEY, choice)
    } catch {
        // A device that cannot store it still gets the theme for this session.
    }
}
