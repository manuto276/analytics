<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const { t } = useI18n()
const { sites, currentSite, selectSite } = useSites()
const { isGlobalAdmin } = useAuth()
const { isSiteModalOpen } = useDashboard()

const activeSites = computed(() => (sites.value ?? []).filter(site => !site.archived))

const items = computed<DropdownMenuItem[][]>(() => {
  const groups: DropdownMenuItem[][] = [activeSites.value.map(site => ({
    label: site.name,
    avatar: { alt: site.name, size: 'xs' as const },
    type: 'checkbox' as const,
    checked: site.id === currentSite.value?.id,
    onSelect() {
      void selectSite(site.id)
    }
  }))]

  if (isGlobalAdmin.value) {
    groups.push([{
      label: t('sites.add'),
      icon: 'i-tabler-circle-plus',
      onSelect() {
        isSiteModalOpen.value = true
      }
    }, {
      label: t('sites.manage'),
      icon: 'i-tabler-settings-cog',
      to: '/admin/sites'
    }])
  }

  return groups
})

const buttonLabel = computed(() => currentSite.value?.name ?? t('sites.none'))
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-40' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      :avatar="{ alt: buttonLabel }"
      :label="collapsed ? undefined : buttonLabel"
      :trailing-icon="collapsed ? undefined : 'i-tabler-selector'"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
      data-testid="sites-menu"
    />
  </UDropdownMenu>
</template>
