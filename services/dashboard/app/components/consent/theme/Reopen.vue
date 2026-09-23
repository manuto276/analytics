<script setup lang="ts">
import { RANGES, REOPEN_ICONS, REOPEN_POSITIONS, REOPEN_SIZES, REOPEN_VARIANTS } from '~/utils/consentTheme'
import type { ThemeV2 } from '~/utils/consentTheme'
import type { ThemeContrast } from '~/utils/contrast'
import ColorField from './ColorField.vue'
import ContrastChecks from './ContrastChecks.vue'

const props = withDefaults(defineProps<{
  contrast: ThemeContrast
  /** show_floating_reopen of the configuration. */
  enabled?: boolean
  errors?: Record<string, string[]>
  readonly?: boolean
}>(), {
  enabled: true,
  errors: () => ({}),
  readonly: false
})

const theme = defineModel<ThemeV2>('theme', { required: true })
const device = defineModel<'desktop' | 'mobile'>('device', { default: 'desktop' })
const { t } = useI18n()
const err = (path: string) => props.errors[path]?.[0]

const deviceTabs = computed(() => (['desktop', 'mobile'] as const).map(value => ({ label: t(`consent.devices.${value}`), value, icon: value === 'desktop' ? 'i-tabler-device-desktop' : 'i-tabler-device-mobile' })))
const iconItems = computed(() => REOPEN_ICONS.map(value => ({ label: t(`consent.reopen.icons.${value}`), value })))
const sizeItems = computed(() => REOPEN_SIZES.map(value => ({ label: t(`consent.reopen.sizes.${value}`), value })))
const variantItems = computed(() => REOPEN_VARIANTS.map(value => ({ label: t(`consent.reopen.variants.${value}`), value })))
const positionItems = computed(() => REOPEN_POSITIONS.map(value => ({ label: t(`consent.layout.positions.${value}`), value })))

/** The reopen colours default to the button colours (null); switching them off restores that. */
const ownColors = computed({
  get: () => theme.value.reopen.background !== null || theme.value.reopen.text !== null,
  set: (on: boolean) => {
    theme.value.reopen.background = on ? theme.value.colors.accent : null
    theme.value.reopen.text = on ? theme.value.colors.accentText : null
  }
})
const background = computed({
  get: () => theme.value.reopen.background ?? theme.value.colors.accent,
  set: (value: string) => {
    theme.value.reopen.background = value
  }
})
const text = computed({
  get: () => theme.value.reopen.text ?? theme.value.colors.accentText,
  set: (value: string) => {
    theme.value.reopen.text = value
  }
})

/** Device classes where the floating button is hidden (Garante 2021, B3: withdrawal must stay easy). */
const hiddenOn = computed(() => (['desktop', 'mobile'] as const).filter(d => theme.value.reopen[d].variant === 'hidden'))
const hiddenDevices = computed(() => hiddenOn.value.map(d => t(`consent.devices.${d}`)).join(', '))
const placement = computed(() => theme.value.reopen[device.value])
</script>

<template>
  <div class="space-y-4" data-testid="section-reopen">
    <UAlert
      v-if="!props.enabled"
      color="neutral"
      variant="subtle"
      icon="i-tabler-info-circle"
      :description="t('consent.reopen.disabled')"
    />

    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField :label="t('consent.reopen.icon')" name="theme.reopen.icon" :error="err('theme.reopen.icon')">
        <USelect
          v-model="theme.reopen.icon"
          :items="iconItems"
          :disabled="props.readonly"
          class="w-full"
        />
      </UFormField>
      <UFormField :label="t('consent.reopen.size')" name="theme.reopen.size" :error="err('theme.reopen.size')">
        <USelect
          v-model="theme.reopen.size"
          :items="sizeItems"
          :disabled="props.readonly"
          class="w-full"
        />
      </UFormField>
    </div>

    <UFormField :label="t('consent.reopen.ownColors')" :description="t('consent.reopen.ownColorsHint')">
      <USwitch
        v-model="ownColors"
        :disabled="props.readonly"
        :aria-label="t('consent.reopen.ownColors')"
        data-testid="reopen-own-colors"
      />
    </UFormField>
    <div v-if="ownColors" class="grid gap-4 sm:grid-cols-2">
      <ColorField
        v-model="background"
        :label="t('consent.reopen.background')"
        name="theme.reopen.background"
        :error="err('theme.reopen.background')"
        :disabled="props.readonly"
      />
      <ColorField
        v-model="text"
        :label="t('consent.reopen.text')"
        name="theme.reopen.text"
        :error="err('theme.reopen.text')"
        :disabled="props.readonly"
      />
    </div>
    <ContrastChecks :contrast="props.contrast" :only="['reopen']" />

    <UTabs
      v-model="device"
      :items="deviceTabs"
      :content="false"
      size="xs"
      data-testid="reopen-device"
    />
    <div class="grid gap-4 sm:grid-cols-3" :data-testid="`reopen-${device}`">
      <UFormField :label="t('consent.reopen.variant')" :name="`theme.reopen.${device}.variant`" :error="err(`theme.reopen.${device}.variant`)">
        <USelect
          v-model="placement.variant"
          :items="variantItems"
          :disabled="props.readonly"
          class="w-full"
          :data-testid="`reopen-${device}-variant`"
        />
      </UFormField>
      <UFormField :label="t('consent.reopen.position')" :name="`theme.reopen.${device}.position`" :error="err(`theme.reopen.${device}.position`)">
        <USelect
          v-model="placement.position"
          :items="positionItems"
          :disabled="props.readonly || placement.variant === 'hidden'"
          class="w-full"
        />
      </UFormField>
      <UFormField :label="t('consent.reopen.offset')" :name="`theme.reopen.${device}.offset`" :error="err(`theme.reopen.${device}.offset`)">
        <UInputNumber
          v-model="placement.offset"
          :min="RANGES[`reopen.${device}.offset`][0]"
          :max="RANGES[`reopen.${device}.offset`][1]"
          :disabled="props.readonly || placement.variant === 'hidden'"
          class="w-28"
        />
      </UFormField>
    </div>

    <UAlert
      v-if="hiddenOn.length"
      color="warning"
      variant="subtle"
      icon="i-tabler-alert-triangle"
      :title="t('consent.reopen.hiddenTitle', { devices: hiddenDevices })"
      :description="t('consent.reopen.hiddenWarning')"
      data-testid="reopen-hidden-warning"
    />
  </div>
</template>
