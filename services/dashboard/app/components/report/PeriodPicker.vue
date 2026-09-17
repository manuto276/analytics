<script setup lang="ts">
import { CalendarDate } from '@internationalized/date'
import type { Period } from '~/types'

const { t } = useI18n()
const fmt = useFormatters()
const { state, setPeriod } = useReportQuery()

const open = ref(false)

function toCalendar(day: string | undefined) {
  if (!day) return undefined
  const [y, m, d] = day.split('-').map(Number) as [number, number, number]
  return new CalendarDate(y, m, d)
}

function toDay(date: CalendarDate) {
  return `${date.year}-${String(date.month).padStart(2, '0')}-${String(date.day).padStart(2, '0')}`
}

const calendarRange = computed({
  get: () => {
    const today = todayIn(fmt.timezone.value)
    const range = state.value.period === 'custom'
      ? { from: state.value.from!, to: state.value.to! }
      : presetRange(state.value.period, today)
    return { start: toCalendar(range.from), end: toCalendar(range.to) }
  },
  set: (value: { start?: CalendarDate | null, end?: CalendarDate | null }) => {
    if (value.start && value.end) {
      void setPeriod('custom', toDay(value.start), toDay(value.end))
      open.value = false
    }
  }
})

const label = computed(() => {
  if (state.value.period === 'custom' && state.value.from && state.value.to) {
    return `${fmt.day(state.value.from)} – ${fmt.day(state.value.to)}`
  }
  return t(`periods.${state.value.period}`)
})

function choose(period: Period) {
  void setPeriod(period)
  open.value = false
}
</script>

<template>
  <UPopover v-model:open="open" :content="{ align: 'start' }" :modal="true">
    <UButton
      color="neutral"
      variant="ghost"
      icon="i-lucide-calendar"
      class="data-[state=open]:bg-elevated group"
      data-testid="period-picker"
    >
      <span class="truncate">{{ label }}</span>

      <template #trailing>
        <UIcon name="i-lucide-chevron-down" class="shrink-0 text-dimmed size-5 group-data-[state=open]:rotate-180 transition-transform duration-200" />
      </template>
    </UButton>

    <template #content>
      <div class="flex items-stretch sm:divide-x divide-default">
        <div class="flex flex-col justify-center py-1">
          <UButton
            v-for="preset in PERIOD_PRESETS"
            :key="preset"
            :label="t(`periods.${preset}`)"
            color="neutral"
            variant="ghost"
            class="rounded-none px-4"
            :class="[state.period === preset ? 'bg-elevated' : 'hover:bg-elevated/50']"
            truncate
            @click="choose(preset)"
          />
        </div>

        <UCalendar
          v-model="calendarRange"
          class="p-2 hidden sm:block"
          :number-of-months="2"
          range
        />
      </div>
    </template>
  </UPopover>
</template>
