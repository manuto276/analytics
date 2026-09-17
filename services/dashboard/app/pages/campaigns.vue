<script setup lang="ts">
const { t } = useI18n()
const { currentSite } = useSites()
useHead({ title: () => t('nav.campaigns') })
</script>

<template>
  <UDashboardPanel id="campaigns">
    <template #header>
      <PageNavbar :title="t('nav.campaigns')" />
      <ReportToolbar />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <ReportTable
        v-else
        report="campaigns"
        :title="t('campaigns.title')"
        :columns="CAMPAIGN_COLUMNS"
      />
    </template>
  </UDashboardPanel>
</template>
