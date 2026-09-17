<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const api = useApi()
const { config, loadConfig } = useAuth()
useHead({ title: () => t('auth.forgot.title') })

await loadConfig()

const email = ref('')
const sent = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await api('/auth/password/forgot', { method: 'POST', body: { email: email.value }, skipAuthRedirect: true })
    sent.value = true
  } catch (e) {
    error.value = isApiError(e) && e.status === 429 ? t('auth.tooManyAttempts') : (e as Error).message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-highlighted">
        {{ t('auth.forgot.title') }}
      </h1>
    </div>

    <UAlert
      v-if="!config?.mailer_enabled"
      color="neutral"
      variant="subtle"
      icon="i-lucide-mail-x"
      :title="t('auth.forgot.unavailable')"
      :description="t('auth.forgot.askAdmin')"
    />
    <UAlert
      v-else-if="sent"
      color="success"
      variant="subtle"
      icon="i-lucide-mail-check"
      :title="t('auth.forgot.sent')"
    />
    <template v-else>
      <p class="text-sm text-muted">
        {{ t('auth.forgot.subtitle') }}
      </p>
      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        :title="error"
      />
      <UForm :state="{ email }" class="space-y-4" @submit="onSubmit">
        <UFormField :label="t('common.email')" name="email" required>
          <UInput
            v-model="email"
            type="email"
            autocomplete="username"
            class="w-full"
            autofocus
          />
        </UFormField>
        <UButton
          type="submit"
          :label="t('auth.forgot.submit')"
          block
          :loading="loading"
        />
      </UForm>
    </template>

    <p class="text-center text-sm">
      <ULink to="/login" class="text-primary">
        {{ t('auth.backToLogin') }}
      </ULink>
    </p>
  </div>
</template>
