// https://nuxt.com/docs/api/configuration/nuxt-config
const apiOrigin = process.env.ANALYTICS_API_ORIGIN || 'http://localhost:8080'

export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@vueuse/nuxt',
    '@nuxtjs/i18n',
    '@nuxt/test-utils/module'
  ],

  // Single-page app served as static files; the API lives on the same origin.
  ssr: false,

  devtools: {
    enabled: true
  },

  app: {
    head: {
      title: 'Analytics'
    }
  },

  css: ['~/assets/css/main.css'],

  compatibilityDate: '2026-06-30',

  nitro: {
    preset: 'static',
    // Dev only: forward API and tracking endpoints to the backend (nginx).
    devProxy: {
      '/api': { target: `${apiOrigin}/api`, changeOrigin: true },
      '/t': { target: `${apiOrigin}/t`, changeOrigin: true }
    }
  },

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  },

  i18n: {
    strategy: 'no_prefix',
    defaultLocale: 'en',
    langDir: 'locales',
    locales: [
      { code: 'en', language: 'en-US', name: 'English', file: 'en.json' },
      { code: 'it', language: 'it-IT', name: 'Italiano', file: 'it.json' }
    ],
    detectBrowserLanguage: {
      useCookie: true,
      cookieKey: 'i18n_locale',
      redirectOn: 'root'
    }
  }
})
