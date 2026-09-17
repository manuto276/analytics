<script setup lang="ts">
const props = withDefaults(defineProps<{
  value: string
  multiline?: boolean
  label?: string
}>(), {
  multiline: false,
  label: undefined
})

const { t } = useI18n()
const toast = useToast()
const { copy, copied } = useClipboard({ legacy: true })

async function onCopy() {
  await copy(props.value)
  toast.add({ title: t('common.copied'), color: 'success', icon: 'i-lucide-clipboard-check' })
}
</script>

<template>
  <div class="flex items-start gap-2 w-full">
    <UTextarea
      v-if="multiline"
      :model-value="value"
      readonly
      autoresize
      :rows="3"
      :aria-label="label"
      class="w-full font-mono text-xs"
    />
    <UInput
      v-else
      :model-value="value"
      readonly
      :aria-label="label"
      class="w-full font-mono"
    />
    <UButton
      :icon="copied ? 'i-lucide-clipboard-check' : 'i-lucide-clipboard'"
      color="neutral"
      variant="subtle"
      :aria-label="t('common.copy')"
      data-testid="copy-button"
      @click="onCopy"
    />
  </div>
</template>
