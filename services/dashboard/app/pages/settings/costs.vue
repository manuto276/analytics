<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiResponse, CampaignCost, CampaignCostInput, CostImportResult } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('settings.costs.title') })

const { data: costs, refresh, status } = useAsyncData<CampaignCost[]>(
  () => `costs:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/costs'>>(`/sites/${currentSiteId.value}/costs`)).data : [],
  { default: () => [] }
)

const UButton = resolveComponent('UButton')

const open = ref(false)
const importOpen = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const form = useTemplateRef('form')
const state = reactive({
  day_from: '',
  day_to: '',
  channel: '',
  utm_source: '',
  utm_medium: '',
  utm_campaign: '',
  amount: '',
  currency: '',
  note: ''
})

function openEditor(cost?: CampaignCost) {
  editingId.value = cost?.id ?? null
  const currency = cost?.currency ?? currentSite.value?.currency ?? 'EUR'
  Object.assign(state, {
    day_from: cost?.day_from ?? todayIn(currentSite.value?.timezone),
    day_to: cost?.day_to ?? todayIn(currentSite.value?.timezone),
    channel: cost?.channel ?? '',
    utm_source: cost?.utm_source ?? '',
    utm_medium: cost?.utm_medium ?? '',
    utm_campaign: cost?.utm_campaign ?? '',
    amount: cost ? String(fromMinorUnits(cost.amount_minor, currency)) : '',
    currency,
    note: cost?.note ?? ''
  })
  open.value = true
}

async function save() {
  if (!currentSiteId.value) return
  const amountMinor = toMinorUnits(state.amount, state.currency)
  if (!Number.isFinite(amountMinor) || amountMinor < 0) {
    form.value?.setErrors([{ name: 'amount_minor', message: t('validation.amount') }])
    return
  }
  const body: CampaignCostInput = {
    day_from: state.day_from,
    day_to: state.day_to || state.day_from,
    channel: state.channel || null,
    utm_source: state.utm_source || null,
    utm_medium: state.utm_medium || null,
    utm_campaign: state.utm_campaign || null,
    amount_minor: amountMinor,
    currency: state.currency.toUpperCase(),
    note: state.note || null
  }
  saving.value = true
  try {
    if (editingId.value) await api(`/sites/${currentSiteId.value}/costs/${editingId.value}`, { method: 'PATCH', body })
    else await api(`/sites/${currentSiteId.value}/costs`, { method: 'POST', body })
    open.value = false
    toast.add({ title: t('common.saved'), color: 'success' })
    await refresh()
  } catch (error) {
    if (isApiError(error) && error.isValidation) form.value?.setErrors(error.fieldErrors())
    else toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    saving.value = false
  }
}

async function remove(cost: CampaignCost) {
  try {
    await api(`/sites/${currentSiteId.value}/costs/${cost.id}`, { method: 'DELETE' })
    toast.add({ title: t('common.deleted'), color: 'success' })
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

async function onImported(result: CostImportResult) {
  importOpen.value = false
  toast.add({ title: t('settings.costs.imported', { n: result.valid_rows }), color: 'success' })
  await refresh()
}

const columns = computed<TableColumn<CampaignCost>[]>(() => [
  { accessorKey: 'day_from', header: t('settings.costs.days'), cell: ({ row }) => row.original.day_from === row.original.day_to ? fmt.day(row.original.day_from) : `${fmt.day(row.original.day_from)} – ${fmt.day(row.original.day_to)}` },
  { accessorKey: 'channel', header: t('columns.channel'), cell: ({ row }) => row.original.channel ?? EMPTY_VALUE },
  { accessorKey: 'utm_campaign', header: t('settings.costs.target'), cell: ({ row }) => [row.original.utm_source, row.original.utm_medium, row.original.utm_campaign].filter(Boolean).join(' / ') || EMPTY_VALUE },
  { accessorKey: 'amount_minor', header: t('settings.costs.amount'), cell: ({ row }) => fmt.money(row.original.amount_minor, row.original.currency) },
  { accessorKey: 'note', header: t('settings.costs.note'), cell: ({ row }) => row.original.note ?? '' },
  {
    id: 'actions',
    cell: ({ row }) => canManage.value
      ? h('div', { class: 'flex justify-end gap-1' }, [
          h(UButton, { 'icon': 'i-tabler-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': t('common.edit'), 'onClick': () => openEditor(row.original) }),
          h(UButton, { 'icon': 'i-tabler-trash', 'color': 'error', 'variant': 'ghost', 'aria-label': t('common.delete'), 'onClick': () => remove(row.original) })
        ])
      : null
  }
])
</script>

<template>
  <NoSiteNotice v-if="!currentSite" />
  <div v-else>
    <UPageCard
      :title="t('settings.costs.title')"
      :description="t('settings.costs.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <div v-if="canManage" class="flex gap-2 lg:ms-auto">
        <UButton
          :label="t('settings.costs.import')"
          icon="i-tabler-upload"
          color="neutral"
          variant="subtle"
          @click="importOpen = true"
        />
        <UButton
          :label="t('settings.costs.add')"
          icon="i-tabler-plus"
          color="neutral"
          @click="openEditor()"
        />
      </div>
    </UPageCard>

    <UTable
      :data="costs"
      :columns="columns"
      :loading="status === 'pending'"
      :empty="t('settings.costs.empty')"
      :ui="{
        base: 'table-fixed border-separate border-spacing-0',
        thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
        tbody: '[&>tr]:last:[&>td]:border-b-0',
        th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
        td: 'border-b border-default'
      }"
    />

    <UModal v-model:open="open" :title="editingId ? t('settings.costs.edit') : t('settings.costs.add')">
      <template #body>
        <UForm
          ref="form"
          :state="state"
          class="space-y-4"
          @submit="save"
        >
          <div class="grid grid-cols-2 gap-4">
            <UFormField :label="t('settings.costs.dayFrom')" name="day_from" required>
              <UInput v-model="state.day_from" type="date" class="w-full" />
            </UFormField>
            <UFormField :label="t('settings.costs.dayTo')" name="day_to">
              <UInput v-model="state.day_to" type="date" class="w-full" />
            </UFormField>
            <UFormField :label="t('columns.channel')" name="channel">
              <UInput v-model="state.channel" class="w-full" />
            </UFormField>
            <UFormField :label="t('columns.utm_source')" name="utm_source">
              <UInput v-model="state.utm_source" class="w-full" />
            </UFormField>
            <UFormField :label="t('columns.utm_medium')" name="utm_medium">
              <UInput v-model="state.utm_medium" class="w-full" />
            </UFormField>
            <UFormField :label="t('columns.utm_campaign')" name="utm_campaign">
              <UInput v-model="state.utm_campaign" class="w-full" />
            </UFormField>
            <UFormField :label="t('settings.costs.amount')" name="amount_minor" required>
              <UInput v-model="state.amount" inputmode="decimal" class="w-full" />
            </UFormField>
            <UFormField :label="t('site.currency')" name="currency" required>
              <UInput v-model="state.currency" maxlength="3" class="w-full uppercase" />
            </UFormField>
          </div>
          <UFormField :label="t('settings.costs.note')" name="note">
            <UInput v-model="state.note" class="w-full" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              :label="t('common.cancel')"
              color="neutral"
              variant="subtle"
              @click="open = false"
            />
            <UButton :label="t('common.save')" type="submit" :loading="saving" />
          </div>
        </UForm>
      </template>
    </UModal>

    <UModal v-model:open="importOpen" :title="t('settings.costs.import')" :ui="{ content: 'sm:max-w-3xl' }">
      <template #body>
        <CostsCostImport v-if="currentSiteId" :site-id="currentSiteId" @imported="onImported" />
      </template>
    </UModal>
  </div>
</template>
