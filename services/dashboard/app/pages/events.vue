<script setup lang="ts">
import type { ReportRow } from '~/types'

const { t } = useI18n()
const { currentSite } = useSites()
useHead({ title: () => t('nav.events') })

const selectedEvent = ref<string | null>(null)
const propsOpen = computed({
  get: () => !!selectedEvent.value,
  set: (open: boolean) => {
    if (!open) selectedEvent.value = null
  }
})

function onSelect(row: ReportRow) {
  if (typeof row.name === 'string') selectedEvent.value = row.name
}
</script>

<template>
  <UDashboardPanel id="events">
    <template #header>
      <PageNavbar :title="t('nav.events')" />
      <ReportToolbar />
    </template>

    <template #body>
      <NoSiteNotice v-if="!currentSite" />
      <template v-else>
        <ReportTable
          report="events"
          :title="t('events.title')"
          :columns="EVENT_COLUMNS"
          selectable
          @select="onSelect"
        />
        <p class="text-xs text-muted px-1">
          {{ t('events.hint') }}
        </p>
      </template>

      <USlideover v-model:open="propsOpen" :title="t('events.propsTitle', { name: selectedEvent ?? '' })" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <ReportTable
            v-if="selectedEvent"
            :report="`events/${encodeURIComponent(selectedEvent)}/props`"
            :columns="EVENT_PROP_COLUMNS"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
