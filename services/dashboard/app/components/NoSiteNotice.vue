<script setup lang="ts">
const { t } = useI18n()
const { isGlobalAdmin } = useAuth()
const { isSiteModalOpen } = useDashboard()
</script>

<template>
  <UEmpty
    icon="i-lucide-globe"
    :title="t('sites.emptyTitle')"
    :description="isGlobalAdmin ? t('sites.emptyAdmin') : t('sites.emptyMember')"
    :actions="isGlobalAdmin ? [{ label: t('sites.add'), icon: 'i-lucide-circle-plus', onClick: () => { isSiteModalOpen = true } }] : []"
    class="flex-1"
  />
</template>
