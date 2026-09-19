<script setup lang="ts">
import type { ApiResponse } from '~/types'

const { t, locales, setLocale } = useI18n()
const api = useApi()
const toast = useToast()
const route = useRoute()
const { user, config, loadConfig, updateMe } = useAuth()
useHead({ title: () => t('profile.title') })

await loadConfig()

// Details: display name and language (PATCH /auth/me).

const details = reactive({
  display_name: user.value?.display_name ?? '',
  locale: (user.value?.locale ?? 'en') as 'en' | 'it'
})
const detailsForm = useTemplateRef('detailsForm')
const savingDetails = ref(false)
const localeItems = computed(() => locales.value.map(l => ({ label: l.name ?? l.code, value: l.code as 'en' | 'it' })))

function validateDetails(s: typeof details) {
  return s.display_name.trim() ? [] : [{ name: 'display_name', message: t('validation.required') }]
}

async function saveDetails() {
  savingDetails.value = true
  try {
    const updated = await updateMe({ display_name: details.display_name.trim(), locale: details.locale })
    details.display_name = updated.display_name
    await setLocale(details.locale)
    toast.add({ title: t('profile.saved'), color: 'success', icon: 'i-tabler-check' })
  } catch (error) {
    if (isApiError(error) && error.isValidation) detailsForm.value?.setErrors(error.fieldErrors())
    else toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    savingDetails.value = false
  }
}

// Email change (POST/DELETE /auth/email). Needs a mailer, like the forgot-password link.

const mailerDisabled = ref(false)
const canChangeEmail = computed(() => !!config.value?.mailer_enabled && !mailerDisabled.value)
const pendingEmail = computed(() => user.value?.pending_email ?? null)

const emailState = reactive({ email: '', current_password: '' })
const emailForm = useTemplateRef('emailForm')
const emailFormEl = useTemplateRef<HTMLElement>('emailFormEl')
const pendingNotice = useTemplateRef<HTMLElement>('pendingNotice')
const emailError = ref<string | null>(null)
const requesting = ref(false)
const cancelling = ref(false)

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validateEmail(s: typeof emailState) {
  const errors: { name: string, message: string }[] = []
  if (!s.email.trim()) errors.push({ name: 'email', message: t('validation.required') })
  else if (!EMAIL_PATTERN.test(s.email.trim())) errors.push({ name: 'email', message: t('validation.email') })
  if (!s.current_password) errors.push({ name: 'current_password', message: t('validation.required') })
  return errors
}

// The form keeps its inputs enabled while submitting (loading-auto off), so focus can move right away.
function focusField(name: string) {
  void nextTick(() => {
    emailFormEl.value?.querySelector<HTMLInputElement>(`input[name="${name}"]`)?.focus()
  })
}

function focusPending() {
  void nextTick(() => pendingNotice.value?.focus())
}

async function requestEmailChange() {
  requesting.value = true
  emailError.value = null
  try {
    const res = await api<ApiResponse<'/auth/email', 'post'>>('/auth/email', {
      method: 'POST',
      body: { email: emailState.email.trim(), current_password: emailState.current_password }
    })
    if (user.value) user.value = { ...user.value, pending_email: res.data.pending_email }
    Object.assign(emailState, { email: '', current_password: '' })
    emailForm.value?.clear()
    toast.add({ title: t('profile.requested', { email: res.data.pending_email }), color: 'success', icon: 'i-tabler-mail' })
    focusPending()
  } catch (error) {
    if (!isApiError(error)) {
      emailError.value = (error as Error).message
      return
    }
    if (error.status === 501 || error.code === 'mailer_disabled') {
      mailerDisabled.value = true
    } else if (error.status === 409) {
      emailForm.value?.setErrors([{ name: 'email', message: t('profile.emailTaken') }])
      focusField('email')
    } else if (error.isValidation) {
      const fields = error.fieldErrors().map(e => e.name === 'current_password' ? { ...e, message: t('profile.wrongPassword') } : e)
      emailForm.value?.setErrors(fields)
      if (fields.some(e => e.name === 'current_password')) emailState.current_password = ''
      const first = fields.find(e => e.name === 'email' || e.name === 'current_password')
      if (first) focusField(first.name)
      else emailError.value = error.message
    } else if (error.status === 429) {
      const retryAfter = error.problem?.retry_after
      emailError.value = retryAfter ? t('profile.retryAfter', { seconds: retryAfter }) : t('auth.tooManyAttempts')
    } else {
      emailError.value = error.message
    }
  } finally {
    requesting.value = false
  }
}

