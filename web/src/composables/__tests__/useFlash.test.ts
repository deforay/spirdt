import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { clearFlash, flash, flashMessage, settleFlash } from '../useFlash'

/**
 * The whole point of this module is that the message outlives the component
 * that set it by exactly one navigation. Both halves of that are easy to get
 * wrong in a way nothing else would notice: too short and the confirmation
 * never appears, too long and it follows somebody around the console.
 */

beforeEach(() => {
    vi.useFakeTimers()
    clearFlash()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('flash', () => {
    it('carries the message across one navigation and clears on the next', () => {
        flash('Chilenje Health Centre added.')

        // The push the form makes right after saving.
        settleFlash()
        expect(flashMessage.value).toBe('Chilenje Health Centre added.')

        // Somebody moving on to another screen.
        settleFlash()
        expect(flashMessage.value).toBe('')
    })

    it('gives up on its own for somebody who stays put', () => {
        flash('Kabwe District saved.')
        settleFlash()

        vi.advanceTimersByTime(10_000)

        expect(flashMessage.value).toBe('')
    })

    it('replaces the message rather than queueing it', () => {
        flash('First.')
        flash('Second.')

        expect(flashMessage.value).toBe('Second.')

        settleFlash()
        expect(flashMessage.value).toBe('Second.')
    })

    it('can be dismissed by hand', () => {
        flash('Chilenje Health Centre added.')
        clearFlash()

        expect(flashMessage.value).toBe('')

        // Dismissed means gone, not merely hidden until the next navigation.
        settleFlash()
        expect(flashMessage.value).toBe('')
    })
})
