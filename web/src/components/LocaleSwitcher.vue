<script setup lang="ts">
import { availableLocales, locale, localeName, setLocale, t } from '@/i18n'

/**
 * Change language.
 *
 * Sits in the header of every screen rather than behind a settings page,
 * because the person who needs it most is the one who cannot read the settings
 * page. What shows is the current language's code, which is legible in any of
 * them and fits a header that already holds a site name and a sync badge.
 *
 * A real `<select>`, made invisible and stretched over that code, does the
 * work. It costs nothing, opens as the platform's own picker on a tablet, and
 * arrives correct for keyboard and screen reader without being asked. The
 * menu component we would otherwise reach for was twenty-two kilobytes
 * compressed — a fifth of the whole app, downloaded over the connection an
 * offline-first tool exists to cope with, for four options.
 *
 * Two grounds to sit on. It appears in the navy app bar on the working screens
 * and on the paper ground on sign-in, and a pill drawn for one is invisible on
 * the other.
 */

withDefaults(defineProps<{ variant?: 'page' | 'chrome' }>(), { variant: 'page' })
</script>

<template>
    <div class="relative shrink-0">
        <span
            aria-hidden="true"
            :class="[
                'block rounded-full px-2.5 py-1 text-[13px] font-semibold uppercase',
                variant === 'chrome'
                    ? 'bg-white/15 text-chrome-ink'
                    : 'bg-surface text-label-2',
            ]"
        >
            {{ locale }}
        </span>

        <select
            :value="locale"
            :aria-label="t('locale.label')"
            class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
            @change="setLocale(($event.target as HTMLSelectElement).value)"
        >
            <option v-for="code in availableLocales" :key="code" :value="code">
                {{ localeName(code) }}
            </option>
        </select>
    </div>
</template>
