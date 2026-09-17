<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const { t } = useI18n()
const route = useRoute()
const { canManage } = useSites()

const links = computed<NavigationMenuItem[][]>(() => {
  const query = { site: route.query.site }
  const items: NavigationMenuItem[] = [
    { label: t('settings.nav.site'), icon: 'i-lucide-globe', to: { path: '/settings', query }, exact: true },
    ...(canManage.value ? [{ label: t('settings.nav.members'), icon: 'i-lucide-users', to: { path: '/settings/members', query } }] : []),
    { label: t('settings.nav.consent'), icon: 'i-lucide-cookie', to: { path: '/settings/consent', query } },
    { label: t('settings.nav.goals'), icon: 'i-lucide-target', to: { path: '/settings/goals', query } },
    { label: t('settings.nav.funnels'), icon: 'i-lucide-filter', to: { path: '/settings/funnels', query } },
    { label: t('settings.nav.costs'), icon: 'i-lucide-receipt', to: { path: '/settings/costs', query } },
    ...(canManage.value ? [{ label: t('settings.nav.apiKeys'), icon: 'i-lucide-key-round', to: { path: '/settings/api-keys', query } }] : []),
    { label: t('settings.nav.security'), icon: 'i-lucide-shield', to: { path: '/settings/security', query } }
  ]
  return [items]
})

const wide = computed(() => ['/settings/consent', '/settings/costs'].includes(route.path))
</script>

<template>
  <UDashboardPanel id="settings" :ui="{ body: 'lg:py-12' }">
    <template #header>
      <PageNavbar :title="t('nav.settings')" />

      <UDashboardToolbar>
        <!-- NOTE: The `-mx-1` class is used to align with the `DashboardSidebarCollapse` button here. -->
        <UNavigationMenu :items="links" highlight class="-mx-1 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 lg:gap-12 w-full mx-auto" :class="wide ? 'lg:max-w-5xl' : 'lg:max-w-2xl'">
        <NuxtPage />
      </div>
    </template>
  </UDashboardPanel>
</template>
