<script setup lang="ts">
import type { ApiResponse } from '~/types'

definePageMeta({ layout: 'auth' })

const { t, locale } = useI18n()
const api = useApi()
const route = useRoute()
const { acceptInvitation } = useAuth()
const fmt = useFormatters()
useHead({ title: () => t('auth.invite.title') })

const token = computed(() => String(route.params.token ?? ''))

const { data: invitation, error: loadError } = useAsyncData(
  () => `invitation:${token.value}`,
  async () => (await api<ApiResponse<'/invitations/{token}'>>(`/invitations/${encodeURIComponent(token.value)}`, { skipAuthRedirect: true })).data
)

const state = reactive({ display_name: '', password: '', confirm: '', locale: (locale.value === 'it' ? 'it' : 'en') as 'en' | 'it' })
const loading = ref(false)
const error = ref<string | null>(null)
const form = useTemplateRef('form')
const localeItems = [{ label: 'English', value: 'en' }, { label: 'Italiano', value: 'it' }]

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
    await acceptInvitation(token.value, { display_name: state.display_name, password: state.password, locale: state.locale })
  } catch (e) {
    if (isApiError(e) && e.isValidation) form.value?.setErrors(e.fieldErrors())
    else error.value = (e as Error).message
  } finally {
    loading.value = false
  }
}

const loadMessage = computed(() => {
  const status = isApiError(loadError.value) ? loadError.value.status : (loadError.value as { statusCode?: number } | null)?.statusCode
  return status === 410 ? t('auth.invite.expired') : t('auth.invite.notFound')
})
</script>

<template>
  <div class="space-y-6">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-highlighted">
        {{ t('auth.invite.title') }}
      </h1>
    </div>

    <UAlert
      v-if="loadError"
      color="error"
      variant="subtle"
      icon="i-tabler-alert-circle"
      :title="loadMessage"
    />

    <template v-else-if="invitation">
      <div class="text-sm space-y-1">
        <p>{{ t('auth.invite.for', { email: invitation.email }) }}</p>
        <p class="text-muted">
          {{ t('auth.invite.role', { role: t(`roles.global.${invitation.global_role}`) }) }}
        </p>
        <ul v-if="invitation.sites.length" class="text-muted list-disc ps-5">
          <li v-for="site in invitation.sites" :key="site.name">
            {{ site.name }} · {{ t(`roles.site.${site.role}`) }}
          </li>
        </ul>
        <p class="text-xs text-muted">
          {{ t('auth.invite.expires', { when: fmt.dateTime(invitation.expires_at) }) }}
        </p>
      </div>

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
        <UFormField :label="t('common.name')" name="display_name" required>
          <UInput v-model="state.display_name" autocomplete="name" class="w-full" />
        </UFormField>
        <UFormField :label="t('common.password')" name="password" required>
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
        <UFormField :label="t('user.language')" name="locale">
          <USelect v-model="state.locale" :items="localeItems" class="w-full" />
        </UFormField>
        <UButton
          type="submit"
          :label="t('auth.invite.accept')"
          block
          :loading="loading"
        />
      </UForm>
    </template>
  </div>
</template>
