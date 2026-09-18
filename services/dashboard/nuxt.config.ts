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
  },

  // The SPA is served by the installation itself, so every icon has to be in the bundle: there is
  // no Nitro server to answer /api/_nuxt_icon, and asking the Iconify API would send a request to a
  // third party from the page an operator signs into.
  icon: {
    mode: 'svg',
    provider: 'none',
    clientBundle: {
      // The default scan misses app.config.ts, where the Nuxt UI icon map lives, so the icons only
      // named there (the sidebar toggles, the loader, the pagination chevrons) rendered as empty
      // <svg> elements. Include .ts sources as well.
      scan: { globInclude: ['**/*.{vue,jsx,tsx,ts,mjs,js}'], globExclude: ['node_modules', 'dist', '.nuxt', '.output', 'test'] },
      includeCustomCollections: true
    }
  }
})
