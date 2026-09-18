<script setup lang="ts">
const { t } = useI18n()
const { currentSite, canManage } = useSites()
const route = useRoute()
useHead({ title: () => t('nav.goals') })
</script>

<template>
  <UDashboardPanel id="goals">
    <template #header>
      <PageNavbar :title="t('nav.goals')">
        <template #right>
          <UButton
            v-if="canManage"
            :label="t('goals.manage')"
            icon="i-tabler-adjustments"
            color="neutral"
            variant="subtle"
            :to="{ path: '/settings/goals', query: { site: route.query.site } }"
          />
        </template>
      </PageNavbar>
      <ReportToolbar />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <div v-else class="grid xl:grid-cols-2 gap-4 sm:gap-6">
        <ReportTable report="goals" :title="t('goals.completions')" :columns="GOAL_COLUMNS" />
        <ReportTable report="conversions" :title="t('goals.conversions')" :columns="CONVERSION_COLUMNS" />
      </div>
    </template>
  </UDashboardPanel>
</template>
