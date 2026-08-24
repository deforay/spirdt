import { ref } from 'vue'

/**
 * The sentence that says the thing you just did worked.
 *
 * Every management form ends the same way: save, and the router puts you back
 * on the list you came from. That is the whole confirmation — a screen change
 * — and it is not one. A facility added to a national registry lands on page
 * one of a list ordered by name, which is to say nowhere anybody can see, so
 * adding one looked exactly like a form that had quietly thrown the entry
 * away.
 *
 * The message is set BEFORE the navigation and read after it, which is why it
 * lives at module scope rather than in a component: the component that knows
 * the save succeeded is unmounted by the time there is anywhere to say so.
 *
 * Not a toast stack. One sentence at a time, in the flow of the page rather
 * than floating over it, and it names what it is talking about — "Chilenje
 * Health Centre added" tells you the right row is in there somewhere; "Saved"
 * tells you an operation returned.
 */

/** Long enough to read the sentence, short enough not to become furniture. */
const SHOWN_FOR_MS = 6000

export const flashMessage = ref('')

let timer: ReturnType<typeof setTimeout> | undefined

/**
 * Survives exactly one navigation.
 *
 * Set on the form, shown on the list. The navigation after that clears it —
 * see `settleFlash` — so a confirmation cannot follow somebody around the
 * console into screens it has nothing to do with.
 */
let crossing = false

export function flash(message: string): void {
    flashMessage.value = message
    crossing = true

    clearTimeout(timer)
    timer = setTimeout(clearFlash, SHOWN_FOR_MS)
}

export function clearFlash(): void {
    clearTimeout(timer)
    crossing = false
    flashMessage.value = ''
}

/**
 * Called after every navigation. The first one carries the message to the
 * screen that should show it; the next one is somebody moving on.
 */
export function settleFlash(): void {
    if (crossing) {
        crossing = false

        return
    }

    clearFlash()
}
