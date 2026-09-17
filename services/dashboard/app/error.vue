<script setup lang="ts">
import type { NuxtError } from '#app'

defineProps<{
  error: NuxtError
}>()

const { t, locale } = useI18n()

useHead({
  title: () => t('errors.notFound'),
  htmlAttrs: {
    lang: locale
  }
})
</script>

<template>
  <UApp>
    <UError :error="error" :clear="{ label: t('errors.backHome') }" />
  </UApp>
</template>
