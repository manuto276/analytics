<script setup lang="ts">
import type { ApiResponse, Site, SiteInput } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const { currentSite, currentSiteId, canManage, updateSite, refreshSites } = useSites()
useHead({ title: () => t('settings.site.title') })

type DomainRow = { host: string, include_subdomains: boolean }

interface SiteForm {
  name: string
  timezone: string
  currency: string
  domains: DomainRow[]
  base_tracking_enabled: boolean
  visitor_hash_mode: 'daily_hash' | 'pageviews_only'
  cookie_level_enabled: boolean
  cookie_domain: string
  visitor_cookie_days: number
  new_visit_on_campaign_change: boolean
  dnt_mode: 'ignore' | 'no_cookie' | 'no_tracking'
  respect_gpc: boolean
  hash_routing: boolean
  allow_localhost: boolean
  allowed_query_params: string[]
  excluded_paths: string[]
  excluded_ip_prefixes: string[]
  content_contact_events: string[]
  auto_events: { outbound: boolean, downloads: boolean, forms: boolean }
}

function toForm(site: Site): SiteForm {
  return {
    name: site.name,
    timezone: site.timezone,
    currency: site.currency,
    domains: site.domains.map(d => ({ ...d })),
    base_tracking_enabled: site.base_tracking_enabled,
    visitor_hash_mode: site.visitor_hash_mode,
    cookie_level_enabled: site.cookie_level_enabled,
    cookie_domain: site.cookie_domain ?? '',
    visitor_cookie_days: site.visitor_cookie_days,
    new_visit_on_campaign_change: site.new_visit_on_campaign_change,
    dnt_mode: site.dnt_mode,
    respect_gpc: site.respect_gpc,
    hash_routing: site.hash_routing,
    allow_localhost: site.allow_localhost,
    allowed_query_params: [...site.allowed_query_params],
    excluded_paths: [...site.excluded_paths],
    excluded_ip_prefixes: [...site.excluded_ip_prefixes],
    content_contact_events: [...site.content_contact_events],
    auto_events: { outbound: !!site.auto_events.outbound, downloads: !!site.auto_events.downloads, forms: !!site.auto_events.forms }
  }
}

const state = ref<SiteForm | null>(null)
watch(currentSite, (site) => {
  state.value = site ? toForm(site) : null
}, { immediate: true })

const form = useTemplateRef('form')
const saving = ref(false)
const archiveOpen = ref(false)

const timezones = timeZoneList()
const hashModeItems = computed(() => (['daily_hash', 'pageviews_only'] as const).map(value => ({ label: t(`settings.site.hashModes.${value}`), value })))
const dntItems = computed(() => (['ignore', 'no_cookie', 'no_tracking'] as const).map(value => ({ label: t(`settings.site.dntModes.${value}`), value })))

