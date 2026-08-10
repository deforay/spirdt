<script setup lang="ts">
import { PieChart, RadarChart } from 'echarts/charts'
import { LegendComponent, ToolboxComponent, TooltipComponent } from 'echarts/components'
import * as echarts from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'

/**
 * One chart, drawn by ECharts.
 *
 * IMPORTED PIECE BY PIECE rather than as `echarts`. The full bundle is around
 * a megabyte; this registers only what is drawn and leaves the rest out.
 * Adding a chart type here is deliberate, which is the point — an
 * `import 'echarts'` would make every future chart free at the point of
 * writing and expensive at the point of loading.
 *
 * None of it reaches the assessor. Every management route is a dynamic import,
 * so this lands in an admin chunk and a phone in a clinic never downloads it —
 * which is the whole reason the earlier panels were hand-drawn, and the reason
 * that argument no longer applies to these.
 *
 * The wrapper exists because three things have to happen around every chart
 * and are easy to forget one of: it must be disposed, it must be told when its
 * container resizes, and it must be re-rendered when the option changes rather
 * than merged into — a merge leaves the previous series behind when a filter
 * removes one.
 */

const props = defineProps<{
    option: Record<string, unknown>
    height?: string
    /** Passed to the click handler as-is, so the parent decides what a click means. */
    ariaLabel?: string
}>()

const emit = defineEmits<{ pick: [params: { name: string; seriesName?: string; dataIndex: number }] }>()

// Exactly what is drawn, and nothing else. The month columns are hand-drawn,
// so no cartesian grid is registered — adding a bar chart here later is a
// deliberate act with a visible cost, which is the point of importing piece by
// piece rather than the whole library. The toolbox is here for one reason: a
// chart in a dashboard gets pasted into a report, and asking somebody to
// screenshot it is asking for a blurry screenshot.
echarts.use([
    PieChart,
    RadarChart,
    LegendComponent,
    ToolboxComponent,
    TooltipComponent,
    CanvasRenderer,
])

const host = ref<HTMLElement | null>(null)
const chart = shallowRef<echarts.ECharts | null>(null)
let observer: ResizeObserver | null = null

function render(): void {
    // notMerge: a chart whose series are replaced must forget the old ones.
    chart.value?.setOption(props.option, true)
}

onMounted(() => {
    if (host.value === null) {
        return
    }

    chart.value = echarts.init(host.value, undefined, { renderer: 'canvas' })
    render()

    chart.value.on('click', (params) => {
        emit('pick', {
            name: String((params as { name?: unknown }).name ?? ''),
            seriesName: (params as { seriesName?: string }).seriesName,
            dataIndex: Number((params as { dataIndex?: unknown }).dataIndex ?? 0),
        })
    })

    // A chart sized once is a chart that is wrong the moment a panel reflows,
    // which on this layout happens at every breakpoint.
    observer = new ResizeObserver(() => chart.value?.resize())
    observer.observe(host.value)
})

watch(() => props.option, render, { deep: true })

onBeforeUnmount(() => {
    observer?.disconnect()
    chart.value?.dispose()
    chart.value = null
})
</script>

<template>
    <div
        ref="host"
        role="img"
        :aria-label="props.ariaLabel"
        :style="{ height: props.height ?? '260px', width: '100%' }"
    ></div>
</template>
