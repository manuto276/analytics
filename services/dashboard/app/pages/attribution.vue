<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AttributionReport } from '~/types'

type AttributionRow = AttributionReport['rows'][number]

const { t } = useI18n()
const fmt = useFormatters()
const { currentSite } = useSites()
useHead({ title: () => t('nav.attribution') })

const model = ref<'first_touch' | 'last_non_direct' | 'declared'>('first_touch')
const group = ref<'channel' | 'source' | 'campaign'>('channel')
const windowDays = ref<7 | 30 | 90>(30)
const base = ref<string | undefined>()
const target = ref<string | undefined>()

const conversions = useReport('conversions', { limit: 100 }, { compare: false, filters: false })
const conversionNames = computed(() => conversions.rows.value.map(r => String(r.name ?? '')).filter(Boolean))

const report = useReport<{ data: AttributionReport }>('attribution', () => ({
  model: model.value,
  group: group.value,
  window: windowDays.value,
  base: base.value,
  target: target.value
}), { compare: false, filters: false })

const result = computed(() => report.data.value?.data ?? null)

const modelItems = computed(() => (['first_touch', 'last_non_direct', 'declared'] as const).map(value => ({ label: t(`attribution.models.${value}`), value })))
const groupItems = computed(() => (['channel', 'source', 'campaign'] as const).map(value => ({ label: t(`attribution.groups.${value}`), value })))
const windowItems = computed(() => ([7, 30, 90] as const).map(value => ({ label: t('attribution.windowDays', { n: value }), value })))

const columns = computed<TableColumn<AttributionRow>[]>(() => [
  { accessorKey: 'key', header: t(`attribution.groups.${group.value}`), cell: ({ row }) => row.original.key || t('attribution.unattributed') },
  { accessorKey: 'visits', header: t('metrics.visits'), cell: ({ row }) => fmt.number(row.original.visits) },
  { accessorKey: 'base_conversions', header: t('attribution.baseConversions'), cell: ({ row }) => fmt.number(row.original.base_conversions) },
  { accessorKey: 'target_conversions', header: t('attribution.targetConversions'), cell: ({ row }) => fmt.number(row.original.target_conversions) },
  { accessorKey: 'revenue_minor', header: t('metrics.revenue_minor'), cell: ({ row }) => fmt.money(row.original.revenue_minor) },
  { accessorKey: 'cost_minor', header: t('attribution.cost'), cell: ({ row }) => fmt.money(row.original.cost_minor) },
  { accessorKey: 'cac_minor', header: t('attribution.cac'), cell: ({ row }) => fmt.money(row.original.cac_minor) },
  { accessorKey: 'roas', header: t('attribution.roas'), cell: ({ row }) => row.original.roas === null ? EMPTY_VALUE : `${fmt.number(row.original.roas)}×` }
])

async function exportCsv() {
  await report.downloadCsv('attribution.csv')
}
</script>

<template>
  <UDashboardPanel id="attribution">
    <template #header>
      <PageNavbar :title="t('nav.attribution')" />
      <ReportToolbar :compare="false" :filters="false">
        <template #right>
          <UButton
            icon="i-lucide-download"
            :label="t('report.csv')"
            color="neutral"
            variant="ghost"
            @click="exportCsv"
          />
        </template>
      </ReportToolbar>
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <template v-else>
        <div class="flex flex-wrap items-end gap-3">
          <UFormField :label="t('attribution.model')">
            <USelect v-model="model" :items="modelItems" class="min-w-44" />
          </UFormField>
          <UFormField :label="t('attribution.group')">
            <USelect v-model="group" :items="groupItems" class="min-w-36" />
          </UFormField>
          <UFormField :label="t('attribution.window')">
            <USelect v-model="windowDays" :items="windowItems" class="min-w-28" />
          </UFormField>
          <UFormField :label="t('attribution.base')">
            <USelectMenu
              v-model="base"
              :items="conversionNames"
              :placeholder="t('attribution.anyConversion')"
              clear
              class="min-w-44"
            />
          </UFormField>
          <UFormField :label="t('attribution.target')">
            <USelectMenu
              v-model="target"
              :items="conversionNames"
              :placeholder="t('attribution.anyConversion')"
              clear
              class="min-w-44"
            />
          </UFormField>
        </div>

        <UPageGrid v-if="result" class="lg:grid-cols-5 gap-4">
          <UPageCard :title="t('attribution.baseConversions')" variant="subtle">
            <span class="text-xl font-semibold text-highlighted">{{ fmt.number(result.totals.base_conversions) }}</span>
          </UPageCard>
          <UPageCard :title="t('attribution.targetConversions')" variant="subtle">
            <span class="text-xl font-semibold text-highlighted">{{ fmt.number(result.totals.target_conversions) }}</span>
          </UPageCard>
          <UPageCard :title="t('metrics.revenue_minor')" variant="subtle">
            <span class="text-xl font-semibold text-highlighted">{{ fmt.money(result.totals.revenue_minor) }}</span>
          </UPageCard>
          <UPageCard :title="t('attribution.cost')" variant="subtle">
            <span class="text-xl font-semibold text-highlighted">{{ fmt.money(result.totals.cost_minor) }}</span>
          </UPageCard>
          <UPageCard :title="t('attribution.unattributedShare')" variant="subtle">
            <span class="text-xl font-semibold text-highlighted">{{ fmt.percent(result.totals.unattributed_share) }}</span>
          </UPageCard>
        </UPageGrid>

        <UTable
          :data="result?.rows ?? []"
          :columns="columns"
          :loading="report.loading.value"
          :empty="t('report.empty')"
          class="shrink-0"
          :ui="{
            base: 'table-fixed border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
            td: 'border-b border-default'
          }"
        />
        <p class="text-xs text-muted px-1">
          {{ t('attribution.hint') }}
        </p>
      </template>
    </template>
  </UDashboardPanel>
</template>
