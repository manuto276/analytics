<script setup lang="ts">
import type { ApiResponse, Funnel, FunnelReport } from '~/types'

const { t } = useI18n()
const api = useApi()
const fmt = useFormatters()
const route = useRoute()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('nav.funnels') })

const { data: funnels } = useAsyncData<Funnel[]>(
  () => `funnels:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/funnels'>>(`/sites/${currentSiteId.value}/funnels`)).data : [],
  { default: () => [] }
)

const selectedId = ref<number | undefined>()
watch(funnels, (list) => {
  if (!list.some(f => f.id === selectedId.value)) selectedId.value = list[0]?.id
}, { immediate: true })

const breakdown = ref<'none' | 'channel' | 'device'>('none')
const breakdownItems = computed(() => (['none', 'channel', 'device'] as const).map(value => ({ label: t(`funnels.breakdowns.${value}`), value })))
const funnelItems = computed(() => funnels.value.map(f => ({ label: f.name, value: f.id })))

const report = useReport<{ data: FunnelReport }>(
  () => `funnels/${selectedId.value}`,
  () => ({ breakdown: breakdown.value }),
  { compare: false, filters: false, enabled: () => !!selectedId.value }
)

const funnelReport = computed(() => report.data.value?.data ?? null)
const maxCount = computed(() => Math.max(1, funnelReport.value?.entered ?? 0, ...(funnelReport.value?.steps ?? []).map(s => s.count)))

function barWidth(count: number) {
  return `${Math.max(1, Math.round((count / maxCount.value) * 100))}%`
}
</script>

<template>
  <UDashboardPanel id="funnels">
    <template #header>
      <PageNavbar :title="t('nav.funnels')">
        <template #right>
          <UButton
            v-if="canManage"
            :label="t('funnels.manage')"
            icon="i-tabler-adjustments"
            color="neutral"
            variant="subtle"
            :to="{ path: '/settings/funnels', query: { site: route.query.site } }"
          />
        </template>
      </PageNavbar>
      <ReportToolbar :compare="false" :filters="false">
        <template #right>
          <USelect
            v-model="selectedId"
            :items="funnelItems"
            :placeholder="t('funnels.select')"
            class="min-w-48"
            :aria-label="t('funnels.select')"
          />
          <USelect v-model="breakdown" :items="breakdownItems" :aria-label="t('funnels.breakdown')" />
        </template>
      </ReportToolbar>
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <UEmpty
        v-else-if="!funnels.length"
        icon="i-tabler-filter"
        :title="t('funnels.emptyTitle')"
        :description="t('funnels.emptyDescription')"
      />
      <template v-else-if="funnelReport">
        <UPageGrid class="lg:grid-cols-3 gap-4">
          <UPageCard :title="t('funnels.entered')" variant="subtle">
            <span class="text-2xl font-semibold text-highlighted">{{ fmt.number(funnelReport.entered) }}</span>
          </UPageCard>
          <UPageCard :title="t('funnels.completed')" variant="subtle">
            <span class="text-2xl font-semibold text-highlighted">{{ fmt.number(funnelReport.completed) }}</span>
          </UPageCard>
          <UPageCard :title="t('metrics.conversion_rate')" variant="subtle">
            <span class="text-2xl font-semibold text-highlighted">{{ fmt.percent(funnelReport.conversion_rate) }}</span>
          </UPageCard>
        </UPageGrid>

        <UCard>
          <ol class="space-y-4" data-testid="funnel-steps">
            <li v-for="step in funnelReport.steps" :key="step.position" class="space-y-1">
              <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-medium text-highlighted">{{ step.position }}. {{ step.name }}</span>
                <span class="text-muted tabular-nums">
                  {{ fmt.number(step.count) }} · {{ fmt.percent(step.conversion_rate) }}
                </span>
              </div>
              <div class="h-6 rounded bg-elevated overflow-hidden">
                <div class="h-full bg-primary/70 rounded" :style="{ width: barWidth(step.count) }" />
              </div>
              <p v-if="step.drop_off > 0" class="text-xs text-error">
                {{ t('funnels.dropOff', { n: fmt.number(step.drop_off) }) }}
              </p>
            </li>
          </ol>
        </UCard>

        <UCard v-if="funnelReport.breakdown.length" :ui="{ body: 'p-0 sm:p-0' }">
          <table class="w-full text-sm">
            <thead class="bg-elevated/50">
              <tr>
                <th class="text-left p-2">
                  {{ t(`funnels.breakdowns.${breakdown}`) }}
                </th>
                <th v-for="step in funnelReport.steps" :key="step.position" class="text-right p-2">
                  {{ step.name }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in funnelReport.breakdown" :key="row.value" class="border-t border-default">
                <td class="p-2">
                  {{ row.value }}
                </td>
                <td v-for="(count, i) in row.steps" :key="i" class="p-2 text-right tabular-nums">
                  {{ fmt.number(count) }}
                </td>
              </tr>
            </tbody>
          </table>
        </UCard>
      </template>
      <div v-else class="space-y-3">
        <USkeleton v-for="i in 3" :key="i" class="h-12 w-full" />
      </div>
    </template>
  </UDashboardPanel>
</template>
