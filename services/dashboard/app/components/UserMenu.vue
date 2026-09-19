<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const { t, locale, locales } = useI18n()
const colorMode = useColorMode()
const { user, config, loadConfig, logout, setLocale } = useAuth()
const route = useRoute()

onMounted(() => {
  void loadConfig()
})

const displayName = computed(() => user.value?.display_name || user.value?.email || '')
const shortCommit = computed(() => (config.value?.commit ?? '').slice(0, 7))

const items = computed<DropdownMenuItem[][]>(() => {
  const groups: DropdownMenuItem[][] = [[{
    type: 'label',
    label: displayName.value,
    description: user.value?.email,
    avatar: { alt: displayName.value }
  }], [{
    label: t('user.profile'),
    icon: 'i-tabler-user',
    to: { path: '/settings/profile', query: { site: route.query.site } }
  }, {
    label: t('user.security'),
    icon: 'i-tabler-shield',
    to: { path: '/settings/security', query: { site: route.query.site } }
  }, {
    label: t('user.language'),
    icon: 'i-tabler-language',
    children: locales.value.map(l => ({
      label: l.name ?? l.code,
      type: 'checkbox' as const,
      checked: locale.value === l.code,
      onSelect(e: Event) {
        e.preventDefault()
        void setLocale(l.code as 'en' | 'it')
      }
    }))
  }, {
    label: t('user.appearance'),
    icon: 'i-tabler-sun-moon',
    children: (['system', 'light', 'dark'] as const).map(mode => ({
      label: t(`user.mode.${mode}`),
      icon: mode === 'light' ? 'i-tabler-sun' : mode === 'dark' ? 'i-tabler-moon' : 'i-tabler-device-desktop',
      type: 'checkbox' as const,
      checked: colorMode.preference === mode,
      onSelect(e: Event) {
        e.preventDefault()
        colorMode.preference = mode
      }
    }))
  }]]

  const about: DropdownMenuItem[] = []
  if (config.value) {
    about.push({
      type: 'label',
      label: t('user.version', { version: config.value.version, commit: shortCommit.value })
    })
  }
  if (config.value?.source_url) {
    about.push({
      label: t('user.source'),
      description: t('user.sourceLicense'),
      icon: 'i-tabler-code',
      to: config.value.source_url,
      target: '_blank'
    })
  }
  if (about.length) groups.push(about)

  groups.push([{
    label: t('user.logout'),
    icon: 'i-tabler-logout',
    onSelect() {
      void logout()
    }
  }])

  return groups
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      :avatar="{ alt: displayName }"
      :label="collapsed ? undefined : displayName"
      :trailing-icon="collapsed ? undefined : 'i-tabler-selector'"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    />
  </UDropdownMenu>
</template>
