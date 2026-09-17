<script setup lang="ts">
const { loadConfig, config } = useAuth()
const { t } = useI18n()
await loadConfig()
</script>

<template>
  <div class="min-h-svh flex flex-col items-center justify-center gap-6 px-4 py-12 bg-elevated/25">
    <div class="flex items-center gap-2 text-highlighted font-semibold text-lg">
      <UIcon name="i-lucide-chart-no-axes-combined" class="size-6 text-primary" />
      <span>{{ t('app.name') }}</span>
    </div>

    <UCard class="w-full max-w-sm" :ui="{ body: 'p-6 sm:p-8' }">
      <slot />
    </UCard>

    <div class="flex items-center gap-3 text-xs text-toned">
      <LocaleSwitch />
      <ULink
        v-if="config?.source_url"
        :to="config.source_url"
        target="_blank"
        class="hover:text-default"
      >
        {{ t('user.source') }}
      </ULink>
    </div>
  </div>
</template>
