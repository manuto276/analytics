<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const { login, config } = useAuth()
useHead({ title: () => t('auth.login.title') })

const state = reactive({ email: '', password: '' })
const loading = ref(false)
const error = ref<string | null>(null)

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await login({ email: state.email, password: state.password })
  } catch (e) {
    if (isApiError(e) && e.status === 429) error.value = t('auth.tooManyAttempts')
    else if (isApiError(e) && (e.status === 401 || e.status === 422)) error.value = t('auth.login.invalid')
    else error.value = (e as Error).message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-highlighted">
        {{ t('auth.login.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('auth.login.subtitle') }}
      </p>
    </div>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-tabler-alert-circle"
      :title="error"
    />

    <UForm :state="state" class="space-y-4" @submit="onSubmit">
      <UFormField :label="t('common.email')" name="email" required>
        <UInput
          v-model="state.email"
          type="email"
          autocomplete="username"
          class="w-full"
          autofocus
        />
      </UFormField>
      <UFormField :label="t('common.password')" name="password" required>
        <UInput
          v-model="state.password"
          type="password"
          autocomplete="current-password"
          class="w-full"
        />
      </UFormField>
      <UButton
        type="submit"
        :label="t('auth.login.submit')"
        block
        :loading="loading"
      />
    </UForm>

    <p v-if="config?.mailer_enabled" class="text-center text-sm">
      <ULink to="/password/forgot" class="text-primary">
        {{ t('auth.login.forgot') }}
      </ULink>
    </p>
  </div>
</template>
