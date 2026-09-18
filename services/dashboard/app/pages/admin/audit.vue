<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiResponse, AuditEntry } from '~/types'

type AuditResponse = ApiResponse<'/admin/audit-log'>

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
const { sites } = useSites()
useHead({ title: () => t('nav.audit') })

const siteId = ref<number | undefined>()
const action = ref('')
const actionDebounced = refDebounced(action, 400)

const entries = shallowRef<AuditEntry[]>([])
const cursor = ref<string | null>(null)
const loadingMore = ref(false)

const params = computed(() => {
  const query: Record<string, string> = { limit: '50' }
  if (siteId.value) query.site_id = String(siteId.value)
  if (actionDebounced.value.trim()) query.action = actionDebounced.value.trim()
  return query
})

const { data, status } = useAsyncData<AuditResponse>(
  () => `admin:audit:${stableStringify(params.value)}`,
  () => api<AuditResponse>('/admin/audit-log', { query: params.value })
)

watch(data, (value) => {
  if (!value) return
  entries.value = value.data
  cursor.value = value.meta.next_cursor
}, { immediate: true })

async function loadMore() {
  if (!cursor.value) return
  loadingMore.value = true
  try {
    const res = await api<AuditResponse>('/admin/audit-log', { query: { ...params.value, cursor: cursor.value } })
    entries.value = [...entries.value, ...res.data]
    cursor.value = res.meta.next_cursor
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    loadingMore.value = false
  }
}

const siteItems = computed(() => [{ label: t('admin.audit.allSites'), value: undefined }, ...sites.value.map(s => ({ label: s.name, value: s.id }))])
const siteName = (id: number | null) => (id ? sites.value.find(s => s.id === id)?.name ?? `#${id}` : EMPTY_VALUE)

const columns = computed<TableColumn<AuditEntry>[]>(() => [
  { accessorKey: 'occurred_at', header: t('admin.audit.when'), cell: ({ row }) => fmt.dateTime(row.original.occurred_at) },
  { accessorKey: 'actor_email', header: t('admin.audit.actor'), cell: ({ row }) => row.original.actor_email ?? row.original.actor_type },
  { accessorKey: 'action', header: t('admin.audit.action'), cell: ({ row }) => h('span', { class: 'font-mono text-xs' }, row.original.action) },
  { accessorKey: 'site_id', header: t('admin.audit.site'), cell: ({ row }) => siteName(row.original.site_id) },
  { accessorKey: 'target_type', header: t('admin.audit.target'), cell: ({ row }) => [row.original.target_type, row.original.target_id].filter(Boolean).join(' #') || EMPTY_VALUE },
  { accessorKey: 'metadata', header: t('admin.audit.details'), cell: ({ row }) => h('span', { class: 'font-mono text-xs truncate block max-w-xs', title: JSON.stringify(row.original.metadata) }, JSON.stringify(row.original.metadata)) },
  { accessorKey: 'ip_prefix', header: t('admin.audit.ip'), cell: ({ row }) => row.original.ip_prefix ?? EMPTY_VALUE }
])
</script>

<template>
  <UDashboardPanel id="admin-audit">
    <template #header>
      <PageNavbar :title="t('nav.audit')" />
      <UDashboardToolbar>
        <template #left>
          <USelect
            v-model="siteId"
            :items="siteItems"
            class="min-w-44"
            :aria-label="t('admin.audit.site')"
          />
          <UInput
            v-model="action"
            icon="i-tabler-search"
            :placeholder="t('admin.audit.actionFilter')"
            class="max-w-xs"
          />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <UTable
        :data="entries"
        :columns="columns"
        :loading="status === 'pending'"
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
      <div v-if="cursor" class="flex justify-center">
        <UButton
          :label="t('report.loadMore')"
          color="neutral"
          variant="subtle"
          :loading="loadingMore"
          @click="loadMore"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
