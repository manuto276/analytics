<script setup lang="ts">
import type { ApiResponse, SessionInfo } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
useHead({ title: () => t('security.title') })

const passwordState = reactive({ current_password: '', new_password: '', confirm: '' })
const changing = ref(false)
const form = useTemplateRef('form')

function validate(state: typeof passwordState) {
  const errors: { name: string, message: string }[] = []
  if (state.new_password && state.new_password.length < 12) errors.push({ name: 'new_password', message: t('validation.passwordLength') })
  if (state.confirm && state.confirm !== state.new_password) errors.push({ name: 'confirm', message: t('validation.passwordMatch') })
  if (state.current_password && state.current_password === state.new_password) errors.push({ name: 'new_password', message: t('validation.passwordDifferent') })
  return errors
}

async function changePassword() {
  changing.value = true
  try {
    await api('/auth/password', { method: 'POST', body: { current_password: passwordState.current_password, new_password: passwordState.new_password } })
    Object.assign(passwordState, { current_password: '', new_password: '', confirm: '' })
    toast.add({ title: t('security.passwordChanged'), color: 'success' })
    await refreshSessions()
  } catch (error) {
    if (isApiError(error) && error.isValidation) form.value?.setErrors(error.fieldErrors())
    else toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    changing.value = false
  }
}

const { data: sessions, refresh: refreshSessions } = useAsyncData<SessionInfo[]>(
  'auth:sessions',
  async () => (await api<ApiResponse<'/auth/sessions'>>('/auth/sessions')).data,
  { default: () => [] }
)

async function revoke(session: SessionInfo) {
  try {
    await api(`/auth/sessions/${session.id}`, { method: 'DELETE' })
    await refreshSessions()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

async function revokeOthers() {
  try {
    const res = await api<ApiResponse<'/auth/sessions', 'delete'>>('/auth/sessions', { method: 'DELETE' })
    toast.add({ title: t('security.sessions.revokedOthers', { n: res.data.revoked }), color: 'success' })
    await refreshSessions()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}
</script>

<template>
  <UPageCard :title="t('security.password')" :description="t('security.passwordDescription')" variant="subtle">
    <UForm
      ref="form"
      :state="passwordState"
      :validate="validate"
      class="flex flex-col gap-4 max-w-xs"
      @submit="changePassword"
    >
      <UFormField name="current_password" :label="t('security.currentPassword')">
        <UInput
          v-model="passwordState.current_password"
          type="password"
          autocomplete="current-password"
          class="w-full"
        />
      </UFormField>
      <UFormField name="new_password" :label="t('security.newPassword')">
        <UInput
          v-model="passwordState.new_password"
          type="password"
          autocomplete="new-password"
          class="w-full"
        />
      </UFormField>
      <UFormField name="confirm" :label="t('security.confirmPassword')">
        <UInput
          v-model="passwordState.confirm"
          type="password"
          autocomplete="new-password"
          class="w-full"
        />
      </UFormField>
      <UButton
        :label="t('common.update')"
        class="w-fit"
        type="submit"
        :loading="changing"
      />
    </UForm>
  </UPageCard>

  <SecurityTotpSetup />

  <UPageCard :title="t('security.sessions.title')" :description="t('security.sessions.description')" variant="subtle">
    <ul class="divide-y divide-default">
      <li v-for="session in sessions" :key="session.id" class="py-2 flex items-center justify-between gap-3 text-sm">
        <div class="min-w-0">
          <p class="text-highlighted truncate">
            {{ session.ua_summary ?? t('security.sessions.unknownDevice') }}
            <UBadge
              v-if="session.current"
              color="primary"
              variant="subtle"
              size="sm"
              :label="t('security.sessions.current')"
            />
          </p>
          <p class="text-xs text-muted">
            {{ session.ip_prefix ?? '' }} · {{ t('security.sessions.lastSeen', { when: fmt.dateTime(session.last_seen_at) }) }}
          </p>
        </div>
        <UButton
          v-if="!session.current"
          :label="t('security.sessions.revoke')"
          color="error"
          variant="ghost"
          size="xs"
          @click="revoke(session)"
        />
      </li>
    </ul>
    <template #footer>
      <UButton
        :label="t('security.sessions.revokeOthers')"
        color="neutral"
        variant="subtle"
        :disabled="sessions.length <= 1"
        @click="revokeOthers"
      />
    </template>
  </UPageCard>
</template>
