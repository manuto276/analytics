<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

const { t } = useI18n()
const toast = useToast()
const { isSiteModalOpen } = useDashboard()
const { createSite, selectSite } = useSites()

const schema = computed(() => z.object({
  name: z.string().min(1, t('validation.required')).max(120),
  domains: z.array(z.string()).min(1, t('validation.domainRequired')).superRefine((list, ctx) => {
    const invalid = list.filter(domain => !parseDomain(domain))
    if (invalid.length) ctx.addIssue({ code: 'custom', message: t('validation.domainInvalid', { hosts: invalid.join(', ') }) })
  }),
  timezone: z.string().min(1, t('validation.required'))
}))

const state = reactive({
  name: '',
  domains: [] as string[],
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
})

const timezones = timeZoneList()
const form = useTemplateRef('form')
const tagsInput = useTemplateRef<{ inputRef: HTMLInputElement | undefined }>('tagsInput')
const saving = ref(false)
/** Entries the API rejected, shown in the error colour until they change. */
const serverInvalid = ref<string[]>([])

// A pasted "a.com, b.com" can reach the model as one tag (or leave an empty one behind):
// split, trim and de-duplicate so every tag is exactly one domain.
watch(() => state.domains, (domains) => {
  const clean = [...new Set(domains.flatMap(splitDomains))]
  if (clean.length !== domains.length || clean.some((d, i) => d !== domains[i])) state.domains = clean
})

function isInvalid(domain: string) {
  return !parseDomain(domain) || serverInvalid.value.includes(domain)
}

/** Turns whatever is still typed in the tags field into tags, so Enter is not required. */
function flushPendingDomain() {
  const input = tagsInput.value?.inputRef
  if (!input?.value.trim()) return
  const pending = splitDomains(input.value).filter(d => !state.domains.includes(d))
  input.value = ''
  if (pending.length) state.domains = [...state.domains, ...pending]
}

/**
 * The form has no submit button on purpose: with several fields the browser then
 * performs no implicit submission, so Enter in the domains field only adds a tag
 * (in Firefox it used to submit the modal and swallow the next domain).
 */
async function submitForm() {
  flushPendingDomain()
  await nextTick()
  await form.value?.submit()
}

async function onSubmit(event: FormSubmitEvent<{ name: string, domains: string[], timezone: string }>) {
  saving.value = true
  serverInvalid.value = []
  const entries = event.data.domains
  try {
    const site = await createSite({
      name: event.data.name,
      timezone: event.data.timezone,
      domains: entries.map((domain) => {
        const parsed = parseDomain(domain)!
        return { host: formatDomain(parsed), include_subdomains: parsed.include_subdomains }
      })
    })
    toast.add({ title: t('sites.created'), color: 'success', icon: 'i-tabler-check' })
    isSiteModalOpen.value = false
    state.name = ''
    state.domains = []
    await selectSite(site.id)
    await navigateTo({ path: '/settings', query: { site: String(site.id) } })
  } catch (error) {
    if (isApiError(error) && error.isValidation) {
      const { domains, rest, invalidIndexes } = domainFieldErrors(error.errors, entries)
      serverInvalid.value = invalidIndexes.map(i => entries[i]).filter((d): d is string => !!d)
      const known = rest.filter(e => e.name === 'name' || e.name === 'timezone')
      form.value?.setErrors([...known, ...(domains ? [{ name: 'domains', message: domains }] : [])])
      const unknown = rest.filter(e => !known.includes(e))
      if (unknown.length || (!known.length && !domains)) {
        toast.add({ title: t('errors.validation'), description: unknown.map(e => e.message).join(' ') || error.message, color: 'error' })
      }
    } else {
      toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
    }
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
          <UInputTags
            ref="tagsInput"
            v-model="state.domains"
            :placeholder="t('site.domainPlaceholder')"
            :delimiter="DOMAIN_DELIMITER"
            add-on-blur
            add-on-paste
            class="w-full"
            data-testid="domains-input"
          >
            <template #item-text="{ item }">
              <span
                :class="isInvalid(String(item)) ? 'text-error font-medium' : undefined"
                :data-invalid="isInvalid(String(item)) ? '' : undefined"
                data-testid="domain-tag"
              >{{ item }}</span>
            </template>
          </UInputTags>
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
          <UButton
            :label="t('common.create')"
            :loading="saving"
            data-testid="create-site"
            @click="submitForm"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
