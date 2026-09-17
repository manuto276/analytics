<script setup lang="ts">
import { VisXYContainer, VisLine, VisAxis, VisArea, VisCrosshair, VisTooltip } from '@unovis/vue'
import type { Interval, TimeseriesPoint } from '~/types'
import type { ChartDatum, ChartMetric } from '~/utils/chart'

const props = defineProps<{
  points: TimeseriesPoint[]
  comparePoints: TimeseriesPoint[] | null
  metric: ChartMetric
  interval: Interval
  loading?: boolean
}>()

const { t } = useI18n()
const fmt = useFormatters()
const cardRef = useTemplateRef<HTMLElement | null>('cardRef')
const { width } = useElementSize(cardRef)

const data = computed(() => mapTimeseries(props.points, props.comparePoints, props.metric))
const showCompare = computed(() => hasComparison(data.value))

function formatValue(value: number | null) {
  switch (props.metric) {
    case 'bounce_rate':
      return fmt.percent(value)
    case 'avg_duration_ms':
      return fmt.duration(value)
    case 'revenue_minor':
      return fmt.money(value)
    default:
      return fmt.number(value)
  }
}

function formatBucket(bucket: string | null) {
  if (!bucket) return ''
  if (props.interval === 'hour') return fmt.day(bucket, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
  if (props.interval === 'month') return fmt.day(bucket, { month: 'short', year: 'numeric' })
  return fmt.day(bucket, { day: 'numeric', month: 'short' })
}

const headline = computed(() => {
  if (props.metric === 'bounce_rate' || props.metric === 'avg_duration_ms' || props.metric === 'visitors') return null
  return formatValue(seriesTotal(data.value, 'value'))
})

const x = (d: ChartDatum) => d.index
const y = (d: ChartDatum) => d.value ?? undefined
const yCompare = (d: ChartDatum) => d.compare ?? undefined

const xTicks = (i: number) => {
  if (i === 0 || i === data.value.length - 1 || !data.value[i]) return ''
  return formatBucket(data.value[i].t)
}

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, c => `&#${c.charCodeAt(0)};`)
}

const template = (d: ChartDatum) => {
  const current = `${escapeHtml(formatBucket(d.t))}: ${escapeHtml(formatValue(d.value))}`
  if (!showCompare.value) return current
  return `${current}<br><span class="opacity-70">${escapeHtml(formatBucket(d.compareT))}: ${escapeHtml(formatValue(d.compare))}</span>`
}
</script>

<template>
  <UCard ref="cardRef" :ui="{ root: 'overflow-visible', body: 'px-0! pt-0! pb-3!' }" class="shrink-0">
    <template #header>
      <div class="flex items-end justify-between gap-3">
        <div>
          <p class="text-xs text-muted uppercase mb-1.5">
            {{ t(`metrics.${metric}`) }}
          </p>
          <p class="text-3xl text-highlighted font-semibold">
            {{ headline ?? EMPTY_VALUE }}
          </p>
        </div>
        <div v-if="showCompare" class="flex items-center gap-3 text-xs text-muted">
          <span class="flex items-center gap-1"><span class="inline-block w-4 border-t-2 border-primary" />{{ t('compare.current') }}</span>
          <span class="flex items-center gap-1"><span class="inline-block w-4 border-t-2 border-dashed border-muted" />{{ t('compare.previous') }}</span>
        </div>
      </div>
    </template>

    <div v-if="!data.length" class="h-96 flex items-center justify-center text-sm text-muted">
      {{ loading ? t('common.loading') : t('report.empty') }}
    </div>

    <VisXYContainer
      v-else
      :data="data"
      :padding="{ top: 40 }"
      :margin="{ left: -5, right: -5 }"
      class="h-96"
      :width="width"
    >
      <VisLine
        v-if="showCompare"
        :x="x"
        :y="yCompare"
        color="var(--ui-text-dimmed)"
        :line-dash-array="[6, 4]"
      />
      <VisLine
        :x="x"
        :y="y"
        color="var(--ui-primary)"
      />
      <VisArea
        :x="x"
        :y="y"
        color="var(--ui-primary)"
        :opacity="0.1"
      />

      <VisAxis
        type="x"
        :x="x"
        :tick-format="xTicks"
      />

      <VisCrosshair
        color="var(--ui-primary)"
        :template="template"
      />

      <VisTooltip />
    </VisXYContainer>
  </UCard>
</template>

<style scoped>
.unovis-xy-container {
  --vis-crosshair-line-stroke-color: var(--ui-primary);
  --vis-crosshair-circle-stroke-color: var(--ui-bg);

  --vis-axis-grid-color: var(--ui-border);
  --vis-axis-tick-color: var(--ui-border);
  --vis-axis-tick-label-color: var(--ui-text-dimmed);

  --vis-tooltip-background-color: var(--ui-bg);
  --vis-tooltip-border-color: var(--ui-border);
  --vis-tooltip-text-color: var(--ui-text-highlighted);
}
</style>
