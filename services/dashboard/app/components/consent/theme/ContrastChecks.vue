<script setup lang="ts">
import type { ContrastPairKey, ThemeContrast } from '~/utils/contrast'

const props = withDefaults(defineProps<{
  contrast: ThemeContrast
  only?: ContrastPairKey[]
}>(), {
  only: undefined
})

const { t } = useI18n()
const pairs = computed(() => props.contrast.pairs.filter(p => !props.only || props.only.includes(p.key)))
</script>

<template>
  <ul class="space-y-1 text-sm" data-testid="contrast-check">
    <li
      v-for="pair in pairs"
      :key="pair.key"
      class="flex items-center gap-2"
      :class="pair.ok ? 'text-success' : 'text-error'"
      :data-testid="`contrast-${pair.key}`"
      :data-ok="pair.ok"
    >
      <UIcon :name="pair.ok ? 'i-tabler-circle-check' : 'i-tabler-alert-triangle'" class="size-4 shrink-0" />
      <span
        class="inline-flex h-5 min-w-8 items-center justify-center rounded border border-default px-1 text-xs font-semibold"
        :style="{ color: pair.foreground, background: pair.background }"
        aria-hidden="true"
      >{{ t('consent.contrast.sample') }}</span>
      <span class="text-default">{{ t(`consent.contrast.${pair.key}`) }}</span>
      <span class="font-mono">{{ t('consent.contrast.ratio', { ratio: Number.isNaN(pair.ratio) ? '—' : pair.ratio.toFixed(2) }) }}</span>
      <span v-if="!pair.ok">{{ t('consent.contrastFail') }}</span>
    </li>
  </ul>
</template>
