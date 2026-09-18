<script setup lang="ts">
import type { OverviewMetrics, ReportMeta } from '~/types'
import type { ChartMetric } from '~/utils/chart'

const props = defineProps<{
  metrics: OverviewMetrics | null
  deltas: Record<string, number | null> | null
  availability: ReportMeta['availability']
  loading?: boolean
}>()

const selected = defineModel<ChartMetric>('metric', { default: 'visitors' })

const { t } = useI18n()
const fmt = useFormatters()

interface StatDef {
  key: ChartMetric
  icon: string
  requires?: 'visitors' | 'bounce' | 'duration'
  invert?: boolean
  format: (v: number | null | undefined) => string
}

const defs: StatDef[] = [
  { key: 'visitors', icon: 'i-tabler-users', requires: 'visitors', format: fmt.compact },
  { key: 'visits', icon: 'i-tabler-login', format: fmt.compact },
  { key: 'pageviews', icon: 'i-tabler-eye', format: fmt.compact },
  { key: 'bounce_rate', icon: 'i-tabler-arrow-back-up', requires: 'bounce', invert: true, format: fmt.percent },
  { key: 'avg_duration_ms', icon: 'i-tabler-stopwatch', requires: 'duration', format: fmt.duration },
  { key: 'conversions', icon: 'i-tabler-target', format: fmt.compact },
  { key: 'revenue_minor', icon: 'i-tabler-coin', format: v => fmt.money(v) }
]

const stats = computed(() => defs.map((def) => {
  const available = !def.requires || props.availability[def.requires]
  const value = props.metrics ? props.metrics[def.key] : null
  return {
    ...def,
    title: t(`metrics.${def.key}`),
    value: available ? def.format(value) : EMPTY_VALUE,
    delta: available ? fmt.delta(props.deltas?.[def.key], def.invert) : null,
    available
  }
}))
</script>

<template>
  <UPageGrid class="grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7 gap-4 sm:gap-4 lg:gap-4" data-testid="overview-stats">
    <UPageCard
      v-for="stat in stats"
      :key="stat.key"
      :icon="stat.icon"
      :title="stat.title"
      variant="subtle"
      :ui="{
        container: 'gap-y-1.5 p-4 sm:p-4',
        wrapper: 'items-start',
        leading: 'p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col',
        title: 'font-normal text-muted text-xs uppercase'
      }"
      class="cursor-pointer hover:z-1"
      :class="selected === stat.key ? 'ring-2 ring-primary' : ''"
      role="button"
      :aria-pressed="selected === stat.key"
      @click="selected = stat.key"
    >
      <div class="flex flex-wrap items-center gap-2">
        <USkeleton v-if="loading && !metrics" class="h-8 w-20" />
        <span v-else class="text-2xl font-semibold text-highlighted" :title="stat.available ? undefined : t('availability.short')">
          {{ stat.value }}
        </span>

        <UBadge
          v-if="stat.delta"
          :color="stat.delta.tone"
          variant="subtle"
          :icon="stat.delta.icon"
          class="text-xs"
          data-testid="stat-delta"
        >
          {{ stat.delta.text }}
        </UBadge>
      </div>
    </UPageCard>
  </UPageGrid>
</template>
