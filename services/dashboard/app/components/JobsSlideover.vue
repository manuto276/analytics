<script setup lang="ts">
import type { ApiResponse, JobsStatus } from '~/types'

const { t } = useI18n()
const api = useApi()
const fmt = useFormatters()
const { isJobsSlideoverOpen } = useDashboard()

const { data, status, refresh } = useAsyncData<JobsStatus | null>('admin:jobs', async () => {
  const res = await api<ApiResponse<'/admin/jobs'>>('/admin/jobs', { skipForbiddenToast: true })
  return res.data
}, { default: () => null })

const ROLLUP_LAG_WARN_MINUTES = 90
const GEO_DB_STALE_DAYS = 45

const failingJobs = computed(() => (data.value?.jobs ?? []).filter(job => job.last_status && !['ok', 'success', 'running'].includes(job.last_status)))

const issues = computed(() => {
  const d = data.value
  if (!d) return 0
  return failingJobs.value.length
    + (d.rollup_lag_minutes > ROLLUP_LAG_WARN_MINUTES ? 1 : 0)
    + (d.geo_db_age_days === null || d.geo_db_age_days > GEO_DB_STALE_DAYS ? 1 : 0)
    + d.pending_consent_drafts.length
})

watch(isJobsSlideoverOpen, (open) => {
  if (open) void refresh()
})

defineExpose({ issues })
</script>

<template>
  <USlideover v-model:open="isJobsSlideoverOpen" :title="t('jobs.title')" :description="t('jobs.description')">
    <template #body>
      <div v-if="status === 'pending' && !data" class="space-y-3">
        <USkeleton v-for="i in 4" :key="i" class="h-12 w-full" />
      </div>

      <div v-else-if="data" class="space-y-6">
        <div class="grid grid-cols-2 gap-3">
          <UCard :ui="{ body: 'p-3 sm:p-3' }">
            <p class="text-xs text-muted uppercase">
              {{ t('jobs.rollupLag') }}
            </p>
            <p class="text-xl font-semibold" :class="data.rollup_lag_minutes > ROLLUP_LAG_WARN_MINUTES ? 'text-error' : 'text-highlighted'">
              {{ t('jobs.minutes', { n: data.rollup_lag_minutes }) }}
            </p>
            <p class="text-xs text-muted">
              {{ t('jobs.dirtyDays', { n: data.dirty_days }) }}
            </p>
          </UCard>
          <UCard :ui="{ body: 'p-3 sm:p-3' }">
            <p class="text-xs text-muted uppercase">
              {{ t('jobs.geoDb') }}
            </p>
            <p
              class="text-xl font-semibold"
              :class="data.geo_db_age_days === null || data.geo_db_age_days > GEO_DB_STALE_DAYS ? 'text-warning' : 'text-highlighted'"
            >
              {{ data.geo_db_age_days === null ? t('jobs.geoMissing') : t('jobs.days', { n: data.geo_db_age_days }) }}
            </p>
          </UCard>
        </div>

        <div v-if="data.pending_consent_drafts.length">
          <p class="text-sm font-medium text-highlighted mb-2">
            {{ t('jobs.pendingDrafts') }}
          </p>
          <ul class="space-y-1">
            <li v-for="draft in data.pending_consent_drafts" :key="draft.site_id">
              <ULink :to="{ path: '/settings/consent', query: { site: String(draft.site_id) } }" class="text-sm">
                {{ draft.site_name }}
              </ULink>
            </li>
          </ul>
        </div>

        <div>
          <p class="text-sm font-medium text-highlighted mb-2">
            {{ t('jobs.jobs') }}
          </p>
          <ul class="divide-y divide-default">
            <li v-for="job in data.jobs" :key="job.job" class="py-2 flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-mono text-highlighted truncate">
                  {{ job.job }}
                </p>
                <p class="text-xs text-muted">
                  {{ job.last_started_at ? fmt.dateTime(job.last_started_at) : t('jobs.never') }}
                  <template v-if="job.last_duration_ms !== null">
                    · {{ fmt.duration(job.last_duration_ms) }}
                  </template>
                </p>
                <p v-if="job.last_message" class="text-xs text-error truncate">
                  {{ job.last_message }}
                </p>
              </div>
              <UBadge
                :color="failingJobs.includes(job) ? 'error' : job.last_status ? 'success' : 'neutral'"
                variant="subtle"
                :label="job.last_status ?? t('jobs.unknown')"
              />
            </li>
          </ul>
        </div>
      </div>

      <UEmpty v-else icon="i-lucide-server-off" :title="t('jobs.unavailable')" />
    </template>
  </USlideover>
</template>
