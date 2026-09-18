<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const api = useApi()
const route = useRoute()
useHead({ title: () => t('auth.reset.title') })

const state = reactive({ password: '', confirm: '' })
const loading = ref(false)
const done = ref(false)
const error = ref<string | null>(null)
const form = useTemplateRef('form')

function validate(s: typeof state) {
  const errors: { name: string, message: string }[] = []
  if (s.password && s.password.length < 12) errors.push({ name: 'password', message: t('validation.passwordLength') })
  if (s.confirm && s.confirm !== s.password) errors.push({ name: 'confirm', message: t('validation.passwordMatch') })
  return errors
}

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await api('/auth/password/reset', { method: 'POST', body: { token: String(route.params.token ?? ''), password: state.password }, skipAuthRedirect: true })
    done.value = true
  } catch (e) {
    if (isApiError(e) && e.status === 410) error.value = t('auth.reset.expired')
    else if (isApiError(e) && e.isValidation) form.value?.setErrors(e.fieldErrors())
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
        {{ t('auth.reset.title') }}
      </h1>
    </div>

    <UAlert
      v-if="done"
      color="success"
      variant="subtle"
      icon="i-tabler-check"
      :title="t('auth.reset.done')"
    />
    <template v-else>
      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        :title="error"
      />
      <UForm
        ref="form"
        :state="state"
        :validate="validate"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField :label="t('security.newPassword')" name="password" required>
          <UInput
            v-model="state.password"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField :label="t('security.confirmPassword')" name="confirm" required>
          <UInput
            v-model="state.confirm"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UButton
          type="submit"
          :label="t('auth.reset.submit')"
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
