<script setup lang="ts">
defineProps<{
  title: string
}>()

const { t } = useI18n()
const { isGlobalAdmin } = useAuth()
const { isJobsSlideoverOpen } = useDashboard()
</script>

<template>
  <UDashboardNavbar :title="title" :ui="{ right: 'gap-3' }">
    <template #leading>
      <UDashboardSidebarCollapse />
    </template>

    <template #right>
      <slot name="right" />

      <UTooltip v-if="isGlobalAdmin" :text="t('jobs.title')">
        <UButton
          color="neutral"
          variant="ghost"
          square
          icon="i-tabler-server-cog"
          :aria-label="t('jobs.title')"
          @click="isJobsSlideoverOpen = true"
        />
      </UTooltip>
    </template>
  </UDashboardNavbar>
</template>
