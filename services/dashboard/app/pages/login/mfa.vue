<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const { verifyMfa } = useAuth()
useHead({ title: () => t('auth.mfa.title') })

const useRecovery = ref(false)
const pin = ref<string[]>([])
const recovery = ref('')
const loading = ref(false)
const error = ref<string | null>(null)

const code = computed(() => (useRecovery.value ? recovery.value.trim() : pin.value.join('')))

async function onSubmit() {
  if (!code.value) return
  loading.value = true
  error.value = null
  try {
    await verifyMfa(code.value)
  } catch (e) {
    if (isApiError(e) && (e.status === 400 || e.status === 401)) {
      error.value = t('auth.mfa.expired')
      await navigateTo('/login')
    } else if (isApiError(e) && e.status === 429) {
      error.value = t('auth.tooManyAttempts')
    } else {
      error.value = t('auth.mfa.invalid')
    }
    pin.value = []
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-highlighted">
        {{ t('auth.mfa.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ useRecovery ? t('auth.mfa.recoverySubtitle') : t('auth.mfa.subtitle') }}
      </p>
    </div>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      :title="error"
    />

    <form class="space-y-4" @submit.prevent="onSubmit">
      <div v-if="!useRecovery" class="flex justify-center">
        <UPinInput
          v-model="pin"
          :length="6"
          otp
          autofocus
          @complete="onSubmit"
        />
      </div>
      <UInput
        v-else
        v-model="recovery"
        class="w-full font-mono"
        autocomplete="one-time-code"
        :aria-label="t('auth.mfa.recoveryCode')"
      />
      <UButton
        type="submit"
        :label="t('auth.mfa.submit')"
        block
        :loading="loading"
      />
    </form>

    <p class="text-center text-sm">
      <UButton
        variant="link"
        :label="useRecovery ? t('auth.mfa.useApp') : t('auth.mfa.useRecovery')"
        @click="useRecovery = !useRecovery"
      />
    </p>
  </div>
</template>
