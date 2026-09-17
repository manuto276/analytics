<script setup lang="ts">
import type { ApiResponse } from '~/types'
import type { ChartMetric } from '~/utils/chart'

const { t } = useI18n()
const { currentSite } = useSites()
const q = useReportQuery()

useHead({ title: () => t('nav.overview') })

const metric = ref<ChartMetric>('visitors')

const overview = useReport<ApiResponse<'/sites/{siteId}/reports/overview'>>('overview', {}, { compare: true })
const timeseries = useReport<ApiResponse<'/sites/{siteId}/reports/timeseries'>>('timeseries', {}, { interval: true, compare: true })

watch(() => overview.availability.value.visitors, (available) => {
  if (!available && metric.value === 'visitors') metric.value = 'visits'
})
</script>

<template>
  <UDashboardPanel id="overview">
    <template #header>
      <PageNavbar :title="t('nav.overview')" />
      <ReportToolbar interval />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <template v-else>
        <OverviewStats
          v-model:metric="metric"
          :metrics="overview.data.value?.data.metrics ?? null"
          :deltas="overview.data.value?.data.deltas ?? null"
          :availability="overview.availability.value"
          :loading="overview.loading.value"
        />
        <OverviewChart
          :points="timeseries.data.value?.data.points ?? []"
          :compare-points="timeseries.data.value?.data.compare_points ?? null"
          :metric="metric"
          :interval="(timeseries.meta.value?.interval ?? q.interval.value)"
          :loading="timeseries.loading.value"
        />
        <div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
          <ReportTable
            report="pages"
            :title="t('overview.topPages')"
            :columns="TOP_PAGES_COLUMNS"
            :limit="10"
            :paginate="false"
            :csv="false"
          />
          <ReportTable
            report="sources"
            :title="t('overview.topSources')"
            :columns="TOP_SOURCES_COLUMNS"
            :params="{ group: 'channel' }"
            :limit="10"
            :paginate="false"
            :csv="false"
          />
        </div>
      </template>
    </template>
  </UDashboardPanel>
</template>
