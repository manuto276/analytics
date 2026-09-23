<script setup lang="ts">
import { CSS_MAX_BYTES, cssErrorPosition, exportTheme, importTheme, offsetOf } from '~/utils/consentTheme'
import type { ThemeV2 } from '~/utils/consentTheme'

const props = withDefaults(defineProps<{
  errors?: Record<string, string[]>
  readonly?: boolean
}>(), {
  errors: () => ({}),
  readonly: false
})

const theme = defineModel<ThemeV2>('theme', { required: true })
const { t } = useI18n()
const toast = useToast()

/* ------------------------------------------------------------------ custom CSS ------------- */

const PUBLIC_NAMES = '.banner .title .body .link .actions .button .close .reopen .reopen-icon'
const cssBytes = computed(() => new TextEncoder().encode(theme.value.css).length)
const cssErrors = computed(() => (props.errors['theme.css'] ?? []).map(message => ({ message, position: cssErrorPosition(message) })))
const cssField = useTemplateRef<{ textareaRef?: HTMLTextAreaElement } | null>('cssField')

/** Put the caret where the server says the problem is. */
function goTo(position: { line: number, column: number }) {
  const el = cssField.value?.textareaRef
  if (!el) return
  const offset = offsetOf(theme.value.css, position.line, position.column)
  el.focus()
  el.setSelectionRange(offset, offset)
}

/* ------------------------------------------------------------------ JSON ------------------- */

const json = computed(() => exportTheme(theme.value))
const importText = ref('')
const importError = ref<{ title: string, issues: string[] } | null>(null)
const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const { copy } = useClipboard({ legacy: true })

async function copyJson() {
  await copy(json.value)
  toast.add({ title: t('common.copied'), color: 'success', icon: 'i-tabler-clipboard-check' })
}

function exportFile() {
  const url = URL.createObjectURL(new Blob([json.value], { type: 'application/json' }))
  const link = document.createElement('a')
  link.href = url
  link.download = 'consent-theme.json'
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

function apply(text: string): boolean {
  const result = importTheme(text)
  if (!result.ok) {
    importError.value = result.reason === 'json'
      ? { title: t('consent.advanced.jsonInvalid'), issues: [result.message] }
      : { title: t('consent.advanced.jsonSchema'), issues: result.issues }
    return false
  }
  importError.value = null
  theme.value = result.theme
  toast.add({ title: t('consent.advanced.imported'), color: 'success' })
  return true
}

function applyText() {
  if (apply(importText.value)) importText.value = ''
}

async function importFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  importText.value = await file.text()
  applyText()
}
</script>

<template>
  <div class="space-y-6" data-testid="section-advanced">
    <UFormField
      :label="t('consent.advanced.css')"
      name="theme.css"
      :error="cssErrors.length > 0"
    >
      <template #description>
        {{ t('consent.advanced.cssHint') }}
        <code class="text-xs">{{ PUBLIC_NAMES }}</code>
      </template>
      <UTextarea
        ref="cssField"
        v-model="theme.css"
        :rows="8"
        autoresize
        :maxrows="24"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        :disabled="props.readonly"
        class="w-full font-mono text-xs"
        :aria-label="t('consent.advanced.css')"
        data-testid="custom-css"
      />
      <template #help>
        <span :class="cssBytes > CSS_MAX_BYTES ? 'text-error' : ''">{{ t('consent.advanced.cssBytes', { n: cssBytes, max: CSS_MAX_BYTES }) }}</span>
      </template>
    </UFormField>

    <div
      v-if="cssErrors.length"
      class="rounded-md border border-error/40 bg-error/5 p-3 text-sm"
      role="alert"
      data-testid="css-errors"
    >
      <p class="mb-1 font-medium text-error">
        {{ t('consent.advanced.cssErrors') }}
      </p>
      <ul class="space-y-1">
        <li v-for="(error, i) in cssErrors" :key="i" class="flex items-start justify-between gap-2">
          <span class="font-mono text-xs">{{ error.message }}</span>
          <UButton
            v-if="error.position && !props.readonly"
            size="xs"
            color="error"
            variant="link"
            :label="t('consent.advanced.goTo', { line: error.position.line })"
            @click="goTo(error.position)"
          />
        </li>
      </ul>
    </div>

    <div class="space-y-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-medium text-highlighted">
          {{ t('consent.advanced.json') }}
        </p>
        <div class="flex flex-wrap gap-2">
          <UButton
            icon="i-tabler-clipboard"
            size="xs"
            color="neutral"
            variant="subtle"
            :label="t('common.copy')"
            data-testid="json-copy"
            @click="copyJson"
          />
          <UButton
            icon="i-tabler-download"
            size="xs"
            color="neutral"
            variant="subtle"
            :label="t('consent.advanced.exportFile')"
            data-testid="json-export"
            @click="exportFile"
          />
          <UButton
            v-if="!props.readonly"
            icon="i-tabler-upload"
            size="xs"
            color="neutral"
            variant="subtle"
            :label="t('consent.advanced.importFile')"
            data-testid="json-import-file"
            @click="fileInput?.click()"
          />
          <input
            v-if="!props.readonly"
            ref="fileInput"
            type="file"
            accept="application/json,.json"
            class="hidden"
            :aria-label="t('consent.advanced.importFile')"
            data-testid="json-file"
            @change="importFile"
          >
        </div>
      </div>
      <p class="text-xs text-muted">
        {{ t('consent.advanced.jsonHint') }}
      </p>
      <pre class="max-h-64 overflow-auto rounded-md border border-default bg-elevated/50 p-3 text-xs" data-testid="json-view">{{ json }}</pre>

      <template v-if="!props.readonly">
        <UTextarea
          v-model="importText"
          :rows="4"
          spellcheck="false"
          class="w-full font-mono text-xs"
          :placeholder="t('consent.advanced.pastePlaceholder')"
          :aria-label="t('consent.advanced.paste')"
          data-testid="json-import"
        />
        <UButton
          icon="i-tabler-file-import"
          size="xs"
          :label="t('consent.advanced.apply')"
          :disabled="!importText.trim()"
          data-testid="json-apply"
          @click="applyText"
        />
      </template>
      <UAlert
        v-if="importError"
        color="error"
        variant="subtle"
        icon="i-tabler-alert-triangle"
        :title="importError.title"
        data-testid="json-error"
      >
        <template #description>
          <ul class="list-disc ps-4 font-mono text-xs">
            <li v-for="issue in importError.issues" :key="issue">
              {{ issue }}
            </li>
          </ul>
        </template>
      </UAlert>
    </div>
  </div>
</template>
