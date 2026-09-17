<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ReportRow } from '~/types'
import type { ReportParams } from '~/composables/useReport'
import type { ReportColumn } from '~/utils/reportColumns'

const props = withDefaults(defineProps<{
  report: string
  columns: ReportColumn[]
  params?: ReportParams
  title?: string
  limit?: number
  paginate?: boolean
  csv?: boolean
  selectable?: boolean
  compare?: boolean
}>(), {
  params: () => ({}),
  title: undefined,
  limit: 50,
  paginate: true,
  csv: true,
  selectable: false,
  compare: false
})

const emit = defineEmits<{
  select: [row: ReportRow]
}>()

const { t } = useI18n()
const toast = useToast()
const fmt = useFormatters()
const { addFilter } = useReportQuery()

const UButton = resolveComponent('UButton')

const sort = ref<string | undefined>(undefined)
const requestParams = computed<ReportParams>(() => ({ ...props.params, limit: props.limit, sort: sort.value }))

const report = useReport(() => props.report, requestParams, { compare: props.compare })
const { rows, loading, availability, nextCursor, loadingMore } = report

const missing = computed(() => unavailableMetrics(props.columns, availability.value))
const totalRows = computed(() => (report.data.value as { data?: { total_rows?: number } } | null)?.data?.total_rows ?? rows.value.length)

function sortIcon(key: string) {
  if (sort.value === `-${key}`) return 'i-lucide-arrow-down-wide-narrow'
  if (sort.value === key) return 'i-lucide-arrow-up-narrow-wide'
  return 'i-lucide-arrow-up-down'
}

const tableColumns = computed<TableColumn<ReportRow>[]>(() => props.columns.map((column) => {
  const label = t(column.label)
  const numeric = column.kind && !['text', 'page', 'country'].includes(column.kind)
  return {
    id: column.key,
    accessorKey: column.key,
    header: () => column.sortable
      ? h(UButton, {
          color: 'neutral',
          variant: 'ghost',
          label,
          icon: sortIcon(column.key),
          class: numeric ? '-me-2.5 ms-auto' : '-mx-2.5',
          onClick: () => {
            sort.value = nextSort(sort.value, column.key)
          }
        })
      : h('span', { class: numeric ? 'block text-right' : undefined }, label),
    cell: ({ row }) => {
      const available = !column.requires || availability.value[column.requires]
      const text = formatCell(row.original, column, fmt, available)
      const raw = row.original[column.key]
      if (column.filter && raw !== null && raw !== undefined && raw !== '') {
        return h('button', {
          'type': 'button',
          'class': 'text-left text-highlighted hover:text-primary hover:underline truncate max-w-md',
          'title': t('filters.add'),
          'data-testid': 'filter-value',
          'onClick': (e: Event) => {
            e.stopPropagation()
            void addFilter(column.filter!, 'is', String(raw))
          }
        }, text)
      }
      return h('span', { class: numeric ? 'block text-right tabular-nums' : 'truncate' }, text)
    }
  }
}))

async function exportCsv() {
  try {
    await report.downloadCsv()
  } catch (error) {
    toast.add({ title: t('errors.csv'), description: (error as Error).message, color: 'error' })
  }
}

function onSelect(_e: Event, row: { original: ReportRow }) {
  if (props.selectable) emit('select', row.original)
}

defineExpose({ refresh: report.refresh })
</script>

<template>
  <UCard :ui="{ body: 'p-0 sm:p-0', header: 'px-4 py-3 sm:px-4' }" class="shrink-0">
    <template #header>
      <div class="flex items-center justify-between gap-2 min-h-8">
        <div class="flex items-center gap-2 min-w-0">
          <p v-if="title" class="text-sm font-medium text-highlighted truncate">
            {{ title }}
          </p>
          <slot name="header" />
        </div>
        <UButton
          v-if="csv"
          icon="i-lucide-download"
          :label="t('report.csv')"
          size="xs"
          color="neutral"
          variant="ghost"
          data-testid="csv-export"
          @click="exportCsv"
        />
      </div>
    </template>

    <div v-if="missing.length" class="p-3 border-b border-default">
      <ReportAvailabilityNotice :missing="missing" />
    </div>

    <UTable
      :data="rows"
      :columns="tableColumns"
      :loading="loading"
      :empty="t('report.empty')"
      :on-select="selectable ? onSelect : undefined"
      :ui="{
        base: 'table-fixed',
        thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
        th: 'py-2',
        td: 'py-2 border-b border-default',
        tr: selectable ? 'cursor-pointer' : ''
      }"
    />

    <div v-if="paginate && (nextCursor || rows.length)" class="flex items-center justify-between gap-2 px-4 py-2 text-xs text-muted">
      <span>{{ t('report.showing', { shown: rows.length, total: totalRows }) }}</span>
      <UButton
        v-if="nextCursor"
        :label="t('report.loadMore')"
        size="xs"
        color="neutral"
        variant="subtle"
        :loading="loadingMore"
        data-testid="load-more"
        @click="report.loadMore()"
      />
    </div>
  </UCard>
</template>
