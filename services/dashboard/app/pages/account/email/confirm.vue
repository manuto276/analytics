<script setup lang="ts">
import type { ApiResponse } from '~/types'

definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const api = useApi()
const route = useRoute()
const { user, fetchMe } = useAuth()
useHead({ title: () => t('auth.emailConfirm.title') })

type Outcome = 'confirming' | 'done' | 'expired' | 'taken' | 'missing' | 'error'

const outcome = ref<Outcome>('confirming')
const newEmail = ref('')
const errorMessage = ref<string | null>(null)
const result = useTemplateRef<HTMLElement>('result')

const token = computed(() => {
  const value = route.query.token
  return typeof value === 'string' ? value.trim() : ''
})

const failureMessage = computed(() => {
  switch (outcome.value) {
    case 'expired': return t('auth.emailConfirm.expired')
    case 'taken': return t('auth.emailConfirm.taken')
    case 'missing': return t('auth.emailConfirm.missing')
    case 'error': return errorMessage.value
    default: return null
  }
})

function settle(value: Outcome) {
  outcome.value = value
  // Move focus to the outcome so screen readers announce it and keyboard users start there.
  void nextTick(() => result.value?.focus())
}

async function confirm() {
  if (!token.value) {
    settle('missing')
    return
  }
  try {
    const res = await api<ApiResponse<'/auth/email/confirm', 'post'>>('/auth/email/confirm', {
      method: 'POST',
      body: { token: token.value },
      skipAuthRedirect: true
    })
    newEmail.value = res.data.email
    // Signed in here as well? Refresh the user so the new address shows (or learn the session ended).
    if (user.value) await fetchMe().catch(() => undefined)
    settle('done')
  } catch (e) {
    if (isApiError(e) && e.status === 410) settle('expired')
    else if (isApiError(e) && e.status === 409) settle('taken')
    else if (isApiError(e) && e.status === 422) settle('missing')
    else {
      errorMessage.value = isApiError(e) && e.status === 429 ? t('auth.tooManyAttempts') : (e as Error).message
      settle('error')
    }
  }
}

onMounted(() => {
  void confirm()
})
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-highlighted">
        {{ t('auth.emailConfirm.title') }}
      </h1>
    </div>

    <p v-if="outcome === 'confirming'" class="text-center text-sm text-toned" role="status">
      {{ t('auth.emailConfirm.confirming') }}
    </p>
    <div
      v-else
      ref="result"
      tabindex="-1"
      class="outline-none"
    >
      <UAlert
        v-if="outcome === 'done'"
        color="success"
        variant="subtle"
        icon="i-tabler-check"
        role="status"
        :title="t('auth.emailConfirm.done', { email: newEmail })"
        :description="t('auth.emailConfirm.signedOut')"
        data-testid="email-confirm-done"
      />
      <UAlert
        v-else
        color="error"
        variant="subtle"
        icon="i-tabler-alert-circle"
        role="alert"
        :title="failureMessage ?? ''"
        data-testid="email-confirm-error"
      />
    </div>

    <p v-if="outcome !== 'confirming'" class="text-center text-sm">
      <ULink
        v-if="user"
        to="/settings/profile"
        class="text-primary"
        data-testid="email-confirm-link"
      >
        {{ t('auth.emailConfirm.toProfile') }}
      </ULink>
      <ULink
        v-else
        to="/login"
        class="text-primary"
        data-testid="email-confirm-link"
      >
        {{ t('auth.emailConfirm.signIn') }}
      </ULink>
    </p>
  </div>
</template>
