<script setup lang="ts">
const { t } = useI18n()
const { currentSite } = useSites()
const { navQuery } = useReportQuery()
useHead({ title: () => t('nav.sources') })

const group = ref<'channel' | 'source' | 'referrer'>('channel')
const tabs = computed(() => (['channel', 'source', 'referrer'] as const).map(value => ({ label: t(`sources.groups.${value}`), value })))
</script>

<template>
  <UDashboardPanel id="sources">
    <template #header>
      <PageNavbar :title="t('nav.sources')">
        <template #right>
          <UButton
            :label="t('sources.campaignsLink')"
            icon="i-tabler-speakerphone"
            color="neutral"
            variant="subtle"
            :to="{ path: '/campaigns', query: navQuery }"
          />
        </template>
      </PageNavbar>
      <ReportToolbar />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <ReportTable
        v-else
        report="sources"
        :columns="SOURCE_COLUMNS[group]"
        :params="{ group }"
      >
        <template #header>
          <UTabs
            v-model="group"
            :items="tabs"
            :content="false"
            size="xs"
          />
        </template>
      </ReportTable>
    </template>
  </UDashboardPanel>
</template>
