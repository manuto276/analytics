<script setup lang="ts">
const { t, locale, locales } = useI18n()
const { setLocale } = useAuth()

const items = computed(() => locales.value.map(l => ({ label: l.name ?? l.code, value: l.code })))
const model = computed({
  get: () => locale.value,
  set: (value: string) => {
    void setLocale(value as 'en' | 'it')
  }
})
</script>

<template>
  <USelect
    v-model="model"
    :items="items"
    size="xs"
    variant="ghost"
    icon="i-lucide-languages"
    :aria-label="t('user.language')"
    data-testid="locale-switch"
    :ui="{
      base: 'text-highlighted',
      value: 'text-highlighted',
      placeholder: 'text-toned',
      label: 'text-highlighted',
      item: 'text-default',
      itemLabel: 'text-highlighted',
      leadingIcon: 'text-toned',
      trailingIcon: 'text-toned'
    }"
  />
</template>
