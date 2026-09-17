<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

const { t } = useI18n()
const toast = useToast()
const { isSiteModalOpen } = useDashboard()
const { createSite, selectSite } = useSites()

const schema = computed(() => z.object({
  name: z.string().min(1, t('validation.required')).max(120),
  domains: z.array(z.string().min(1)).min(1, t('validation.domainRequired')),
  timezone: z.string().min(1, t('validation.required'))
}))

const state = reactive({
  name: '',
  domains: [] as string[],
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
})

const timezones = timeZoneList()
const form = useTemplateRef('form')
const saving = ref(false)

async function onSubmit(event: FormSubmitEvent<{ name: string, domains: string[], timezone: string }>) {
  saving.value = true
  try {
    const site = await createSite({
      name: event.data.name,
      timezone: event.data.timezone,
      domains: event.data.domains.map(host => ({ host: host.trim().toLowerCase(), include_subdomains: false }))
    })
    toast.add({ title: t('sites.created'), color: 'success', icon: 'i-lucide-check' })
    isSiteModalOpen.value = false
    state.name = ''
    state.domains = []
    await selectSite(site.id)
    await navigateTo({ path: '/settings', query: { site: String(site.id) } })
  } catch (error) {
    if (isApiError(error) && error.isValidation) form.value?.setErrors(error.fieldErrors())
    else toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="isSiteModalOpen" :title="t('sites.add')" :description="t('sites.addDescription')">
    <template #body>
      <UForm
        ref="form"
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField :label="t('site.name')" name="name" required>
          <UInput v-model="state.name" class="w-full" autofocus />
        </UFormField>
        <UFormField
          :label="t('site.domains')"
          :description="t('site.domainsHint')"
          name="domains"
          required
        >
          <UInputTags v-model="state.domains" :placeholder="t('site.domainPlaceholder')" class="w-full" />
        </UFormField>
        <UFormField :label="t('site.timezone')" name="timezone" required>
          <USelectMenu v-model="state.timezone" :items="timezones" class="w-full" />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton
            :label="t('common.cancel')"
            color="neutral"
            variant="subtle"
            @click="isSiteModalOpen = false"
          />
          <UButton :label="t('common.create')" type="submit" :loading="saving" />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
