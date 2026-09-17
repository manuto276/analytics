<script setup lang="ts">
import type { ApiResponse } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const { user, fetchMe } = useAuth()

type SetupData = ApiResponse<'/auth/totp/setup', 'post'>['data']

const setup = ref<SetupData | null>(null)
const code = ref<string[]>([])
const recoveryCodes = ref<string[] | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const disableOpen = ref(false)
const password = ref('')

const enabled = computed(() => !!user.value?.mfa_enabled)
const qrSrc = computed(() => setup.value ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.value.qr_svg)}` : '')

async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
  busy.value = true
  error.value = null
  try {
    return await fn()
  } catch (e) {
    error.value = (e as Error).message
    return undefined
  } finally {
    busy.value = false
  }
}

async function start() {
  recoveryCodes.value = null
  await run(async () => {
    const res = await api<ApiResponse<'/auth/totp/setup', 'post'>>('/auth/totp/setup', { method: 'POST' })
    setup.value = res.data
    code.value = []
  })
}

async function confirm() {
  await run(async () => {
    const res = await api<ApiResponse<'/auth/totp/confirm', 'post'>>('/auth/totp/confirm', { method: 'POST', body: { code: code.value.join('') } })
    recoveryCodes.value = res.data.recovery_codes
    setup.value = null
    await fetchMe()
    toast.add({ title: t('security.totp.enabled'), color: 'success' })
  })
}

async function regenerate() {
  await run(async () => {
    const res = await api<ApiResponse<'/auth/totp/recovery-codes', 'post'>>('/auth/totp/recovery-codes', { method: 'POST' })
    recoveryCodes.value = res.data.recovery_codes
  })
}

async function disable() {
  await run(async () => {
    await api('/auth/totp', { method: 'DELETE', body: { password: password.value } })
    disableOpen.value = false
    password.value = ''
    recoveryCodes.value = null
    await fetchMe()
    toast.add({ title: t('security.totp.disabled'), color: 'success' })
  })
}
</script>

<template>
  <UPageCard :title="t('security.totp.title')" :description="t('security.totp.description')" variant="subtle">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
      data-testid="totp-error"
    />

    <div v-if="recoveryCodes" class="space-y-3" data-testid="recovery-codes">
      <UAlert
        color="warning"
        variant="subtle"
        icon="i-lucide-life-buoy"
        :title="t('security.totp.recoveryTitle')"
        :description="t('security.totp.recoveryHint')"
      />
      <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
        <li v-for="rc in recoveryCodes" :key="rc" class="bg-elevated rounded px-2 py-1 text-center">
          {{ rc }}
        </li>
      </ul>
      <CopyField :value="recoveryCodes.join('\n')" multiline :label="t('security.totp.recoveryTitle')" />
    </div>

    <div v-if="setup" class="space-y-4" data-testid="totp-setup">
      <p class="text-sm">
        {{ t('security.totp.scan') }}
      </p>
      <img :src="qrSrc" :alt="t('security.totp.qrAlt')" class="size-48 bg-white p-2 rounded">
      <UFormField :label="t('security.totp.secret')">
        <CopyField :value="setup.secret" :label="t('security.totp.secret')" />
      </UFormField>
      <UFormField :label="t('security.totp.code')">
        <UPinInput
          v-model="code"
          :length="6"
          otp
          data-testid="totp-code"
        />
      </UFormField>
      <div class="flex gap-2">
        <UButton
          :label="t('common.cancel')"
          color="neutral"
          variant="subtle"
          @click="setup = null"
        />
        <UButton
          :label="t('security.totp.confirm')"
          :loading="busy"
          :disabled="code.join('').length !== 6"
          data-testid="totp-confirm"
          @click="confirm"
        />
      </div>
    </div>

    <div v-else-if="!enabled" class="flex">
      <UButton
        :label="t('security.totp.enable')"
        icon="i-lucide-smartphone"
        :loading="busy"
        data-testid="totp-start"
        @click="start"
      />
    </div>

    <div v-else class="flex flex-wrap items-center gap-2">
      <UBadge
        color="success"
        variant="subtle"
        icon="i-lucide-shield-check"
        :label="t('security.totp.active')"
      />
      <UButton
        :label="t('security.totp.regenerate')"
        color="neutral"
        variant="subtle"
        :loading="busy"
        @click="regenerate"
      />
      <UButton
        :label="t('security.totp.disable')"
        color="error"
        variant="ghost"
        @click="disableOpen = true"
      />
    </div>

    <UModal v-model:open="disableOpen" :title="t('security.totp.disable')" :description="t('security.totp.disableHint')">
      <template #body>
        <UForm :state="{ password }" class="space-y-4" @submit="disable">
          <UFormField :label="t('common.password')" name="password" required>
            <UInput
              v-model="password"
              type="password"
              autocomplete="current-password"
              class="w-full"
            />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              :label="t('common.cancel')"
              color="neutral"
              variant="subtle"
              @click="disableOpen = false"
            />
            <UButton
              :label="t('security.totp.disable')"
              color="error"
              type="submit"
              :loading="busy"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </UPageCard>
</template>
