<script setup lang="ts">
const { t } = useI18n()
const { currentSite } = useSites()
useHead({ title: () => t('nav.audience') })

const group = ref<'device' | 'browser' | 'os'>('device')
const tabs = computed(() => (['device', 'browser', 'os'] as const).map(value => ({ label: t(`audience.groups.${value}`), value })))
</script>

<template>
  <UDashboardPanel id="audience">
    <template #header>
      <PageNavbar :title="t('nav.audience')" />
      <ReportToolbar />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <div v-else class="grid xl:grid-cols-2 gap-4 sm:gap-6">
        <ReportTable report="tech" :columns="TECH_COLUMNS[group]" :params="{ group }">
          <template #header>
            <UTabs
              v-model="group"
              :items="tabs"
              :content="false"
              size="xs"
            />
          </template>
        </ReportTable>
        <div class="space-y-2">
          <ReportTable report="countries" :title="t('audience.countries')" :columns="COUNTRY_COLUMNS" />
          <p class="text-xs text-muted px-1">
            {{ t('audience.geoAttribution') }}
            <ULink to="https://db-ip.com" target="_blank" class="underline">
              {{ t('audience.geoProvider') }}
            </ULink>
          </p>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
