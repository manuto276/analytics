<script setup lang="ts">
import type { Compare, Interval } from '~/types'

const props = withDefaults(defineProps<{
  interval?: boolean
  compare?: boolean
  filters?: boolean
}>(), {
  interval: false,
  compare: true,
  filters: true
})

const { t } = useI18n()
const q = useReportQuery()

const intervalItems = computed(() => intervalsFor(q.state.value.period, q.state.value.from, q.state.value.to)
  .map(value => ({ label: t(`intervals.${value}`), value })))

const intervalModel = computed({
  get: () => q.interval.value,
  set: (value: Interval) => {
    void q.changeInterval(value)
  }
})

const compareItems = computed(() => COMPARE_OPTIONS.map(value => ({ label: t(`compare.${value}`), value })))
const compareModel = computed({
  get: () => q.state.value.compare,
  set: (value: Compare) => {
    void q.setCompare(value)
  }
})
</script>

<template>
  <UDashboardToolbar>
    <template #left>
      <!-- NOTE: The `-ms-1` class is used to align with the `DashboardSidebarCollapse` button here. -->
      <ReportPeriodPicker class="-ms-1" />

      <USelect
        v-if="props.interval"
        v-model="intervalModel"
        :items="intervalItems"
        variant="ghost"
        class="data-[state=open]:bg-elevated"
        :aria-label="t('intervals.label')"
        :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
      />

      <USelect
        v-if="props.compare"
        v-model="compareModel"
        :items="compareItems"
        variant="ghost"
        icon="i-lucide-git-compare"
        class="data-[state=open]:bg-elevated"
        :aria-label="t('compare.label')"
        :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
      />
    </template>

    <template #right>
      <ReportFilterChips v-if="props.filters" />
      <slot name="right" />
    </template>
  </UDashboardToolbar>
</template>
