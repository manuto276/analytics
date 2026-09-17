<script setup lang="ts">
import { en, it } from '@nuxt/ui/locale'

const colorMode = useColorMode()
const { t, locale } = useI18n()

const color = computed(() => colorMode.value === 'dark' ? '#1b1718' : 'white')
const uiLocale = computed(() => (locale.value === 'it' ? it : en))

useHead({
  titleTemplate: title => (title ? `${title} · ${t('app.name')}` : t('app.name')),
  meta: [
    { charset: 'utf-8' },
    { name: 'viewport', content: 'width=device-width, initial-scale=1' },
    { name: 'robots', content: 'noindex, nofollow' },
    { key: 'theme-color', name: 'theme-color', content: color }
  ],
  link: [
    { rel: 'icon', href: '/favicon.ico', sizes: '48x48' },
    { rel: 'icon', href: '/favicon.svg', type: 'image/svg+xml' },
    { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' }
  ],
  htmlAttrs: {
    lang: locale
  }
})
</script>

<template>
  <UApp :locale="uiLocale">
    <NuxtLoadingIndicator />

    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
