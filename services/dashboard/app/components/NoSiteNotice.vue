<script setup lang="ts">
const { t } = useI18n()
const { isGlobalAdmin } = useAuth()
const { isSiteModalOpen } = useDashboard()
</script>

<template>
  <UEmpty
    icon="i-tabler-world"
    :title="t('sites.emptyTitle')"
    :description="isGlobalAdmin ? t('sites.emptyAdmin') : t('sites.emptyMember')"
    :actions="isGlobalAdmin ? [{ label: t('sites.add'), icon: 'i-tabler-circle-plus', onClick: () => { isSiteModalOpen = true } }] : []"
    class="flex-1"
  />
</template>
