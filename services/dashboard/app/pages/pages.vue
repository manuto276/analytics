<script setup lang="ts">
const { t } = useI18n()
const { currentSite } = useSites()
useHead({ title: () => t('nav.pages') })

const kind = ref<'top' | 'entry' | 'exit'>('top')
const tabs = computed(() => (['top', 'entry', 'exit'] as const).map(value => ({ label: t(`pages.kinds.${value}`), value })))

// Content groups (content_key) live with the pages report; the prefix narrows them down.
const prefix = ref('')
const prefixDebounced = refDebounced(prefix, 400)
</script>

<template>
  <UDashboardPanel id="pages">
    <template #header>
      <PageNavbar :title="t('nav.pages')" />
      <ReportToolbar />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <template v-else>
        <ReportTable report="pages" :columns="PAGE_COLUMNS[kind]" :params="{ kind }">
          <template #header>
            <UTabs
              v-model="kind"
              :items="tabs"
              :content="false"
              size="xs"
            />
          </template>
        </ReportTable>
        <ReportTable report="landing-pages" :title="t('pages.landing')" :columns="LANDING_COLUMNS" />
        <ReportTable
          report="content"
          :title="t('pages.content')"
          :columns="CONTENT_COLUMNS"
          :params="{ prefix: prefixDebounced }"
        >
          <template #header>
            <UInput
              v-model="prefix"
              icon="i-lucide-search"
              size="xs"
              :placeholder="t('pages.contentPrefix')"
              :aria-label="t('pages.contentPrefix')"
              data-testid="content-prefix"
            />
          </template>
        </ReportTable>
      </template>
    </template>
  </UDashboardPanel>
</template>
