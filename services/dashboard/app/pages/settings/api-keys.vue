<script setup lang="ts">
import type { ApiKey, ApiResponse } from '~/types'

type Scope = 'conversions:write' | 'stats:read'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('settings.apiKeys.title') })

const { data: keys, refresh } = useAsyncData<ApiKey[]>(
  () => `api-keys:${currentSiteId.value}`,
  async () => currentSiteId.value && canManage.value
    ? (await api<ApiResponse<'/sites/{siteId}/api-keys'>>(`/sites/${currentSiteId.value}/api-keys`)).data
    : [],
  { default: () => [] }
)

const scopeItems = computed(() => (['conversions:write', 'stats:read'] as const).map(value => ({ label: t(`settings.apiKeys.scopes.${value.replace(':', '_')}`), value })))

const open = ref(false)
const creating = ref(false)
const secret = ref<string | null>(null)
const state = reactive({ name: '', scopes: ['conversions:write'] as Scope[], expires_at: '' })

function openCreate() {
  state.name = ''
  state.scopes = ['conversions:write']
  state.expires_at = ''
  secret.value = null
  open.value = true
}

async function create() {
  if (!currentSiteId.value) return
  creating.value = true
  try {
    const res = await api<ApiResponse<'/sites/{siteId}/api-keys', 'post'>>(`/sites/${currentSiteId.value}/api-keys`, {
      method: 'POST',
      body: {
        name: state.name,
        scopes: state.scopes,
        expires_at: state.expires_at ? new Date(`${state.expires_at}T23:59:59Z`).toISOString() : null
      }
    })
    secret.value = res.data.secret
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    creating.value = false
  }
}

async function revoke(key: ApiKey) {
  try {
    await api(`/sites/${currentSiteId.value}/api-keys/${key.id}`, { method: 'DELETE' })
    toast.add({ title: t('settings.apiKeys.revoked'), color: 'success' })
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}
</script>

<template>
  <NoSiteNotice v-if="!currentSite" />
  <UAlert
    v-else-if="!canManage"
    color="neutral"
    variant="subtle"
    icon="i-tabler-lock"
    :title="t('errors.adminOnly')"
  />
  <div v-else>
    <UPageCard
      :title="t('settings.apiKeys.title')"
      :description="t('settings.apiKeys.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        :label="t('settings.apiKeys.create')"
        icon="i-tabler-plus"
        color="neutral"
        class="w-fit lg:ms-auto"
        @click="openCreate"
      />
    </UPageCard>

    <UPageCard variant="subtle" :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }">
      <ul role="list" class="divide-y divide-default">
        <li v-for="key in keys" :key="key.id" class="flex items-center justify-between gap-3 py-3 px-4 sm:px-6">
          <div class="min-w-0 text-sm">
            <p class="font-medium text-highlighted truncate">
              {{ key.name }}
              <span class="font-mono text-muted">{{ key.prefix }}…</span>
            </p>
            <p class="text-muted text-xs">
              {{ key.scopes.join(', ') }} ·
              {{ t('settings.apiKeys.lastUsed', { when: key.last_used_at ? fmt.dateTime(key.last_used_at) : t('jobs.never') }) }}
              <template v-if="key.expires_at">
                · {{ t('settings.apiKeys.expires', { when: fmt.dateTime(key.expires_at) }) }}
              </template>
            </p>
          </div>
          <UBadge
            v-if="key.revoked_at"
            color="neutral"
            variant="subtle"
            :label="t('settings.apiKeys.revokedBadge')"
          />
          <UButton
            v-else
            :label="t('settings.apiKeys.revoke')"
            color="error"
            variant="ghost"
            @click="revoke(key)"
          />
        </li>
        <li v-if="!keys.length" class="py-6 px-4 text-center text-sm text-muted">
          {{ t('settings.apiKeys.empty') }}
        </li>
      </ul>
    </UPageCard>

    <UModal v-model:open="open" :title="t('settings.apiKeys.create')">
      <template #body>
        <div v-if="secret" class="space-y-3">
          <UAlert
            color="warning"
            variant="subtle"
            icon="i-tabler-key"
            :title="t('settings.apiKeys.secretOnce')"
          />
          <CopyField :value="secret" :label="t('settings.apiKeys.secret')" />
          <div class="flex justify-end">
            <UButton :label="t('common.done')" @click="open = false" />
          </div>
        </div>
        <UForm
          v-else
          :state="state"
          class="space-y-4"
          @submit="create"
        >
          <UFormField :label="t('common.name')" name="name" required>
            <UInput v-model="state.name" class="w-full" />
          </UFormField>
          <UFormField :label="t('settings.apiKeys.scopesLabel')" name="scopes" required>
            <UCheckboxGroup v-model="state.scopes" :items="scopeItems" />
          </UFormField>
          <UFormField :label="t('settings.apiKeys.expiresAt')" name="expires_at">
            <UInput v-model="state.expires_at" type="date" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              :label="t('common.cancel')"
              color="neutral"
              variant="subtle"
              @click="open = false"
            />
            <UButton
              :label="t('common.create')"
              type="submit"
              :loading="creating"
              :disabled="!state.name || !state.scopes.length"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