async function cancelEmailChange() {
  cancelling.value = true
  try {
    await api('/auth/email', { method: 'DELETE' })
    if (user.value) user.value = { ...user.value, pending_email: null }
    toast.add({ title: t('profile.cancelled'), color: 'success' })
    focusField('email')
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <UPageCard :title="t('profile.details')" :description="t('profile.detailsDescription')" variant="subtle">
    <UForm
      ref="detailsForm"
      :state="details"
      :validate="validateDetails"
      class="flex flex-col gap-4 max-w-sm"
      data-testid="profile-details"
      @submit="saveDetails"
    >
      <UFormField name="display_name" :label="t('profile.displayName')" required>
        <UInput
          v-model="details.display_name"
          name="display_name"
          autocomplete="name"
          class="w-full"
          data-testid="profile-display-name"
        />
      </UFormField>
      <UFormField name="locale" :label="t('profile.language')">
        <USelect
          v-model="details.locale"
          :items="localeItems"
          class="w-56"
          data-testid="profile-locale"
        />
      </UFormField>
      <UButton
        :label="t('common.saveChanges')"
        class="w-fit"
        type="submit"
        :loading="savingDetails"
        data-testid="profile-save"
      />
    </UForm>
  </UPageCard>

  <UPageCard :title="t('profile.email')" :description="t('profile.emailDescription')" variant="subtle">
    <dl class="text-sm">
      <dt class="text-muted">
        {{ t('profile.currentEmail') }}
      </dt>
      <dd class="text-highlighted font-medium" data-testid="profile-email">
        {{ user?.email }}
      </dd>
    </dl>

    <div
      v-if="pendingEmail"
      ref="pendingNotice"
      tabindex="-1"
      role="status"
      class="outline-none"
      data-testid="pending-email"
    >
      <UAlert
        color="info"
        variant="subtle"
        icon="i-tabler-mail-forward"
        :title="t('profile.pendingTitle')"
        :description="t('profile.pending', { email: pendingEmail })"
        :actions="[{ label: t('profile.cancelPending'), color: 'neutral', variant: 'subtle', loading: cancelling, onClick: cancelEmailChange }]"
      />
    </div>

    <UAlert
      v-if="!canChangeEmail"
      color="neutral"
      variant="subtle"
      icon="i-tabler-mail-off"
      :description="t('profile.mailerDisabled')"
      data-testid="mailer-disabled"
    />
    <div v-else ref="emailFormEl">
      <UAlert
        v-if="emailError"
        color="error"
        variant="subtle"
        icon="i-tabler-alert-circle"
        :title="emailError"
        role="alert"
        class="mb-4"
        data-testid="email-error"
      />
      <UForm
        ref="emailForm"
        :state="emailState"
        :validate="validateEmail"
        :loading-auto="false"
        class="flex flex-col gap-4 max-w-sm"
        data-testid="email-form"
        @submit="requestEmailChange"
      >
        <p class="text-sm text-muted">
          {{ t('profile.changeEmailHint') }}
        </p>
        <UFormField name="email" :label="t('profile.newEmail')" required>
          <UInput
            v-model="emailState.email"
            name="email"
            type="email"
            autocomplete="email"
            class="w-full"
            data-testid="new-email"
          />
        </UFormField>
        <UFormField name="current_password" :label="t('security.currentPassword')" required>
          <UInput
            v-model="emailState.current_password"
            name="current_password"
            type="password"
            autocomplete="current-password"
            class="w-full"
            data-testid="email-password"
          />
        </UFormField>
        <UButton
          :label="t('profile.changeEmail')"
          class="w-fit"
          type="submit"
          :loading="requesting"
          data-testid="email-submit"
        />
      </UForm>
    </div>
  </UPageCard>

  <UPageCard :title="t('profile.securityTitle')" :description="t('profile.securityDescription')" variant="subtle">
    <UButton
      :label="t('profile.securityLink')"
      :to="{ path: '/settings/security', query: { site: route.query.site } }"
      icon="i-tabler-shield"
      color="neutral"
      variant="subtle"
      class="w-fit"
      data-testid="security-link"
    />
  </UPageCard>
</template>
