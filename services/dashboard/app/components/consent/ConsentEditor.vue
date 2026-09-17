<script setup lang="ts">
import type { ConsentConfigInput, ConsentTexts } from '~/types'

const props = withDefaults(defineProps<{
  readonly?: boolean
}>(), {
  readonly: false
})

const model = defineModel<ConsentConfigInput>({ required: true })
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
const positionItems = computed(() => (['bottom', 'bottom-left', 'bottom-right'] as const).map(value => ({ label: t(`consent.positions.${value}`), value })))

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
            icon="i-lucide-plus"
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
        <UFormField :label="t('consent.policyUrl')" :name="`policy_urls.${activeLocale}`">
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
            icon="i-lucide-trash"
            size="xs"
            color="error"
            variant="ghost"
            @click="removeLocale(activeLocale)"
          />
        </div>
      </template>
    </UPageCard>

    <UPageCard variant="subtle" :title="t('consent.theme')">
      <div class="grid grid-cols-2 gap-4">
        <UFormField
          v-for="key in (['bg', 'fg', 'ac', 'acf'] as const)"
          :key="key"
          :label="t(`consent.colors.${key}`)"
          :name="`theme.${key}`"
        >
          <div class="flex items-center gap-2">
            <input
              v-model="model.theme[key]"
              type="color"
              class="h-8 w-10 rounded border border-default bg-transparent"
              :disabled="props.readonly"
              :aria-label="t(`consent.colors.${key}`)"
            >
            <UInput
              v-model="model.theme[key]"
              :disabled="props.readonly"
              class="w-28 font-mono"
              :data-testid="`color-${key}`"
            />
          </div>
        </UFormField>
      </div>

      <div class="space-y-2" data-testid="contrast-check">
        <UAlert
          :color="contrast.textOk ? 'success' : 'error'"
          variant="subtle"
          :icon="contrast.textOk ? 'i-lucide-check' : 'i-lucide-triangle-alert'"
          :title="t('consent.contrastText', { ratio: Number.isNaN(contrast.text) ? '—' : contrast.text.toFixed(2) })"
          :description="contrast.textOk ? undefined : t('consent.contrastFail')"
        />
        <UAlert
          :color="contrast.actionOk ? 'success' : 'error'"
          variant="subtle"
          :icon="contrast.actionOk ? 'i-lucide-check' : 'i-lucide-triangle-alert'"
          :title="t('consent.contrastAction', { ratio: Number.isNaN(contrast.action) ? '—' : contrast.action.toFixed(2) })"
          :description="contrast.actionOk ? undefined : t('consent.contrastFail')"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <UFormField :label="t('consent.radius')" name="theme.rad">
          <UInputNumber
            v-model="model.theme.rad"
            :min="0"
            :max="24"
            :disabled="props.readonly"
          />
        </UFormField>
        <UFormField :label="t('consent.position')" name="theme.pos">
          <USelect
            v-model="model.theme.pos"
            :items="positionItems"
            :disabled="props.readonly"
            class="w-full"
          />
        </UFormField>
      </div>
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
