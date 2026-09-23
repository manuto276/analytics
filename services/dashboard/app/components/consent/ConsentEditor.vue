<script setup lang="ts">
import type { ConsentTexts } from '~/types'
import type { ConsentDraft } from '~/utils/consentTheme'
import ThemeAdvanced from './theme/Advanced.vue'
import ThemeColors from './theme/Colors.vue'
import ThemeLayout from './theme/Layout.vue'
import ThemeReopen from './theme/Reopen.vue'
import ThemeShape from './theme/Shape.vue'
import ThemeTypography from './theme/Typography.vue'

const props = withDefaults(defineProps<{
  readonly?: boolean
  /** Validation errors from the server (save or preview), by field path (theme.css, theme.colors.text…). */
  errors?: Record<string, string[]>
}>(), {
  readonly: false,
  errors: () => ({})
})

const model = defineModel<ConsentDraft>({ required: true })
/** Desktop / Mobile tab of the layout and reopen sections (the page links it to the preview). */
const device = defineModel<'desktop' | 'mobile'>('device', { default: 'desktop' })
const activeLocale = defineModel<string>('locale', { default: 'en' })

const { t } = useI18n()

const TEXT_FIELDS: { key: keyof ConsentTexts, max: number, multiline?: boolean }[] = [
  { key: 'title', max: 120 },
  { key: 'body', max: 1200, multiline: true },
  { key: 'accept', max: 40 },
  { key: 'reject', max: 40 },
  { key: 'close', max: 40 },
  { key: 'policy', max: 60 },
  { key: 'reopen', max: 60 }
]

const locales = computed(() => Object.keys(model.value.texts))
const localeTabs = computed(() => locales.value.map(code => ({ label: code.toUpperCase(), value: code })))
const contrast = computed(() => themeContrast(model.value.theme))
const err = (path: string) => props.errors[path]?.[0]

watch(locales, (list) => {
  if (!list.includes(activeLocale.value) && list[0]) activeLocale.value = list[0]
}, { immediate: true })

const newLocale = ref('')
const newLocaleValid = computed(() => /^[a-z]{2}(-[A-Z]{2})?$/.test(newLocale.value) && !locales.value.includes(newLocale.value))

function addLocale() {
  if (!newLocaleValid.value) return
  const source = model.value.texts[model.value.default_locale] ?? Object.values(model.value.texts)[0]
  model.value.texts[newLocale.value] = source ? { ...source } : { title: '', body: '', accept: '', reject: '', close: '', policy: '', reopen: '' }
  model.value.policy_urls[newLocale.value] = model.value.policy_urls[model.value.default_locale] ?? ''
  activeLocale.value = newLocale.value
  newLocale.value = ''
}

function removeLocale(code: string) {
  if (code === model.value.default_locale || locales.value.length <= 1) return
  model.value.texts = Object.fromEntries(Object.entries(model.value.texts).filter(([key]) => key !== code))
  model.value.policy_urls = Object.fromEntries(Object.entries(model.value.policy_urls).filter(([key]) => key !== code))
}

const policyUrl = computed({
  get: () => model.value.policy_urls[activeLocale.value] ?? '',
  set: (value: string) => {
    model.value.policy_urls[activeLocale.value] = value
  }
})

defineExpose({ contrast })
</script>

<template>
  <div class="space-y-6">
    <UPageCard variant="subtle" :title="t('consent.texts')">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <UTabs
          v-model="activeLocale"
          :items="localeTabs"
          :content="false"
          size="xs"
        />
        <div v-if="!props.readonly" class="flex items-center gap-2">
          <UInput
            v-model="newLocale"
            size="xs"
            :placeholder="t('consent.localeCode')"
            class="w-24"
            :aria-label="t('consent.addLocale')"
          />
          <UButton
            icon="i-tabler-plus"
            size="xs"
            color="neutral"
            variant="subtle"
            :label="t('consent.addLocale')"
            :disabled="!newLocaleValid"
            @click="addLocale"
          />
        </div>
      </div>

      <template v-if="model.texts[activeLocale]">
        <UFormField
          v-for="field in TEXT_FIELDS"
          :key="field.key"
          :label="t(`consent.fields.${field.key}`)"
          :name="`texts.${activeLocale}.${field.key}`"
          :error="err(`texts.${activeLocale}.${field.key}`)"
        >
          <UTextarea
            v-if="field.multiline"
            v-model="model.texts[activeLocale]![field.key]"
            :maxlength="field.max"
            :disabled="props.readonly"
            autoresize
            :rows="4"
            class="w-full"
          />
          <UInput
            v-else
            v-model="model.texts[activeLocale]![field.key]"
            :maxlength="field.max"
            :disabled="props.readonly"
            class="w-full"
          />
        </UFormField>
        <UFormField :label="t('consent.policyUrl')" :name="`policy_urls.${activeLocale}`" :error="err(`policy_urls.${activeLocale}`)">
          <UInput
            v-model="policyUrl"
            type="url"
            :disabled="props.readonly"
            class="w-full"
          />
        </UFormField>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <UFormField :label="t('consent.defaultLocale')">
            <USelect
              v-model="model.default_locale"
              :items="locales"
              :disabled="props.readonly"
              class="w-28"
            />
          </UFormField>
          <UButton
            v-if="!props.readonly && activeLocale !== model.default_locale && locales.length > 1"
            :label="t('consent.removeLocale')"
            icon="i-tabler-trash"
            size="xs"
            color="error"
            variant="ghost"
            @click="removeLocale(activeLocale)"
          />
        </div>
      </template>
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.sections.colors')">
      <ThemeColors
        v-model:theme="model.theme"
        :contrast="contrast"
        :errors="props.errors"
        :readonly="props.readonly"
      />
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.sections.typography')">
      <ThemeTypography v-model:theme="model.theme" :errors="props.errors" :readonly="props.readonly" />
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.sections.shape')">
      <ThemeShape v-model:theme="model.theme" :errors="props.errors" :readonly="props.readonly" />
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.sections.layout')">
      <ThemeLayout
        v-model:theme="model.theme"
        v-model:device="device"
        :errors="props.errors"
        :readonly="props.readonly"
      />
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.sections.reopen')">
      <ThemeReopen
        v-model:theme="model.theme"
        v-model:device="device"
        :contrast="contrast"
        :enabled="model.show_floating_reopen"
        :errors="props.errors"
        :readonly="props.readonly"
      />
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.sections.advanced')" :description="t('consent.sections.advancedHint')">
      <ThemeAdvanced v-model:theme="model.theme" :errors="props.errors" :readonly="props.readonly" />
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.behaviour')">
      <div class="grid grid-cols-2 gap-4">
        <UFormField :label="t('consent.acceptedTtl')" name="accepted_ttl_days">
          <UInputNumber
            v-model="model.accepted_ttl_days"
            :min="1"
            :max="395"
            :disabled="props.readonly"
          />
        </UFormField>
        <UFormField :label="t('consent.rejectedTtl')" name="rejected_ttl_days">
          <UInputNumber
            v-model="model.rejected_ttl_days"
            :min="1"
            :max="395"
            :disabled="props.readonly"
          />
        </UFormField>
      </div>
      <UFormField :label="t('consent.floating')" :description="t('consent.floatingHint')" class="flex items-center justify-between gap-4">
        <USwitch v-model="model.show_floating_reopen" :disabled="props.readonly" />
      </UFormField>
    </UPageCard>
  </div>
</template>
