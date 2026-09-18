<script setup lang="ts">
import type { CohortReport, ConsentReport } from '~/types'

const { t } = useI18n()
const fmt = useFormatters()
const { currentSite } = useSites()
useHead({ title: () => t('nav.retention') })

const cohort = ref<'week' | 'month'>('month')
const periods = ref(6)
const cohortItems = computed(() => (['week', 'month'] as const).map(value => ({ label: t(`retention.cohorts.${value}`), value })))

const cohorts = useReport<{ data: CohortReport }>('cohorts', () => ({ cohort: cohort.value, periods: periods.value }), { range: false })
const consent = useReport<{ data: ConsentReport }>('consent', {}, { compare: false, filters: false })

const cohortRows = computed(() => cohorts.data.value?.data.rows ?? [])
const consentData = computed(() => consent.data.value?.data ?? null)

function cellClass(value: number | null | undefined) {
  if (value === null || value === undefined) return 'text-dimmed'
  if (value >= 0.5) return 'bg-primary/60 text-highlighted'
  if (value >= 0.25) return 'bg-primary/40 text-highlighted'
  if (value >= 0.1) return 'bg-primary/20'
  if (value > 0) return 'bg-primary/10'
  return ''
}
</script>

<template>
  <UDashboardPanel id="retention">
    <template #header>
      <PageNavbar :title="t('nav.retention')" />
      <ReportToolbar :compare="false" :filters="false" />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <template v-else>
        <UCard :ui="{ body: 'p-0 sm:p-0 overflow-x-auto' }">
          <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="text-sm font-medium text-highlighted">
                  {{ t('retention.title') }}
                </p>
                <p class="text-xs text-muted">
                  {{ t('retention.description') }}
                </p>
              </div>
              <div class="flex items-center gap-2">
                <USelect
                  v-model="cohort"
                  :items="cohortItems"
                  size="sm"
                  :aria-label="t('retention.cohort')"
                />
                <UInputNumber
                  v-model="periods"
                  :min="1"
                  :max="13"
                  size="sm"
                  class="w-24"
                  :aria-label="t('retention.periods')"
                />
              </div>
            </div>
          </template>

          <table v-if="cohortRows.length" class="w-full text-sm" data-testid="cohort-grid">
            <thead class="bg-elevated/50">
              <tr>
                <th class="text-left p-2">
                  {{ t('retention.cohort') }}
                </th>
                <th class="text-right p-2">
                  {{ t('retention.size') }}
                </th>
                <th v-for="i in periods" :key="i" class="text-center p-2">
                  {{ t(`retention.offset.${cohort}`, { n: i - 1 }) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in cohortRows" :key="row.cohort" class="border-t border-default">
                <td class="p-2 whitespace-nowrap">
                  {{ fmt.day(row.cohort, cohort === 'month' ? { month: 'short', year: 'numeric' } : { dateStyle: 'medium' }) }}
                </td>
                <td class="p-2 text-right tabular-nums">
                  {{ fmt.number(row.size) }}
                </td>
                <td
                  v-for="i in periods"
                  :key="i"
                  class="p-2 text-center tabular-nums"
                  :class="cellClass(row.retained[i - 1])"
                >
                  {{ fmt.percent(row.retained[i - 1]) }}
                </td>
              </tr>
            </tbody>
          </table>
          <UEmpty
            v-else
            icon="i-tabler-repeat"
            :title="t('report.empty')"
            :description="t('retention.consentOnly')"
          />
        </UCard>

        <UCard :ui="{ body: 'p-0 sm:p-0 overflow-x-auto' }">
          <template #header>
            <p class="text-sm font-medium text-highlighted">
              {{ t('retention.consentTitle') }}
            </p>
          </template>
          <div v-if="consentData" class="grid grid-cols-2 lg:grid-cols-6 gap-px bg-default">
            <div v-for="key in (['shown', 'accepted', 'rejected', 'dismissed', 'reopened'] as const)" :key="key" class="bg-default p-3">
              <p class="text-xs text-muted uppercase">
                {{ t(`retention.consent.${key}`) }}
              </p>
              <p class="text-lg font-semibold text-highlighted">
                {{ fmt.number(consentData.totals[key]) }}
              </p>
            </div>
            <div class="bg-default p-3">
              <p class="text-xs text-muted uppercase">
                {{ t('retention.consent.acceptance_rate') }}
              </p>
              <p class="text-lg font-semibold text-highlighted">
                {{ fmt.percent(consentData.totals.acceptance_rate) }}
              </p>
            </div>
          </div>
          <table v-if="consentData?.rows.length" class="w-full text-sm">
            <thead class="bg-elevated/50">
              <tr>
                <th class="text-left p-2">
                  {{ t('retention.consent.day') }}
                </th>
                <th class="text-right p-2">
                  {{ t('retention.consent.version') }}
                </th>
                <th v-for="key in (['shown', 'accepted', 'rejected', 'dismissed', 'reopened'] as const)" :key="key" class="text-right p-2">
                  {{ t(`retention.consent.${key}`) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in consentData.rows" :key="`${row.day}-${row.consent_version}`" class="border-t border-default">
                <td class="p-2">
                  {{ fmt.day(row.day) }}
                </td>
                <td class="p-2 text-right">
                  {{ row.consent_version }}
                </td>
                <td v-for="key in (['shown', 'accepted', 'rejected', 'dismissed', 'reopened'] as const)" :key="key" class="p-2 text-right tabular-nums">
                  {{ fmt.number(row[key]) }}
                </td>
              </tr>
            </tbody>
          </table>
        </UCard>
      </template>
    </template>
  </UDashboardPanel>
</template>
