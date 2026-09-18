<script setup lang="ts">
const props = defineProps<{
  currentVersion: number | null
  loading?: boolean
}>()

const emit = defineEmits<{
  publish: [materialChange: boolean]
}>()

const open = defineModel<boolean>('open', { default: false })
const { t } = useI18n()
const materialChange = ref(false)

watch(open, (value) => {
  if (value) materialChange.value = false
})

const nextVersion = computed(() => (props.currentVersion ?? 0) + (materialChange.value || props.currentVersion === null ? 1 : 0))
</script>

<template>
  <UModal v-model:open="open" :title="t('consent.publishTitle')" :description="t('consent.publishDescription')">
    <template #body>
      <div class="space-y-4">
        <UFormField :label="t('consent.materialChange')" :description="t('consent.materialChangeHint')" class="flex items-start justify-between gap-4">
          <USwitch v-model="materialChange" data-testid="material-change" />
        </UFormField>
        <UAlert
          v-if="materialChange"
          color="warning"
          variant="subtle"
          icon="i-tabler-refresh-dot"
          :title="t('consent.materialWarning')"
        />
        <p class="text-sm text-muted" data-testid="next-version">
          {{ t('consent.nextVersion', { n: nextVersion }) }}
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          :label="t('common.cancel')"
          color="neutral"
          variant="subtle"
          @click="open = false"
        />
        <UButton
          :label="t('consent.publish')"
          :loading="loading"
          data-testid="confirm-publish"
          @click="emit('publish', materialChange)"
        />
      </div>
    </template>
  </UModal>
</template>
