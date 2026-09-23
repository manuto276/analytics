<script setup lang="ts">
import { FONT_FAMILY, FONT_FAMILY_MAX, LINE_HEIGHT, RANGES } from '~/utils/consentTheme'
import type { ThemeV2 } from '~/utils/consentTheme'

const props = withDefaults(defineProps<{
  errors?: Record<string, string[]>
  readonly?: boolean
}>(), {
  errors: () => ({}),
  readonly: false
})

const theme = defineModel<ThemeV2>('theme', { required: true })
const { t } = useI18n()
const err = (path: string) => props.errors[path]?.[0]

type FamilyMode = 'inherit' | 'system' | 'custom'
const familyMode = computed<FamilyMode>({
  get: () => theme.value.font.family === 'inherit' || theme.value.font.family === 'system' ? theme.value.font.family : 'custom',
  set: (mode) => {
    theme.value.font.family = mode === 'custom' ? '"Inter", sans-serif' : mode
  }
})
const familyItems = computed(() => (['inherit', 'system', 'custom'] as const).map(value => ({ label: t(`consent.font.families.${value}`), value })))
const familyError = computed(() => err('theme.font.family')
  ?? (familyMode.value === 'custom' && !FONT_FAMILY.test(theme.value.font.family) ? t('consent.font.familyInvalid') : undefined))

const inheritSize = computed({
  get: () => theme.value.font.size === null,
  set: (inherit: boolean) => {
    theme.value.font.size = inherit ? null : 16
  }
})
const size = computed({
  get: () => theme.value.font.size ?? 16,
  set: (value: number) => {
    theme.value.font.size = value
  }
})
</script>

<template>
  <div class="space-y-4" data-testid="section-typography">
    <UFormField
      :label="t('consent.font.family')"
      :description="t('consent.font.familyHint')"
      name="theme.font.family"
      :error="familyError"
    >
      <div class="flex flex-wrap gap-2">
        <USelect
          v-model="familyMode"
          :items="familyItems"
          :disabled="props.readonly"
          class="w-40"
          :aria-label="t('consent.font.family')"
          data-testid="font-family-mode"
        />
        <UInput
          v-if="familyMode === 'custom'"
          v-model="theme.font.family"
          :maxlength="FONT_FAMILY_MAX"
          :disabled="props.readonly"
          class="min-w-48 flex-1 font-mono"
          :aria-label="t('consent.font.familyCustom')"
          data-testid="font-family"
        />
      </div>
    </UFormField>

    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField :label="t('consent.font.size')" name="theme.font.size" :error="err('theme.font.size')">
        <div class="flex flex-wrap items-center gap-3">
          <USwitch
            v-model="inheritSize"
            :label="t('consent.font.inheritSize')"
            :disabled="props.readonly"
            data-testid="font-size-inherit"
          />
          <UInputNumber
            v-if="!inheritSize"
            v-model="size"
            :min="RANGES['font.size'][0]"
            :max="RANGES['font.size'][1]"
            :disabled="props.readonly"
            class="w-28"
            :aria-label="t('consent.font.size')"
          />
        </div>
      </UFormField>
      <UFormField :label="t('consent.font.lineHeight')" name="theme.font.lineHeight" :error="err('theme.font.lineHeight')">
        <UInputNumber
          v-model="theme.font.lineHeight"
          :min="LINE_HEIGHT[0]"
          :max="LINE_HEIGHT[1]"
          :step="0.05"
          :format-options="{ maximumFractionDigits: 2 }"
          :disabled="props.readonly"
          class="w-28"
        />
      </UFormField>
    </div>
  </div>
</template>
