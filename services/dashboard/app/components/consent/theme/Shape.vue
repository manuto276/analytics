<script setup lang="ts">
import { RANGES, SHADOWS } from '~/utils/consentTheme'
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
const shadowItems = computed(() => SHADOWS.map(value => ({ label: t(`consent.shape.shadows.${value}`), value })))
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2" data-testid="section-shape">
    <UFormField :label="t('consent.shape.radius')" name="theme.shape.radius" :error="err('theme.shape.radius')">
      <UInputNumber
        v-model="theme.shape.radius"
        :min="RANGES['shape.radius'][0]"
        :max="RANGES['shape.radius'][1]"
        :disabled="props.readonly"
        class="w-28"
      />
    </UFormField>
    <UFormField
      :label="t('consent.shape.buttonRadius')"
      :description="t('consent.shape.buttonRadiusHint')"
      name="theme.shape.buttonRadius"
      :error="err('theme.shape.buttonRadius')"
    >
      <UInputNumber
        v-model="theme.shape.buttonRadius"
        :min="RANGES['shape.buttonRadius'][0]"
        :max="RANGES['shape.buttonRadius'][1]"
        :disabled="props.readonly"
        class="w-28"
      />
    </UFormField>
    <UFormField :label="t('consent.shape.padding')" name="theme.spacing.padding" :error="err('theme.spacing.padding')">
      <UInputNumber
        v-model="theme.spacing.padding"
        :min="RANGES['spacing.padding'][0]"
        :max="RANGES['spacing.padding'][1]"
        :disabled="props.readonly"
        class="w-28"
      />
    </UFormField>
    <UFormField :label="t('consent.shape.gap')" name="theme.spacing.gap" :error="err('theme.spacing.gap')">
      <UInputNumber
        v-model="theme.spacing.gap"
        :min="RANGES['spacing.gap'][0]"
        :max="RANGES['spacing.gap'][1]"
        :disabled="props.readonly"
        class="w-28"
      />
    </UFormField>
    <UFormField :label="t('consent.shape.borderWidth')" name="theme.border.width" :error="err('theme.border.width')">
      <UInputNumber
        v-model="theme.border.width"
        :min="RANGES['border.width'][0]"
        :max="RANGES['border.width'][1]"
        :disabled="props.readonly"
        class="w-28"
      />
    </UFormField>
    <UFormField :label="t('consent.shape.shadow')" name="theme.shadow" :error="err('theme.shadow')">
      <USelect
        v-model="theme.shadow"
        :items="shadowItems"
        :disabled="props.readonly"
        class="w-40"
      />
    </UFormField>
  </div>
</template>
