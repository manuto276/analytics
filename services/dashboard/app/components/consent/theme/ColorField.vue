<script setup lang="ts">
const props = withDefaults(defineProps<{
  label: string
  name: string
  error?: string
  disabled?: boolean
}>(), {
  error: undefined,
  disabled: false
})

const model = defineModel<string>({ required: true })
// The native picker only understands #rrggbb; while the text field holds something else, show black.
const pickerValue = computed(() => /^#[0-9a-f]{6}$/i.test(model.value) ? model.value.toLowerCase() : '#000000')
</script>

<template>
  <UFormField :label="props.label" :name="props.name" :error="props.error">
    <div class="flex items-center gap-2">
      <input
        :value="pickerValue"
        type="color"
        class="h-8 w-10 shrink-0 rounded border border-default bg-transparent"
        :disabled="props.disabled"
        :aria-label="props.label"
        @input="model = ($event.target as HTMLInputElement).value"
      >
      <UInput
        v-model="model"
        :disabled="props.disabled"
        :maxlength="7"
        class="w-28 font-mono"
        :data-testid="`color-${props.name}`"
      />
    </div>
  </UFormField>
</template>
