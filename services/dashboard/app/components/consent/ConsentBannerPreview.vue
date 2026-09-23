<script setup lang="ts">
import type { ApiResponse, ConsentTrackerConfig } from '~/types'
import type { ConsentDraft } from '~/utils/consentTheme'
import { MSG_ERROR, MSG_READY, MSG_RENDER, PREVIEW_PAGE, PREVIEW_VIEWPORTS, fitScale } from '~/utils/bannerPreview'
import type { PreviewDevice, PreviewView, RenderMessage } from '~/utils/bannerPreview'

/**
 * The real banner, not a re-drawing: the unsaved configuration is compiled by the server
 * (POST …/consent/preview, the same code that builds window.__an_cfg.consent) and rendered by the
 * tracker's own banner module inside a sandboxed frame (public/_preview/banner.html). The frame
 * has an opaque origin, no cookies and no network access; see ~/utils/bannerPreview.
 */
const props = withDefaults(defineProps<{
  config: ConsentDraft
  locale: string
  siteId: number
  /** The preview endpoint needs the same permission as saving the draft. */
  enabled?: boolean
  /** ms between the last edit and the preview request. */
  debounce?: number
}>(), {
  enabled: true,
  debounce: 300
})

const emit = defineEmits<{
  /** Validation errors of the current configuration ({} once it compiles again). */
  errors: [errors: Record<string, string[]>]
}>()

const device = defineModel<PreviewDevice>('device', { default: 'desktop' })
const view = ref<PreviewView>('banner')

const { t } = useI18n()
const api = useApi()

type Status = 'loading' | 'ok' | 'invalid' | 'failed' | 'unavailable'
const status = ref<Status>('loading')
const compiled = shallowRef<ConsentTrackerConfig | null>(null)
const ready = ref(false)
let sequence = 0

async function refresh() {
  if (!props.enabled) return
  const id = ++sequence
  try {
    const response = await api<ApiResponse<'/sites/{siteId}/consent/preview', 'post'>>(`/sites/${props.siteId}/consent/preview`, {
      method: 'POST',
      body: props.config,
      skipForbiddenToast: true
    })
    if (id !== sequence) return
    compiled.value = response.data
    status.value = 'ok'
    emit('errors', {})
    send()
  } catch (error) {
    if (id !== sequence) return
    if (isApiError(error) && error.isValidation) {
      status.value = 'invalid'
      emit('errors', error.errors)
    } else {
      status.value = 'failed'
    }
  }
}

const schedule = useDebounceFn(refresh, () => props.debounce)
watch(() => [props.config, props.siteId], () => {
  void schedule()
}, { deep: true })
onMounted(() => {
  void refresh()
})

/* ------------------------------------------------------------------ the frame -------------- */

const frame = useTemplateRef<HTMLIFrameElement>('frame')

function send() {
  const target = frame.value?.contentWindow
  if (!ready.value || !target || !compiled.value) return
  const message: RenderMessage = { type: MSG_RENDER, view: view.value, locale: props.locale, config: compiled.value }
  // Opaque (sandboxed) origin: "*" is the only target origin that reaches it. Plain data only.
  target.postMessage(JSON.parse(JSON.stringify(message)), '*')
}

function onMessage(event: MessageEvent) {
  if (!frame.value || event.source !== frame.value.contentWindow) return
  const type = (event.data as { type?: unknown } | null)?.type
  if (type === MSG_READY) {
    ready.value = true
    send()
  } else if (type === MSG_ERROR) {
    status.value = 'unavailable'
  }
}
useEventListener(import.meta.client ? window : undefined, 'message', onMessage)
watch([view, () => props.locale], send)

/* ------------------------------------------------------------------ viewport --------------- */

const box = useTemplateRef<HTMLElement>('box')
const { width: boxWidth } = useElementSize(box)
/** Tallest the phone frame may get on the page before it is scaled down further. */
const MOBILE_MAX_HEIGHT = 520
const viewport = computed(() => PREVIEW_VIEWPORTS[device.value])
const scale = computed(() => fitScale(viewport.value, { width: boxWidth.value, height: device.value === 'mobile' ? MOBILE_MAX_HEIGHT : 0 }))

const deviceItems = computed(() => (['desktop', 'mobile'] as const).map(value => ({
  label: t(`consent.previewUi.${value}`, PREVIEW_VIEWPORTS[value]),
  value,
  icon: value === 'desktop' ? 'i-tabler-device-desktop' : 'i-tabler-device-mobile'
})))
const viewItems = computed(() => (['banner', 'reopen'] as const).map(value => ({ label: t(`consent.previewUi.views.${value}`), value })))

defineExpose({ refresh, status, compiled })
</script>

<template>
  <div class="space-y-3" data-testid="banner-preview">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <UTabs
        v-model="device"
        :items="deviceItems"
        :content="false"
        size="xs"
        data-testid="preview-device"
      />
      <UTabs
        v-model="view"
        :items="viewItems"
        :content="false"
        size="xs"
        data-testid="preview-view"
      />
    </div>

    <UAlert
      v-if="!props.enabled"
      color="neutral"
      variant="subtle"
      icon="i-tabler-lock"
      :description="t('consent.previewUi.forbidden')"
    />
    <template v-else>
      <div ref="box" class="w-full">
        <div
          class="relative mx-auto overflow-hidden rounded-lg border border-default bg-white"
          :style="{ width: `${Math.round(viewport.width * scale)}px`, height: `${Math.round(viewport.height * scale)}px` }"
        >
          <iframe
            ref="frame"
            :src="PREVIEW_PAGE"
            sandbox="allow-scripts"
            referrerpolicy="no-referrer"
            :title="t('consent.previewUi.frameTitle')"
            class="absolute top-0 left-0 origin-top-left border-0"
            :style="{ width: `${viewport.width}px`, height: `${viewport.height}px`, transform: `scale(${scale})` }"
            :data-device="device"
            :data-view="view"
            data-testid="preview-frame"
          />
        </div>
      </div>
      <p class="text-xs text-muted">
        {{ t('consent.previewUi.hint') }}
      </p>
      <UAlert
        v-if="view === 'reopen' && !props.config.show_floating_reopen"
        color="neutral"
        variant="subtle"
        icon="i-tabler-info-circle"
        :description="t('consent.reopen.disabled')"
      />
      <UAlert
        v-if="status === 'invalid'"
        color="warning"
        variant="subtle"
        icon="i-tabler-alert-triangle"
        :description="t('consent.previewUi.invalid')"
        data-testid="preview-invalid"
      />
      <UAlert
        v-else-if="status === 'failed' || status === 'unavailable'"
        color="error"
        variant="subtle"
        icon="i-tabler-alert-triangle"
        :description="t(status === 'failed' ? 'consent.previewUi.failed' : 'consent.previewUi.unavailable')"
        data-testid="preview-failed"
      />
    </template>
  </div>
</template>