const { data: snippet } = useAsyncData(
  () => `snippet:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/snippet'>>(`/sites/${currentSiteId.value}/snippet`)).data : null,
  { default: () => null }
)

function addDomain() {
  state.value?.domains.push({ host: '', include_subdomains: false })
}

function removeDomain(index: number) {
  state.value?.domains.splice(index, 1)
}

async function onSubmit() {
  if (!state.value || !currentSiteId.value) return
  saving.value = true
  try {
    const s = state.value
    const input: SiteInput = {
      ...s,
      domains: s.domains.filter(d => d.host.trim()).map(d => ({ host: d.host.trim().toLowerCase(), include_subdomains: d.include_subdomains })),
      cookie_domain: s.cookie_domain.trim() || null,
      currency: s.currency.toUpperCase()
    }
    const site = await updateSite(currentSiteId.value, input)
    toast.add({ title: t('common.saved'), color: 'success', icon: 'i-lucide-check' })
    if (site.timezone_changed) {
      toast.add({ title: t('settings.site.timezoneChanged'), description: t('settings.site.timezoneChangedHint'), color: 'warning', icon: 'i-lucide-clock', duration: 0 })
    }
  } catch (error) {
    if (isApiError(error) && error.isValidation) {
      form.value?.setErrors(error.fieldErrors())
      toast.add({ title: t('errors.validation'), color: 'error' })
    } else {
      toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
    }
  } finally {
    saving.value = false
  }
}

async function archive() {
  if (!currentSiteId.value) return
  try {
    await api(`/sites/${currentSiteId.value}`, { method: 'DELETE' })
    archiveOpen.value = false
    toast.add({ title: t('settings.site.archived'), color: 'success' })
    await refreshSites()
    await navigateTo('/')
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}
</script>

<template>
  <NoSiteNotice v-if="!currentSite || !state" />
  <UForm
    v-else
    id="site-settings"
    ref="form"
    :state="state"
    :disabled="!canManage"
    class="flex flex-col gap-4 sm:gap-6 lg:gap-12"
    @submit="onSubmit"
  >
    <div>
      <UPageCard
        :title="t('settings.site.title')"
        :description="t('settings.site.description')"
        variant="naked"
        orientation="horizontal"
        class="mb-4"
      >
        <UButton
          v-if="canManage"
          form="site-settings"
          :label="t('common.saveChanges')"
          color="neutral"
          type="submit"
          :loading="saving"
          class="w-fit lg:ms-auto"
          data-testid="save-site"
        />
      </UPageCard>

      <UPageCard variant="subtle">
        <UFormField
          name="name"
          :label="t('site.name')"
          required
          class="flex max-sm:flex-col justify-between items-start gap-4"
        >
          <UInput v-model="state.name" autocomplete="off" />
        </UFormField>
        <USeparator />
        <UFormField
          name="timezone"
          :label="t('site.timezone')"
          :description="t('settings.site.timezoneHint')"
          class="flex max-sm:flex-col justify-between items-start gap-4"
        >
          <USelectMenu v-model="state.timezone" :items="timezones" class="w-56" />
        </UFormField>
        <USeparator />
        <UFormField
          name="currency"
          :label="t('site.currency')"
          :description="t('settings.site.currencyHint')"
          class="flex max-sm:flex-col justify-between items-start gap-4"
        >
          <UInput v-model="state.currency" maxlength="3" class="w-24 uppercase" />
        </UFormField>
        <USeparator />
        <UFormField
          name="domains"
          :label="t('site.domains')"
          :description="t('site.domainsHint')"
          class="flex max-sm:flex-col justify-between items-start gap-4"
          :ui="{ container: 'w-full sm:max-w-sm' }"
        >
          <div class="space-y-2 w-full">
            <div v-for="(domain, index) in state.domains" :key="index" class="flex items-center gap-2">
              <UInput v-model="domain.host" :placeholder="t('site.domainPlaceholder')" class="flex-1" />
              <UCheckbox v-model="domain.include_subdomains" :label="t('settings.site.subdomains')" />
              <UButton
                v-if="canManage"
                icon="i-lucide-x"
                color="neutral"
                variant="ghost"
                :aria-label="t('common.remove')"
                @click="removeDomain(index)"
              />
            </div>
            <UButton
              v-if="canManage"
              :label="t('settings.site.addDomain')"
              icon="i-lucide-plus"
              size="xs"
              color="neutral"
              variant="subtle"
              @click="addDomain"
            />
          </div>
        </UFormField>
      </UPageCard>
    </div>

    <div>
      <UPageCard
        :title="t('settings.site.trackingTitle')"
        :description="t('settings.site.trackingDescription')"
        variant="naked"
        class="mb-4"
      />
      <UPageCard variant="subtle">
        <UFormField
          name="base_tracking_enabled"
          :label="t('settings.site.baseTracking')"
          :description="t('settings.site.baseTrackingHint')"
          class="flex items-center justify-between gap-4"
        >
          <USwitch v-model="state.base_tracking_enabled" />
        </UFormField>
        <USeparator />
        <UFormField
          name="visitor_hash_mode"
          :label="t('settings.site.hashMode')"
          :description="t('settings.site.hashModeHint')"
          class="flex max-sm:flex-col justify-between items-start gap-4"
        >
          <USelect v-model="state.visitor_hash_mode" :items="hashModeItems" class="w-56" />
        </UFormField>
        <UAlert
          v-if="state.visitor_hash_mode === 'daily_hash'"
          color="warning"
          variant="subtle"
          icon="i-lucide-scale"
          :title="t('settings.site.legalTitle')"
          :description="t('settings.site.legalNotice')"
        />
        <USeparator />
        <UFormField
          name="cookie_level_enabled"
          :label="t('settings.site.cookieLevel')"
          :description="t('settings.site.cookieLevelHint')"
          class="flex items-center justify-between gap-4"
        >
          <USwitch v-model="state.cookie_level_enabled" />
        </UFormField>
        <template v-if="state.cookie_level_enabled">
          <USeparator />
          <UFormField
            name="cookie_domain"
            :label="t('settings.site.cookieDomain')"
            :description="t('settings.site.cookieDomainHint')"
            class="flex max-sm:flex-col justify-between items-start gap-4"
          >
            <UInput v-model="state.cookie_domain" :placeholder="t('settings.site.cookieDomainPlaceholder')" />
          </UFormField>
          <USeparator />
          <UFormField
            name="visitor_cookie_days"
            :label="t('settings.site.cookieDays')"
            class="flex max-sm:flex-col justify-between items-start gap-4"
          >
            <UInputNumber
              v-model="state.visitor_cookie_days"
              :min="1"
              :max="395"
              class="w-32"
            />
          </UFormField>
          <USeparator />
          <UFormField
            name="new_visit_on_campaign_change"
            :label="t('settings.site.campaignSplit')"
            class="flex items-center justify-between gap-4"
          >
            <USwitch v-model="state.new_visit_on_campaign_change" />
          </UFormField>
        </template>
        <USeparator />
        <UFormField
          name="dnt_mode"
          :label="t('settings.site.dnt')"
          class="flex max-sm:flex-col justify-between items-start gap-4"
        >
          <USelect v-model="state.dnt_mode" :items="dntItems" class="w-56" />
        </UFormField>
        <USeparator />
        <UFormField name="respect_gpc" :label="t('settings.site.gpc')" class="flex items-center justify-between gap-4">
          <USwitch v-model="state.respect_gpc" />
        </UFormField>
        <USeparator />
        <UFormField name="hash_routing" :label="t('settings.site.hashRouting')" class="flex items-center justify-between gap-4">
          <USwitch v-model="state.hash_routing" />
        </UFormField>
        <USeparator />
        <UFormField name="allow_localhost" :label="t('settings.site.allowLocalhost')" class="flex items-center justify-between gap-4">
          <USwitch v-model="state.allow_localhost" />
        </UFormField>
        <USeparator />
        <UFormField
          :label="t('settings.site.autoEvents')"
          :description="t('settings.site.autoEventsHint')"
          class="flex max-sm:flex-col justify-between items-start gap-4"
          :ui="{ container: 'w-full sm:max-w-sm' }"
        >
          <div class="flex flex-col gap-3">
            <UCheckbox
              v-model="state.auto_events.outbound"
              :label="t('settings.site.outbound')"
              :description="t('settings.site.outboundHint')"
            />
            <UCheckbox
              v-model="state.auto_events.downloads"
              :label="t('settings.site.downloads')"
              :description="t('settings.site.downloadsHint')"
            />
            <UCheckbox
              v-model="state.auto_events.forms"
              :label="t('settings.site.forms')"
              :description="t('settings.site.formsHint')"
            />
          </div>
        </UFormField>
      </UPageCard>
    </div>

    <div>
      <UPageCard
        :title="t('settings.site.dataTitle')"
        :description="t('settings.site.dataDescription')"
        variant="naked"
        class="mb-4"
      />
      <UPageCard variant="subtle">
        <UFormField name="allowed_query_params" :label="t('settings.site.queryParams')" :description="t('settings.site.queryParamsHint')">
          <UInputTags v-model="state.allowed_query_params" class="w-full" />
        </UFormField>
        <USeparator />
        <UFormField name="excluded_paths" :label="t('settings.site.excludedPaths')" :description="t('settings.site.excludedPathsHint')">
          <UInputTags v-model="state.excluded_paths" class="w-full" />
        </UFormField>
        <USeparator />
        <UFormField name="excluded_ip_prefixes" :label="t('settings.site.excludedIps')" :description="t('settings.site.excludedIpsHint')">
          <UInputTags v-model="state.excluded_ip_prefixes" class="w-full" />
        </UFormField>
        <USeparator />
        <UFormField name="content_contact_events" :label="t('settings.site.contactEvents')" :description="t('settings.site.contactEventsHint')">
          <UInputTags v-model="state.content_contact_events" class="w-full" />
        </UFormField>
      </UPageCard>
    </div>

    <div v-if="snippet">
      <UPageCard
        :title="t('settings.site.snippetTitle')"
        :description="t('settings.site.snippetDescription')"
        variant="naked"
        class="mb-4"
      />
      <UPageCard variant="subtle">
        <UFormField :label="t('settings.site.snippet')">
          <CopyField :value="snippet.html" multiline :label="t('settings.site.snippet')" />
        </UFormField>
        <UFormField :label="t('settings.site.proxySnippet')" :description="t('settings.site.proxySnippetHint')">
          <CopyField :value="snippet.proxy_html" multiline :label="t('settings.site.proxySnippet')" />
        </UFormField>
        <UFormField :label="t('settings.site.publicKey')">
          <CopyField :value="snippet.public_key" :label="t('settings.site.publicKey')" />
        </UFormField>
      </UPageCard>
    </div>

    <UPageCard
      v-if="canManage"
      :title="t('settings.site.archiveTitle')"
      :description="t('settings.site.archiveDescription')"
      class="bg-linear-to-tl from-error/10 from-5% to-default"
    >
      <template #footer>
        <UModal v-model:open="archiveOpen" :title="t('settings.site.archiveTitle')" :description="t('settings.site.archiveConfirm')">
          <UButton :label="t('settings.site.archive')" color="error" :disabled="false" />
          <template #footer>
            <div class="flex justify-end gap-2 w-full">
              <UButton
                :label="t('common.cancel')"
                color="neutral"
                variant="subtle"
                @click="archiveOpen = false"
              />
              <UButton :label="t('settings.site.archive')" color="error" @click="archive" />
            </div>
          </template>
        </UModal>
      </template>
    </UPageCard>
  </UForm>
</template>
