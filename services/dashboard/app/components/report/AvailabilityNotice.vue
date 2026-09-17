<script setup lang="ts">
import type { AvailabilityKey } from '~/utils/reportColumns'

const props = defineProps<{
  missing: AvailabilityKey[]
}>()

const { t } = useI18n()
const description = computed(() => props.missing.map(key => t(`availability.${key}`)).join(' '))
</script>

<template>
  <UAlert
    v-if="missing.length"
    color="warning"
    variant="subtle"
    icon="i-lucide-info"
    :title="t('availability.title')"
    :description="description"
    data-testid="availability-notice"
  />
</template>
