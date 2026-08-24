<script setup lang="ts">
/**
 * A heading inside a form, for the forms long enough to need one.
 *
 * The facility form is ten fields in one grid — a name, a place, three
 * classifications, an address, three ways of contacting somebody and a pair of
 * coordinates — and read straight down it is a wall with no shape to it. The
 * fields were never the problem; the absence of any statement about which of
 * them belong together was.
 *
 * Short forms do not get one. A heading over three fields is a label for
 * itself, the same reason the navigation rail drops a group heading that names
 * a single screen.
 */
defineProps<{ title: string }>()
</script>

<template>
    <!--
        A band across the card rather than a line of text above a gap. It has
        to be as heavy as the thing it is dividing: on a card of ten white
        boxes, a small grey heading floating between two of them is read as a
        stray label rather than as the start of a group.

        Negative margins take it to the card's edges, which is what makes it a
        strip and not a paragraph. The first one hugs the top and loses its
        rule, so the card opens with a header instead of a seam.
    -->
    <div
        class="-mx-6 border-y border-hairline bg-surface-2 px-6 py-3 first:-mt-6 first:rounded-t-surface first:border-t-0 sm:col-span-2"
    >
        <h2 class="text-[14px] font-semibold text-label">{{ title }}</h2>
    </div>
</template>
