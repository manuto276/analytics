<script setup lang="ts">
const { t } = useI18n()
const { state, removeFilter, clearFilters } = useReportQuery()
</script>

<template>
  <div v-if="state.filters.length" class="flex flex-wrap items-center gap-1.5">
    <UBadge
      v-for="filter in state.filters"
      :key="`${filter.dim}-${filter.op}`"
      color="primary"
      variant="subtle"
      size="lg"
      class="gap-1"
    >
      <span class="text-muted">{{ t(`filters.dims.${filter.dim}`) }}</span>
      <span>{{ t(`filters.ops.${filter.op}`) }}</span>
      <span class="font-medium truncate max-w-48">{{ filter.value }}</span>
      <UButton
        icon="i-lucide-x"
        size="xs"
        color="primary"
        variant="link"
        class="p-0"
        :aria-label="t('filters.remove')"
        @click="removeFilter(filter)"
      />
    </UBadge>
    <UButton
      v-if="state.filters.length > 1"
      :label="t('filters.clear')"
      size="xs"
      color="neutral"
      variant="ghost"
      @click="clearFilters()"
    />
  </div>
</template>
