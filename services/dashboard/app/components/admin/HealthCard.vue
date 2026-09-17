<script setup lang="ts">
import type { ApiResponse } from '~/types'

type Health = ApiResponse<'/admin/health'>['data']

const { t } = useI18n()
const api = useApi()

const { data, status, refresh } = useAsyncData<Health | null>(
  'admin:health',
  async () => (await api<ApiResponse<'/admin/health'>>('/admin/health', { skipForbiddenToast: true })).data,
  { default: () => null }
)

const colors = { ok: 'success', warn: 'warning', fail: 'error' } as const

defineExpose({ refresh })
</script>

<template>
  <UCard :ui="{ body: 'p-4 sm:p-4' }" data-testid="health-card">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div class="flex items-center gap-2">
        <p class="text-sm font-medium text-highlighted">
          {{ t('admin.health.title') }}
        </p>
        <UBadge
          v-if="data"
          :color="colors[data.status]"
          variant="subtle"
          :label="t(`admin.health.status.${data.status}`)"
        />
      </div>
      <div class="flex items-center gap-2">
        <span v-if="data" class="text-xs text-muted">
          {{ t('user.version', { version: data.version, commit: data.commit.slice(0, 7) }) }}
        </span>
        <UButton
          icon="i-lucide-refresh-cw"
          size="xs"
          color="neutral"
          variant="ghost"
          :loading="status === 'pending'"
          :aria-label="t('admin.health.refresh')"
          @click="refresh()"
        />
      </div>
    </div>

    <USkeleton v-if="!data && status === 'pending'" class="h-16 w-full" />
    <ul v-else-if="data" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
      <li v-for="(check, name) in data.checks" :key="name" class="flex items-start gap-2 text-sm">
        <UIcon
          :name="check.status === 'ok' ? 'i-lucide-circle-check' : check.status === 'warn' ? 'i-lucide-circle-alert' : 'i-lucide-circle-x'"
          class="size-4 mt-0.5 shrink-0"
          :class="check.status === 'ok' ? 'text-success' : check.status === 'warn' ? 'text-warning' : 'text-error'"
        />
        <span class="min-w-0">
          <span class="font-mono text-xs text-highlighted">{{ name }}</span>
          <span class="block text-xs text-muted truncate" :title="check.detail">{{ check.detail }}</span>
        </span>
      </li>
    </ul>
    <p v-else class="text-sm text-muted">
      {{ t('jobs.unavailable') }}
    </p>
  </UCard>
</template>
