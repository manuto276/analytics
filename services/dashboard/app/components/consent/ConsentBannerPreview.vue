<script setup lang="ts">
import type { ConsentConfigInput } from '~/types'

const props = defineProps<{
  config: ConsentConfigInput
  locale: string
}>()

const texts = computed(() => props.config.texts[props.locale] ?? props.config.texts[props.config.default_locale] ?? Object.values(props.config.texts)[0])
const policyUrl = computed(() => {
  const url = props.config.policy_urls[props.locale] ?? props.config.policy_urls[props.config.default_locale] ?? ''
  // Only http(s) links are rendered, never javascript: or data: URLs.
  return /^https?:\/\//i.test(url) ? url : ''
})

const position = computed(() => ({
  'bottom': 'inset-x-3 bottom-3',
  'bottom-left': 'left-3 bottom-3 max-w-sm',
  'bottom-right': 'right-3 bottom-3 max-w-sm'
})[props.config.theme.pos])

const boxStyle = computed(() => ({
  background: props.config.theme.bg,
  color: props.config.theme.fg,
  borderRadius: `${props.config.theme.rad}px`
}))
const buttonStyle = computed(() => ({
  background: props.config.theme.ac,
  color: props.config.theme.acf,
  borderRadius: `${Math.max(0, props.config.theme.rad - 4)}px`
}))
</script>

<template>
  <div class="relative h-80 rounded-lg border border-default bg-elevated/50 overflow-hidden" data-testid="banner-preview">
    <div
      v-if="texts"
      class="absolute p-4 shadow-lg"
      :class="position"
      :style="boxStyle"
      role="dialog"
      :aria-label="texts.title"
    >
      <p class="font-semibold mb-1">
        {{ texts.title }}
      </p>
      <p class="text-sm mb-3 whitespace-pre-line">
        {{ texts.body }}
        <a
          v-if="policyUrl"
          :href="policyUrl"
          target="_blank"
          rel="noopener"
          class="underline"
          :style="{ color: config.theme.fg }"
        >{{ texts.policy }}</a>
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="px-3 py-1.5 text-sm font-medium" :style="buttonStyle">
          {{ texts.reject }}
        </button>
        <button type="button" class="px-3 py-1.5 text-sm font-medium" :style="buttonStyle">
          {{ texts.accept }}
        </button>
      </div>
    </div>
    <button
      v-if="config.show_floating_reopen && texts"
      type="button"
      class="absolute left-3 top-3 px-2 py-1 text-xs"
      :style="buttonStyle"
    >
      {{ texts.reopen }}
    </button>
  </div>
</template>
