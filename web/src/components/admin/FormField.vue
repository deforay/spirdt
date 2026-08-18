<script setup lang="ts">
/**
 * One labelled control, so spacing and label treatment are decided once.
 *
 * `wide` spans both columns — for an address, or anything else where a
 * half-width box truncates what people actually type.
 */
defineProps<{ label: string; hint?: string; wide?: boolean }>()
</script>

<template>
    <label class="flex flex-col gap-1.5" :class="wide ? 'sm:col-span-2' : ''">
        <span class="text-[14px] font-medium text-label-2">{{ label }}</span>
        <slot />
        <span v-if="hint" class="text-[13px] text-label-3">{{ hint }}</span>
    </label>
</template>
