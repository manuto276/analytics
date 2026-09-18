<script setup lang="ts">
import type { ApiResponse } from '~/types'

const props = defineProps<{
  siteId: number
}>()

type Receipt = ApiResponse<'/sites/{siteId}/consent/receipts'>['data'][number]

const { t } = useI18n()
const api = useApi()
const fmt = useFormatters()

const VISITOR_ID = /^[A-Za-z0-9_-]{22}$/

const visitorId = ref('')
const receipts = ref<Receipt[] | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)

const valid = computed(() => VISITOR_ID.test(visitorId.value.trim()))

async function lookup() {
  if (!valid.value) {
    error.value = t('consent.receipts.invalidId')
    return
  }
  loading.value = true
  error.value = null
  receipts.value = null
  try {
    const res = await api<ApiResponse<'/sites/{siteId}/consent/receipts'>>(`/sites/${props.siteId}/consent/receipts`, {
      query: { visitor_id: visitorId.value.trim() },
      skipForbiddenToast: true
    })
    receipts.value = res.data
  } catch (e) {
    if (isApiError(e) && e.status === 409) error.value = t('consent.receipts.disabled')
    else if (isApiError(e) && e.status === 422) error.value = t('consent.receipts.invalidId')
    else if (isApiError(e) && e.status === 404) receipts.value = []
    else error.value = (e as Error).message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UPageCard
    variant="subtle"
    :title="t('consent.receipts.title')"
    :description="t('consent.receipts.description')"
    :ui="{ container: 'gap-y-3' }"
  >
    <UFormField :label="t('consent.receipts.visitorId')" :description="t('consent.receipts.visitorIdHint')">
      <div class="flex items-start gap-2">
        <UInput
          v-model="visitorId"
          class="w-full font-mono"
          maxlength="22"
          :placeholder="t('consent.receipts.visitorIdPlaceholder')"
          data-testid="receipt-visitor-id"
          @keydown.enter.prevent="lookup"
        />
        <UButton
          :label="t('consent.receipts.lookup')"
          color="neutral"
          :loading="loading"
          :disabled="!valid"
          data-testid="receipt-lookup"
          @click="lookup"
        />
      </div>
    </UFormField>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-tabler-alert-circle"
      :title="error"
      data-testid="receipt-error"
    />

    <div v-else-if="receipts" data-testid="receipt-results">
      <ul v-if="receipts.length" class="divide-y divide-default text-sm">
        <li v-for="(receipt, index) in receipts" :key="index" class="py-2 flex items-center justify-between gap-3">
          <span>{{ t('consent.receipts.accepted', { version: receipt.consent_version }) }}</span>
          <span class="text-toned">{{ fmt.dateTime(receipt.decided_at) }}</span>
        </li>
      </ul>
      <p v-else class="text-sm text-toned">
        {{ t('consent.receipts.empty') }}
      </p>
    </div>
  </UPageCard>
</template>
