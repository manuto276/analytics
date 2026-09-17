<script setup lang="ts">
import type { ApiResponse, CostImportResult } from '~/types'

const props = defineProps<{
  siteId: number
}>()

const emit = defineEmits<{
  imported: [result: CostImportResult]
}>()

const { t } = useI18n()
const api = useApi()
const fmt = useFormatters()

const CSV_HEADER = 'day_from,day_to,channel,utm_source,utm_medium,utm_campaign,amount,currency,note'

const csv = ref('')
const preview = ref<CostImportResult | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

async function onFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  csv.value = await file.text()
  preview.value = null
}

async function send(dryRun: boolean) {
  busy.value = true
  error.value = null
  try {
    const res = await api<ApiResponse<'/sites/{siteId}/costs/import', 'post'>>(`/sites/${props.siteId}/costs/import`, {
      method: 'POST',
      query: { dry_run: dryRun ? '1' : '0' },
      headers: { 'Content-Type': 'text/csv' },
      body: csv.value
    })
    if (dryRun) {
      preview.value = res.data
    } else {
      preview.value = null
      csv.value = ''
      emit('imported', res.data)
    }
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}

const canConfirm = computed(() => !!preview.value && preview.value.invalid_rows === 0 && preview.value.valid_rows > 0)
watch(csv, () => {
  preview.value = null
})
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-muted">
      {{ t('settings.costs.importHint') }}
    </p>
    <code class="block text-xs bg-elevated rounded p-2 overflow-x-auto">{{ CSV_HEADER }}</code>

    <input
      type="file"
      accept=".csv,text/csv"
      class="block text-sm"
      :aria-label="t('settings.costs.chooseFile')"
      @change="onFile"
    >
    <UTextarea
      v-model="csv"
      :rows="6"
      class="w-full font-mono text-xs"
      :placeholder="CSV_HEADER"
      :aria-label="t('settings.costs.csv')"
      data-testid="csv-input"
    />

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    />

    <div class="flex gap-2">
      <UButton
        :label="t('settings.costs.preview')"
        color="neutral"
        variant="subtle"
        :disabled="!csv.trim()"
        :loading="busy && !preview"
        data-testid="csv-preview"
        @click="send(true)"
      />
      <UButton
        :label="t('settings.costs.confirmImport')"
        :disabled="!canConfirm"
        :loading="busy && !!preview"
        data-testid="csv-confirm"
        @click="send(false)"
      />
    </div>

    <div v-if="preview" class="space-y-2" data-testid="csv-preview-result">
      <p class="text-sm">
        {{ t('settings.costs.previewSummary', { valid: preview.valid_rows, invalid: preview.invalid_rows }) }}
      </p>
      <div class="overflow-x-auto border border-default rounded">
        <table class="w-full text-xs">
          <thead class="bg-elevated/50">
            <tr>
              <th class="p-2 text-left">
                {{ t('settings.costs.line') }}
              </th>
              <th class="p-2 text-left">
                {{ t('settings.costs.days') }}
              </th>
              <th class="p-2 text-left">
                {{ t('settings.costs.target') }}
              </th>
              <th class="p-2 text-right">
                {{ t('settings.costs.amount') }}
              </th>
              <th class="p-2 text-left">
                {{ t('settings.costs.errors') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in preview.rows"
              :key="row.line"
              class="border-t border-default"
              :class="row.errors.length ? 'bg-error/10' : ''"
            >
              <td class="p-2">
                {{ row.line }}
              </td>
              <td class="p-2 whitespace-nowrap">
                {{ row.day_from }} – {{ row.day_to }}
              </td>
              <td class="p-2">
                {{ [row.channel, row.utm_source, row.utm_medium, row.utm_campaign].filter(Boolean).join(' / ') }}
              </td>
              <td class="p-2 text-right tabular-nums">
                {{ row.amount_minor === null || row.amount_minor === undefined ? '—' : fmt.money(row.amount_minor, row.currency) }}
              </td>
              <td class="p-2 text-error">
                {{ row.errors.join('; ') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
