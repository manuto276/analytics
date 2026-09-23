<script setup lang="ts">
import type { ThemeV2 } from '~/utils/consentTheme'
import type { ThemeContrast } from '~/utils/contrast'
import ColorField from './ColorField.vue'
import ContrastChecks from './ContrastChecks.vue'

const props = withDefaults(defineProps<{
  contrast: ThemeContrast
  errors?: Record<string, string[]>
  readonly?: boolean
}>(), {
  errors: () => ({}),
  readonly: false
})

const theme = defineModel<ThemeV2>('theme', { required: true })
const { t } = useI18n()

const KEYS = ['background', 'text', 'accent', 'accentText', 'link', 'border'] as const
const err = (path: string) => props.errors[path]?.[0]

const backdropOn = computed({
  get: () => theme.value.colors.backdrop !== null,
  set: (on: boolean) => {
    theme.value.colors.backdrop = on ? '#11182780' : null
  }
})
const backdropColor = computed({
  get: () => theme.value.colors.backdrop ?? '',
  set: (value: string) => {
    theme.value.colors.backdrop = value
  }
})
</script>

<template>
  <div class="space-y-4" data-testid="section-colors">
    <div class="grid gap-4 sm:grid-cols-2">
      <ColorField
        v-for="key in KEYS"
        :key="key"
        v-model="theme.colors[key]"
        :label="t(`consent.colors.${key}`)"
        :name="`theme.colors.${key}`"
        :error="err(`theme.colors.${key}`)"
        :disabled="props.readonly"
      />
    </div>
    <p class="text-xs text-muted">
      {{ t('consent.colors.oneButtonStyle') }}
    </p>

    <ContrastChecks :contrast="props.contrast" :only="['text', 'accentText', 'link']" />

    <UFormField
      :label="t('consent.colors.backdrop')"
      :description="t('consent.colors.backdropHint')"
      name="theme.colors.backdrop"
      :error="err('theme.colors.backdrop')"
    >
      <div class="flex flex-wrap items-center gap-3">
        <USwitch v-model="backdropOn" :disabled="props.readonly" :aria-label="t('consent.colors.backdrop')" />
        <UInput
          v-if="backdropOn"
          v-model="backdropColor"
          :disabled="props.readonly"
          :maxlength="9"
          class="w-32 font-mono"
          data-testid="color-theme.colors.backdrop"
        />
      </div>
    </UFormField>
  </div>
</template>
