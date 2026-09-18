<script setup lang="ts">
import { VisXYContainer, VisStackedBar, VisAxis, VisTooltip } from '@unovis/vue'
import type { ApiResponse } from '~/types'

type MinuteDatum = { t: string, pageviews: number }

const REFRESH_MS = 10_000

const { t } = useI18n()
const fmt = useFormatters()
const { currentSite } = useSites()
useHead({ title: () => t('nav.realtime') })

const realtime = useReport<ApiResponse<'/sites/{siteId}/reports/realtime'>>('realtime', {}, { range: false })
const data = computed(() => realtime.data.value?.data ?? null)

let timer: ReturnType<typeof globalThis.setInterval> | undefined
onMounted(() => {
  timer = globalThis.setInterval(() => {
    if (document.visibilityState === 'visible') void realtime.refresh()
  }, REFRESH_MS)
})
onBeforeUnmount(() => {
  if (timer) globalThis.clearInterval(timer)
})

const chartRef = useTemplateRef<HTMLElement | null>('chartRef')
const { width } = useElementSize(chartRef)

const minutes = computed<MinuteDatum[]>(() => data.value?.pageviews_per_minute ?? [])
const x = (_: MinuteDatum, i: number) => i
const y = (d: MinuteDatum) => d.pageviews
const minuteLabel = (bucket: string) => fmt.clock(bucket)
const tooltip = (d: MinuteDatum) => `${minuteLabel(d.t)}: ${fmt.number(d.pageviews)}`
const xTicks = (i: number) => (i % 5 === 0 && minutes.value[i] ? minuteLabel(minutes.value[i].t) : '')
</script>

<template>
  <UDashboardPanel id="realtime">
    <template #header>
      <PageNavbar :title="t('nav.realtime')">
        <template #right>
          <UBadge color="success" variant="subtle" icon="i-tabler-broadcast">
            {{ t('realtime.live') }}
          </UBadge>
        </template>
      </PageNavbar>
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <template v-else>
        <UPageGrid class="lg:grid-cols-3 gap-4">
          <UPageCard
            icon="i-tabler-users"
            :title="t('realtime.active')"
            :description="t('realtime.activeHint')"
            variant="subtle"
            :ui="{ leading: 'p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col', title: 'font-normal text-muted text-xs uppercase' }"
          >
            <span class="text-4xl font-semibold text-highlighted" data-testid="active-visitors">
              {{ fmt.number(data?.active_visitors ?? null) }}
            </span>
          </UPageCard>

          <UCard ref="chartRef" class="lg:col-span-2" :ui="{ root: 'overflow-visible' }">
            <p class="text-xs text-muted uppercase mb-2">
              {{ t('realtime.perMinute') }}
            </p>
            <VisXYContainer
              v-if="minutes.length"
              :data="minutes"
              class="h-40"
              :width="width"
            >
              <VisStackedBar :x="x" :y="y" color="var(--ui-primary)" />
              <VisAxis type="x" :x="x" :tick-format="xTicks" />
              <VisTooltip :triggers="{ '.vis-stacked-bar': tooltip }" />
            </VisXYContainer>
            <div v-else class="h-40 flex items-center justify-center text-sm text-muted">
              {{ t('report.empty') }}
            </div>
          </UCard>
        </UPageGrid>

        <div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
          <UCard :ui="{ body: 'p-0 sm:p-0' }">
            <template #header>
              <p class="text-sm font-medium text-highlighted">
                {{ t('overview.topPages') }}
              </p>
            </template>
            <ul class="divide-y divide-default">
              <li v-for="page in data?.top_pages ?? []" :key="`${page.host}${page.path}`" class="flex justify-between gap-3 px-4 py-2 text-sm">
                <span class="truncate" :title="page.host">{{ page.path }}</span>
                <span class="tabular-nums text-muted">{{ fmt.number(page.pageviews) }}</span>
              </li>
              <li v-if="!data?.top_pages.length" class="px-4 py-6 text-center text-sm text-muted">
                {{ t('report.empty') }}
              </li>
            </ul>
          </UCard>
          <UCard :ui="{ body: 'p-0 sm:p-0' }">
            <template #header>
              <p class="text-sm font-medium text-highlighted">
                {{ t('overview.topSources') }}
              </p>
            </template>
            <ul class="divide-y divide-default">
              <li v-for="source in data?.top_sources ?? []" :key="`${source.channel}-${source.source}`" class="flex justify-between gap-3 px-4 py-2 text-sm">
                <span class="truncate">{{ source.source ?? source.channel }} <span class="text-muted">· {{ source.channel }}</span></span>
                <span class="tabular-nums text-muted">{{ fmt.number(source.visits) }}</span>
              </li>
              <li v-if="!data?.top_sources.length" class="px-4 py-6 text-center text-sm text-muted">
                {{ t('report.empty') }}
              </li>
            </ul>
          </UCard>
        </div>
      </template>
    </template>
  </UDashboardPanel>
</template>
