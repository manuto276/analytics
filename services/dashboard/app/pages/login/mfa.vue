<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const toast = useToast()
const { verifyMfa } = useAuth()
useHead({ title: () => t('auth.mfa.title') })

const useRecovery = ref(false)
const pin = ref<string[]>([])
const recovery = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const formRef = useTemplateRef<HTMLFormElement>('formRef')

const code = computed(() => (useRecovery.value ? recovery.value.trim() : pin.value.join('')))

/** Codes that are simply wrong keep the visitor on this page. */
const RETRYABLE_CODES = ['invalid_mfa_code', 'invalid_code', 'validation_failed']
/** The pending sign-in is gone, so the password step has to start over. */
const RESTART_CODES = ['mfa_not_pending', 'session_expired', 'unauthorized']

function focusInput() {
  void nextTick(() => {
    formRef.value?.querySelector('input')?.focus()
  })
}

function clearCode() {
  pin.value = []
  recovery.value = ''
  focusInput()
}

async function restart(message: string) {
  toast.add({ title: message, color: 'warning', icon: 'i-tabler-clock' })
  await navigateTo('/login')
}

async function onSubmit() {
  if (!code.value || loading.value) return
  loading.value = true
  error.value = null
  try {
    await verifyMfa(code.value)
  } catch (e) {
    if (!isApiError(e)) {
      error.value = (e as Error).message
      clearCode()
      return
    }
    if (e.status === 429 || e.code === 'account_locked' || e.code === 'rate_limited') {
      const retryAfter = e.problem?.retry_after
      error.value = retryAfter
        ? t('auth.mfa.retryAfter', { seconds: retryAfter })
        : t('auth.tooManyAttempts')
      clearCode()
    } else if (e.status === 422 || RETRYABLE_CODES.includes(e.code)) {
      error.value = t('auth.mfa.invalid')
      clearCode()
    } else if (e.status === 400 || e.status === 401 || RESTART_CODES.includes(e.code)) {
      await restart(t('auth.mfa.expired'))
    } else {
      error.value = e.message
      clearCode()
    }
  } finally {
    loading.value = false
  }
}

function toggleRecovery() {
  useRecovery.value = !useRecovery.value
  error.value = null
  clearCode()
}
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-highlighted">
        {{ t('auth.mfa.title') }}
      </h1>
      <p class="text-sm text-toned">
        {{ useRecovery ? t('auth.mfa.recoverySubtitle') : t('auth.mfa.subtitle') }}
      </p>
    </div>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-tabler-alert-circle"
      :title="error"
      data-testid="mfa-error"
      role="alert"
    />

    <form ref="formRef" class="space-y-4" @submit.prevent="onSubmit">
      <div v-if="!useRecovery" class="flex justify-center">
        <UPinInput
          v-model="pin"
          :length="6"
          otp
          autofocus
          data-testid="mfa-code"
          @complete="onSubmit"
        />
      </div>
      <UInput
        v-else
        v-model="recovery"
        class="w-full font-mono"
        autocomplete="one-time-code"
        data-testid="mfa-recovery"
        :aria-label="t('auth.mfa.recoveryCode')"
      />
      <UButton
        type="submit"
        :label="t('auth.mfa.submit')"
        block
        :loading="loading"
        data-testid="mfa-submit"
      />
    </form>

    <p class="text-center text-sm">
      <UButton
        variant="link"
        :label="useRecovery ? t('auth.mfa.useApp') : t('auth.mfa.useRecovery')"
        @click="toggleRecovery"
      />
    </p>
  </div>
</template>
