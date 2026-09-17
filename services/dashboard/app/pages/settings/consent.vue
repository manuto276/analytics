<script setup lang="ts">
import type { ApiResponse, ConsentConfig, ConsentConfigInput } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('consent.title') })

type ConsentState = ApiResponse<'/sites/{siteId}/consent'>['data']

const { data: consent, refresh } = useAsyncData<ConsentState | null>(
  () => `consent:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/consent'>>(`/sites/${currentSiteId.value}/consent`)).data : null,
  { default: () => null }
)

const { data: history, refresh: refreshHistory } = useAsyncData<ConsentConfig[]>(
  () => `consent-history:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/consent/history'>>(`/sites/${currentSiteId.value}/consent/history`)).data : [],
  { default: () => [] }
)

function toInput(config: ConsentConfigInput): ConsentConfigInput {
  return {
    texts: JSON.parse(JSON.stringify(config.texts)),
    policy_urls: { ...config.policy_urls },
    default_locale: config.default_locale,
    theme: { ...config.theme },
    accepted_ttl_days: config.accepted_ttl_days,
    rejected_ttl_days: config.rejected_ttl_days,
    show_floating_reopen: config.show_floating_reopen
  }
}

const editing = ref<ConsentConfigInput | null>(null)
const locale = ref('en')
watch(consent, (value) => {
  if (!value) {
    editing.value = null
    return
  }
  editing.value = toInput(value.draft ?? value.published ?? value.defaults)
}, { immediate: true })

const contrastOk = computed(() => !editing.value || themeContrast(editing.value.theme).ok)
const saving = ref(false)
const publishing = ref(false)
const publishOpen = ref(false)

async function saveDraft(): Promise<boolean> {
  if (!editing.value || !currentSiteId.value) return false
  if (!contrastOk.value) {
    toast.add({ title: t('consent.contrastFail'), color: 'error' })
    return false
  }
  saving.value = true
  try {
    await api(`/sites/${currentSiteId.value}/consent/draft`, { method: 'PUT', body: editing.value })
    toast.add({ title: t('consent.draftSaved'), color: 'success' })
    await refresh()
    return true
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
    return false
  } finally {
    saving.value = false
  }
}

async function discardDraft() {
  if (!currentSiteId.value) return
  try {
    await api(`/sites/${currentSiteId.value}/consent/draft`, { method: 'DELETE' })
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

async function publish(materialChange: boolean) {
  if (!currentSiteId.value) return
  publishing.value = true
  try {
    if (!(await saveDraft())) return
    await api(`/sites/${currentSiteId.value}/consent/publish`, { method: 'POST', body: { material_change: materialChange } })
    publishOpen.value = false
    toast.add({ title: t('consent.published'), color: 'success' })
    await Promise.all([refresh(), refreshHistory()])
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    publishing.value = false
  }
}
</script>

<template>
  <NoSiteNotice v-if="!currentSite" />
  <div v-else-if="editing" class="space-y-6">
    <UPageCard
      :title="t('consent.title')"
      :description="t('consent.description')"
      variant="naked"
      orientation="horizontal"
    >
      <div v-if="canManage" class="flex flex-wrap gap-2 lg:ms-auto">
        <UButton
          v-if="consent?.draft"
          :label="t('consent.discard')"
          color="neutral"
          variant="ghost"
          @click="discardDraft"
        />
        <UButton
          :label="t('consent.saveDraft')"
          color="neutral"
          variant="subtle"
          :loading="saving"
          :disabled="!contrastOk"
          @click="saveDraft"
        />
        <UButton
          :label="t('consent.publish')"
          :disabled="!contrastOk"
          data-testid="open-publish"
          @click="publishOpen = true"
        />
      </div>
    </UPageCard>

    <div class="flex flex-wrap gap-2 text-sm">
      <UBadge v-if="consent?.published" color="success" variant="subtle">
        {{ t('consent.publishedVersion', { n: consent.published.consent_version, revision: consent.published.revision }) }}
      </UBadge>
      <UBadge v-else color="neutral" variant="subtle">
        {{ t('consent.notPublished') }}
      </UBadge>
      <UBadge v-if="consent?.draft" color="warning" variant="subtle">
        {{ t('consent.hasDraft') }}
      </UBadge>
    </div>

    <UAlert
      v-for="warning in consent?.draft?.contrast_warnings ?? []"
      :key="warning"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="warning"
    />

    <div class="grid lg:grid-cols-2 gap-6 items-start">
      <ConsentEditor v-model="editing" v-model:locale="locale" :readonly="!canManage" />
      <div class="space-y-6 lg:sticky lg:top-4">
        <div>
          <p class="text-sm font-medium text-highlighted mb-2">
            {{ t('consent.preview') }}
          </p>
          <ConsentBannerPreview :config="editing" :locale="locale" />
        </div>

        <UPageCard variant="subtle" :title="t('consent.history')" :ui="{ container: 'gap-y-2' }">
          <ul class="divide-y divide-default text-sm">
            <li v-for="rev in history" :key="rev.id" class="py-2 flex items-center justify-between gap-2">
              <span>{{ t('consent.revision', { revision: rev.revision, version: rev.consent_version }) }}</span>
              <span class="flex items-center gap-2">
                <span class="text-muted">{{ fmt.dateTime(rev.published_at ?? rev.created_at) }}</span>
                <UBadge :color="rev.status === 'published' ? 'success' : 'neutral'" variant="subtle" :label="t(`consent.status.${rev.status}`)" />
              </span>
            </li>
            <li v-if="!history.length" class="py-2 text-muted">
              {{ t('consent.noHistory') }}
            </li>
          </ul>
        </UPageCard>
      </div>
    </div>

    <ConsentPublishModal
      v-model:open="publishOpen"
      :current-version="consent?.published?.consent_version ?? null"
      :loading="publishing"
      @publish="publish"
    />
  </div>
</template>
