import { beforeEach, describe, expect, it } from 'vitest'

import * as en from '../en'
import * as es from '../es'
import * as fr from '../fr'
import * as pt from '../pt'
import { availableLocales, formatPercent, locale, setLocale, t, text } from '../index'

/**
 * The catalogues are checked against English structurally rather than by
 * reading them. A translation nobody here can read is exactly the kind that
 * ships with `{count}` spelled `{total}` and renders the braces to an assessor
 * in the field, so what is machine-checkable is checked.
 */

const TRANSLATIONS = { fr, pt, es }

/** `{count}` and friends, so a translation cannot rename one and go unnoticed. */
function placeholders(message: unknown): Set<string> {
    const forms = typeof message === 'string' ? [message] : Object.values(message as object)
    const found = new Set<string>()

    for (const form of forms) {
        for (const match of String(form).matchAll(/\{(\w+)\}/g)) {
            found.add(match[1]!)
        }
    }

    return found
}

beforeEach(() => {
    setLocale('en')
})

describe('catalogues', () => {
    it('offers every catalogue it ships', () => {
        expect(availableLocales).toEqual(['en', 'fr', 'pt', 'es'])
    })

    for (const [code, catalogue] of Object.entries(TRANSLATIONS)) {
        describe(code, () => {
            it('has exactly the English keys', () => {
                expect(Object.keys(catalogue.messages).sort()).toEqual(
                    Object.keys(en.messages).sort(),
                )
            })

            it('keeps plural messages plural, with both forms', () => {
                for (const [key, source] of Object.entries(en.messages)) {
                    const translated = (catalogue.messages as Record<string, unknown>)[key]

                    if (typeof source === 'string') {
                        expect(typeof translated, key).toBe('string')
                        continue
                    }

                    expect(translated, key).toMatchObject({
                        one: expect.any(String),
                        other: expect.any(String),
                    })
                }
            })

            it('uses the same placeholders as English', () => {
                for (const [key, source] of Object.entries(en.messages)) {
                    const translated = (catalogue.messages as Record<string, unknown>)[key]

                    expect([...placeholders(translated)].sort(), key).toEqual(
                        [...placeholders(source)].sort(),
                    )
                }
            })

            it('leaves nothing in English', () => {
                for (const [key, source] of Object.entries(en.messages)) {
                    const translated = (catalogue.messages as Record<string, unknown>)[key]

                    // Codes and abbreviations legitimately coincide across
                    // languages; whole sentences do not.
                    if (typeof source !== 'string' || source.length < 16) {
                        continue
                    }

                    expect(translated, key).not.toBe(source)
                }
            })
        })
    }
})

describe('t', () => {
    it('fills placeholders', () => {
        expect(t('question.responseLabel', { code: '3.4' })).toBe('Response to question 3.4')
    })

    it('leaves a placeholder alone when nothing is passed for it', () => {
        expect(t('score.level')).toBe('Level {level}')
    })

    it('picks the singular at one and the plural elsewhere', () => {
        expect(t('review.stillNeeded', { count: 1 })).toBe('1 question still needs an answer.')
        expect(t('review.stillNeeded', { count: 7 })).toBe('7 questions still need an answer.')
        expect(t('review.stillNeeded', { count: 0 })).toBe('0 questions still need an answer.')
    })

    it('counts zero as singular in French', () => {
        setLocale('fr')

        expect(t('setup.missingFields', { count: 0 })).toBe('0 champ obligatoire reste à remplir.')
        expect(t('setup.missingFields', { count: 1 })).toBe('1 champ obligatoire reste à remplir.')
        expect(t('setup.missingFields', { count: 2 })).toBe(
            '2 champs obligatoires restent à remplir.',
        )
    })

    it('changes what it returns when the language changes', () => {
        expect(t('review.submit')).toBe('Submit assessment')

        setLocale('pt')

        expect(t('review.submit')).toBe('Submeter avaliação')
    })

    it('ignores a language it does not have', () => {
        setLocale('de')

        expect(locale.value).toBe('en')
        expect(t('review.submit')).toBe('Submit assessment')
    })
})

describe('text', () => {
    it('reads the instrument in the current language', () => {
        setLocale('fr')

        expect(text({ en: 'Yes', fr: 'Oui' })).toBe('Oui')
    })

    it('falls back to English when the instrument is not translated', () => {
        setLocale('fr')

        expect(text({ en: 'Yes' })).toBe('Yes')
    })

    it('prefers English over whichever translation the template lists first', () => {
        setLocale('fr')

        expect(text({ pt: 'Sim', en: 'Yes' })).toBe('Yes')
    })

    it('falls back to any translation when there is no English either', () => {
        setLocale('fr')

        expect(text({ pt: 'Sim' })).toBe('Sim')
    })

    it('returns nothing for a field the instrument omits', () => {
        expect(text(undefined)).toBe('')
    })
})

describe('formatPercent', () => {
    it('writes the score the way the language writes it', () => {
        expect(formatPercent(78.26, 2)).toBe('78.26%')

        setLocale('fr')

        // French separates with a comma and puts a space before the sign.
        expect(formatPercent(78.26, 2)).toMatch(/^78,26\s%$/u)
    })
})
