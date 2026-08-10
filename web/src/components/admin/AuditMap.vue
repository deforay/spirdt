<script setup lang="ts">
import L from 'leaflet'
// Leaflet's own stylesheet. Imported here rather than in app.css so it
// travels with the admin chunk that uses it.
import 'leaflet/dist/leaflet.css'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { t } from '@/i18n'

/**
 * Where the visits happened.
 *
 * The predecessor plotted one marker per audit coloured by band, and it was
 * the most useful thing it produced: a country's certification picture is
 * geographic before it is anything else, because remediation is planned by
 * district and not by percentile.
 *
 * TWO KINDS OF PIN, AND THE DIFFERENCE MATTERS. A device fix is evidence that
 * somebody stood there. A facility fallback is what the registry claims, which
 * may have been entered by an administrator who has never been. The second is
 * drawn hollow so a map cannot be used to settle a dispute about whether a
 * visit happened with a coordinate nobody recorded.
 *
 * OpenStreetMap tiles, no API key, no account, and no third-party script — the
 * same reasoning as everywhere else in this application. Leaflet is imported
 * only here, so it lands in an admin chunk rather than on an assessor's phone.
 */

interface Point {
    id: string
    lat: number
    lng: number
    accuracy_m: number | null
    source: string | null
    site: string | null
    facility: string | null
    assessed_on: string
    percentage: number | null
    level: number | null
}

const props = defineProps<{ points: Point[]; height?: string }>()
const emit = defineEmits<{ pick: [id: string] }>()

const host = ref<HTMLElement | null>(null)
let map: L.Map | null = null
let layer: L.LayerGroup | null = null

/** Matches ScoreBadge: 0 and 1 need remediation, 2 partial, 3 and 4 certifiable. */
function colour(level: number | null): string {
    if (level === null) {
        return '#6B6B73'
    }

    if (level <= 1) {
        return '#B3261E'
    }

    return level === 2 ? '#9A5B00' : '#1E7B34'
}

function draw(): void {
    if (map === null) {
        return
    }

    layer?.remove()
    layer = L.layerGroup().addTo(map)

    const bounds: L.LatLngExpression[] = []

    for (const point of props.points) {
        const inherited = point.source === 'facility'

        const marker = L.circleMarker([point.lat, point.lng], {
            radius: 7,
            color: colour(point.level),
            weight: 2,
            // Hollow means "the registry says so", filled means "somebody was
            // there". Legend below the map says as much in words.
            fillColor: inherited ? 'transparent' : colour(point.level),
            fillOpacity: inherited ? 0 : 0.85,
        })

        const name = point.site ?? point.facility ?? ''
        const score = point.percentage === null ? '' : `${point.percentage}%`

        marker.bindTooltip(
            `<strong>${escape(name)}</strong><br>${escape(point.assessed_on)} ${escape(score)}` +
                (inherited ? `<br><em>${escape(t('map.inherited'))}</em>` : ''),
            { direction: 'top' },
        )

        marker.on('click', () => emit('pick', point.id))
        marker.addTo(layer)
        bounds.push([point.lat, point.lng])
    }

    if (bounds.length > 0) {
        map.fitBounds(L.latLngBounds(bounds), { padding: [32, 32], maxZoom: 12 })
    }
}

/** Names come from the registry and are shown inside a tooltip's HTML. */
function escape(value: string): string {
    return value.replace(
        /[&<>"']/g,
        (character) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character] ??
            character,
    )
}

onMounted(() => {
    if (host.value === null) {
        return
    }

    map = L.map(host.value, { scrollWheelZoom: false, attributionControl: true })
    map.setView([0, 20], 3)

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map)

    draw()
})

watch(() => props.points, draw, { deep: true })

onBeforeUnmount(() => {
    layer?.remove()
    map?.remove()
    map = null
})
</script>

<template>
    <div
        ref="host"
        class="w-full overflow-hidden rounded-card"
        :style="{ height: props.height ?? '380px' }"
    ></div>
</template>
