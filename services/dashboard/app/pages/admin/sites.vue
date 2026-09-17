<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiResponse, Site } from '~/types'

const { t } = useI18n()
const api = useApi()
const fmt = useFormatters()
const toast = useToast()
const { isSiteModalOpen } = useDashboard()
const { updateSite, refreshSites } = useSites()
useHead({ title: () => t('nav.sites') })

const UButton = resolveComponent('UButton')
const UBadge = resolveComponent('UBadge')

// archived=1 returns every site, archived ones included (global admins only).
const { data: all, status, refresh } = useAsyncData<Site[]>(
  'admin:sites:all',
  async () => [...(await api<ApiResponse<'/sites'>>('/sites', { query: { archived: '1' } })).data].sort((a, b) => a.name.localeCompare(b.name)),
  { default: () => [] }
)

async function restore(site: Site) {
  try {
    await updateSite(site.id, { archived: false })
    toast.add({ title: t('admin.sites.restored'), color: 'success' })
    await Promise.all([refresh(), refreshSites()])
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

watch(isSiteModalOpen, (open) => {
  if (!open) void refresh()
})

const columns = computed<TableColumn<Site>[]>(() => [
  { accessorKey: 'name', header: t('site.name'), cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name) },
  { accessorKey: 'domains', header: t('site.domains'), cell: ({ row }) => row.original.domains.map(d => (d.include_subdomains ? `*.${d.host}` : d.host)).join(', ') },
  { accessorKey: 'timezone', header: t('site.timezone') },
  { accessorKey: 'public_key', header: t('settings.site.publicKey'), cell: ({ row }) => h('span', { class: 'font-mono text-xs' }, row.original.public_key) },
  { accessorKey: 'archived', header: t('admin.users.status'), cell: ({ row }) => h(UBadge, { color: row.original.archived ? 'neutral' : 'success', variant: 'subtle', label: row.original.archived ? t('admin.sites.archived') : t('admin.sites.active') }) },
  { accessorKey: 'created_at', header: t('admin.sites.created'), cell: ({ row }) => fmt.dateTime(row.original.created_at) },
  {
    id: 'actions',
    cell: ({ row }) => row.original.archived
      ? h(UButton, { 'label': t('admin.sites.restore'), 'size': 'xs', 'color': 'neutral', 'variant': 'subtle', 'data-testid': 'restore-site', 'onClick': () => restore(row.original) })
      : h(UButton, { label: t('nav.settings'), size: 'xs', color: 'neutral', variant: 'ghost', to: { path: '/settings', query: { site: String(row.original.id) } } })
  }
])
</script>

<template>
  <UDashboardPanel id="admin-sites">
    <template #header>
      <PageNavbar :title="t('nav.sites')">
        <template #right>
          <UButton :label="t('sites.add')" icon="i-lucide-circle-plus" @click="isSiteModalOpen = true" />
        </template>
      </PageNavbar>
    </template>

    <template #body>
      <AdminHealthCard />

      <UTable
        :data="all"
        :columns="columns"
        :loading="status === 'pending'"
        class="shrink-0"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default'
        }"
      />
    </template>
  </UDashboardPanel>
</template>
