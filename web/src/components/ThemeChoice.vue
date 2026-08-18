<script setup lang="ts">
import { PhCircleHalf, PhMoon, PhSun } from '@phosphor-icons/vue'

import { setTheme, theme, type ThemeChoice } from '@/composables/useTheme'
import { t } from '@/i18n'

/**
 * Three cards rather than a switch.
 *
 * A two-state switch cannot say "follow the device", and following the device
 * is the state most people should be in — so it is one of the three rather
 * than an extra checkbox beside a toggle. Each carries its own mark: the
 * difference between a moon and a half-filled circle is legible at a glance in
 * a way "Dark / Auto" in one type size is not.
 */

const OPTIONS: { key: ThemeChoice; label: 'theme.light' | 'theme.dark' | 'theme.auto'; icon: typeof PhSun }[] = [
    { key: 'light', label: 'theme.light', icon: PhSun },
    { key: 'dark', label: 'theme.dark', icon: PhMoon },
    { key: 'auto', label: 'theme.auto', icon: PhCircleHalf },
]
</script>

<template>
    <div class="grid grid-cols-3 gap-2" role="group" :aria-label="t('theme.title')">
        <button
            v-for="option in OPTIONS"
            :key="option.key"
            type="button"
            :aria-pressed="theme === option.key"
            :class="[
                'flex min-h-[68px] flex-col items-center justify-center gap-1.5 rounded-card border-2 px-1 text-[13px] font-medium transition-colors',
                theme === option.key
                    ? 'border-accent bg-accent-soft text-accent'
                    : 'border-hairline text-label-2 hover:border-label-3/40 hover:text-label',
            ]"
            @click="setTheme(option.key)"
        >
            <component :is="option.icon" :size="19" aria-hidden="true" />
            {{ t(option.label) }}
        </button>
    </div>
</template>
