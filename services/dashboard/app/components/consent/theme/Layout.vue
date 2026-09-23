<script setup lang="ts">
import { BUTTON_LAYOUTS, DESKTOP_POSITIONS, MOBILE_POSITIONS, RANGES } from '~/utils/consentTheme'
import type { ThemeV2 } from '~/utils/consentTheme'

const props = withDefaults(defineProps<{
  errors?: Record<string, string[]>
  readonly?: boolean
}>(), {
  errors: () => ({}),
  readonly: false
})

const theme = defineModel<ThemeV2>('theme', { required: true })
const device = defineModel<'desktop' | 'mobile'>('device', { default: 'desktop' })
const { t } = useI18n()
const err = (path: string) => props.errors[path]?.[0]

const deviceTabs = computed(() => (['desktop', 'mobile'] as const).map(value => ({ label: t(`consent.devices.${value}`), value, icon: value === 'desktop' ? 'i-tabler-device-desktop' : 'i-tabler-device-mobile' })))
const desktopPositions = computed(() => DESKTOP_POSITIONS.map(value => ({ label: t(`consent.layout.positions.${value}`), value })))
const mobilePositions = computed(() => MOBILE_POSITIONS.map(value => ({ label: t(`consent.layout.positions.${value}`), value })))
const buttonItems = computed(() => BUTTON_LAYOUTS.map(value => ({ label: t(`consent.layout.buttonLayouts.${value}`), value })))
</script>

<template>
  <div class="space-y-4" data-testid="section-layout">
    <UFormField
      :label="t('consent.layout.breakpoint')"
      :description="t('consent.layout.breakpointHint', { px: theme.layout.breakpoint })"
      name="theme.layout.breakpoint"
      :error="err('theme.layout.breakpoint')"
    >
      <UInputNumber
        v-model="theme.layout.breakpoint"
        :min="RANGES['layout.breakpoint'][0]"
        :max="RANGES['layout.breakpoint'][1]"
        :step="16"
        :disabled="props.readonly"
        class="w-32"
      />
    </UFormField>

    <UTabs
      v-model="device"
      :items="deviceTabs"
      :content="false"
      size="xs"
      data-testid="layout-device"
    />

    <div v-if="device === 'desktop'" class="grid gap-4 sm:grid-cols-2" data-testid="layout-desktop">
      <UFormField :label="t('consent.layout.position')" name="theme.layout.desktop.position" :error="err('theme.layout.desktop.position')">
        <USelect
          v-model="theme.layout.desktop.position"
          :items="desktopPositions"
          :disabled="props.readonly"
          class="w-full"
          data-testid="desktop-position"
        />
      </UFormField>
      <UFormField :label="t('consent.layout.buttons')" name="theme.layout.desktop.buttons" :error="err('theme.layout.desktop.buttons')">
        <USelect
          v-model="theme.layout.desktop.buttons"
          :items="buttonItems"
          :disabled="props.readonly"
          class="w-full"
        />
      </UFormField>
      <UFormField :label="t('consent.layout.maxWidth')" name="theme.layout.desktop.maxWidth" :error="err('theme.layout.desktop.maxWidth')">
        <UInputNumber
          v-model="theme.layout.desktop.maxWidth"
          :min="RANGES['layout.desktop.maxWidth'][0]"
          :max="RANGES['layout.desktop.maxWidth'][1]"
          :step="8"
          :disabled="props.readonly"
          class="w-32"
        />
      </UFormField>
      <UFormField :label="t('consent.layout.offset')" name="theme.layout.desktop.offset" :error="err('theme.layout.desktop.offset')">
        <UInputNumber
          v-model="theme.layout.desktop.offset"
          :min="RANGES['layout.desktop.offset'][0]"
          :max="RANGES['layout.desktop.offset'][1]"
          :disabled="props.readonly"
          class="w-32"
        />
      </UFormField>
    </div>

    <div v-else class="space-y-4" data-testid="layout-mobile">
      <p class="text-xs text-muted">
        {{ t('consent.layout.mobileHint') }}
      </p>
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField :label="t('consent.layout.position')" name="theme.layout.mobile.position" :error="err('theme.layout.mobile.position')">
          <USelect
            v-model="theme.layout.mobile.position"
            :items="mobilePositions"
            :disabled="props.readonly"
            class="w-full"
            data-testid="mobile-position"
          />
        </UFormField>
        <UFormField :label="t('consent.layout.buttons')" name="theme.layout.mobile.buttons" :error="err('theme.layout.mobile.buttons')">
          <USelect
            v-model="theme.layout.mobile.buttons"
            :items="buttonItems"
            :disabled="props.readonly"
            class="w-full"
          />
        </UFormField>
        <UFormField :label="t('consent.layout.offset')" name="theme.layout.mobile.offset" :error="err('theme.layout.mobile.offset')">
          <UInputNumber
            v-model="theme.layout.mobile.offset"
            :min="RANGES['layout.mobile.offset'][0]"
            :max="RANGES['layout.mobile.offset'][1]"
            :disabled="props.readonly || theme.layout.mobile.position === 'sheet'"
            class="w-32"
            data-testid="mobile-offset"
          />
        </UFormField>
      </div>
    </div>
  </div>
</template>
