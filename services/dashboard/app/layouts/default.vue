<script setup lang="ts">
import type { CommandPaletteGroup, CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { isGlobalAdmin } = useAuth()
const { ensureSites, currentSiteId } = useSites()
const { navQuery } = useReportQuery()
useDashboard()

const open = ref(false)

await ensureSites()

// Keep the selected site in the URL so links can be shared.
watch(currentSiteId, (id) => {
  if (id && route.query.site !== String(id) && !route.path.startsWith('/admin')) {
    void router.replace({ query: { ...route.query, site: String(id) } })
  }
}, { immediate: true })

function close() {
  open.value = false
}

function link(label: string, icon: string, path: string, extra: Partial<NavigationMenuItem> = {}): NavigationMenuItem {
  return { label, icon, to: { path, query: navQuery.value }, onSelect: close, ...extra }
}

const reportLinks = computed<NavigationMenuItem[]>(() => [
  link(t('nav.overview'), 'i-lucide-layout-dashboard', '/', { exact: true }),
  link(t('nav.pages'), 'i-lucide-file-text', '/pages'),
  link(t('nav.sources'), 'i-lucide-share-2', '/sources'),
  link(t('nav.campaigns'), 'i-lucide-megaphone', '/campaigns'),
  link(t('nav.audience'), 'i-lucide-users', '/audience'),
  link(t('nav.events'), 'i-lucide-mouse-pointer-click', '/events'),
  link(t('nav.goals'), 'i-lucide-target', '/goals'),
  link(t('nav.funnels'), 'i-lucide-filter', '/funnels'),
  link(t('nav.attribution'), 'i-lucide-git-fork', '/attribution'),
  link(t('nav.retention'), 'i-lucide-repeat', '/retention'),
  link(t('nav.realtime'), 'i-lucide-activity', '/realtime'),
  link(t('nav.settings'), 'i-lucide-settings', '/settings')
])

const adminLinks = computed<NavigationMenuItem[]>(() => isGlobalAdmin.value
  ? [
      { label: t('nav.admin'), type: 'label' },
      { label: t('nav.users'), icon: 'i-lucide-user-cog', to: '/admin/users', onSelect: close },
      { label: t('nav.sites'), icon: 'i-lucide-globe', to: '/admin/sites', onSelect: close },
      { label: t('nav.audit'), icon: 'i-lucide-scroll-text', to: '/admin/audit', onSelect: close }
    ]
  : [])

const groups = computed<CommandPaletteGroup<CommandPaletteItem>[]>(() => [{
  id: 'links',
  label: t('nav.goTo'),
  items: [...reportLinks.value, ...adminLinks.value.filter(l => l.type !== 'label')]
    .map(({ label, icon, to }) => ({ label, icon, to }) as CommandPaletteItem)
}])
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <SitesMenu :collapsed="collapsed" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" class="bg-transparent ring-default" />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="reportLinks"
          orientation="vertical"
          tooltip
          popover
        />

        <UNavigationMenu
          v-if="adminLinks.length"
          :collapsed="collapsed"
          :items="adminLinks"
          orientation="vertical"
          tooltip
          class="mt-auto"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="groups" />

    <slot />

    <JobsSlideover v-if="isGlobalAdmin" />
    <SiteCreateModal />
  </UDashboardGroup>
</template>
