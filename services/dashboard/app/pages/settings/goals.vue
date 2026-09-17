<script setup lang="ts">
import type { ApiResponse, Goal, GoalInput } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('settings.goals.title') })

const { data: goals, refresh } = useAsyncData<Goal[]>(
  () => `goals:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/goals'>>(`/sites/${currentSiteId.value}/goals`)).data : [],
  { default: () => [] }
)

type GoalType = 'pageview' | 'event' | 'conversion'
const typeItems = computed(() => (['pageview', 'event', 'conversion'] as const).map(value => ({ label: t(`settings.goals.types.${value}`), value })))

const open = ref(false)
const editingId = ref<number | null>(null)
const state = reactive({ name: '', type: 'pageview' as GoalType, path: '', eventName: '', props: [] as { key: string, value: string }[] })
const saving = ref(false)
const form = useTemplateRef('form')

function openEditor(goal?: Goal) {
  editingId.value = goal?.id ?? null
  state.name = goal?.name ?? ''
  state.type = goal?.type ?? 'pageview'
  state.path = goal?.match.path ?? ''
  state.eventName = goal?.match.name ?? ''
  state.props = Object.entries(goal?.match.props ?? {}).map(([key, value]) => ({ key, value }))
  open.value = true
}

function describe(goal: Goal) {
  if (goal.type === 'pageview') return goal.match.path ?? ''
  const props = Object.entries(goal.match.props ?? {}).map(([k, v]) => `${k}=${v}`).join(', ')
  return props ? `${goal.match.name} (${props})` : goal.match.name ?? ''
}

async function save() {
  if (!currentSiteId.value) return
  const body: GoalInput = {
    name: state.name,
    type: state.type,
    match: state.type === 'pageview'
      ? { path: state.path }
      : state.type === 'event'
        ? { name: state.eventName, props: Object.fromEntries(state.props.filter(p => p.key).map(p => [p.key, p.value])) }
        : { name: state.eventName }
  }
  saving.value = true
  try {
    if (editingId.value) {
      await api(`/sites/${currentSiteId.value}/goals/${editingId.value}`, { method: 'PATCH', body })
    } else {
      await api(`/sites/${currentSiteId.value}/goals`, { method: 'POST', body })
    }
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

async function remove(goal: Goal) {
  try {
    await api(`/sites/${currentSiteId.value}/goals/${goal.id}`, { method: 'DELETE' })
    toast.add({ title: t('common.deleted'), color: 'success' })
    await refresh()
  } catch (error) {
    const description = isApiError(error) && error.status === 409 ? t('settings.goals.inUse') : (error as Error).message
    toast.add({ title: t('errors.generic'), description, color: 'error' })
  }
}
</script>

<template>
  <NoSiteNotice v-if="!currentSite" />
  <div v-else>
    <UPageCard
      :title="t('settings.goals.title')"
      :description="t('settings.goals.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="canManage"
        :label="t('settings.goals.add')"
        icon="i-lucide-plus"
        color="neutral"
        class="w-fit lg:ms-auto"
        data-testid="add-goal"
        @click="openEditor()"
      />
    </UPageCard>

    <UPageCard variant="subtle" :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }">
      <ul role="list" class="divide-y divide-default">
        <li v-for="goal in goals" :key="goal.id" class="flex items-center justify-between gap-3 py-3 px-4 sm:px-6">
          <div class="min-w-0 text-sm">
            <p class="font-medium text-highlighted truncate">
              {{ goal.name }}
            </p>
            <p class="text-muted truncate">
              <UBadge
                color="neutral"
                variant="subtle"
                size="sm"
                :label="t(`settings.goals.types.${goal.type}`)"
              />
              {{ describe(goal) }}
            </p>
          </div>
          <div v-if="canManage" class="flex items-center gap-1" data-testid="goal-actions">
            <UButton
              icon="i-lucide-pencil"
              color="neutral"
              variant="ghost"
              :aria-label="t('common.edit')"
              @click="openEditor(goal)"
            />
            <UButton
              icon="i-lucide-trash"
              color="error"
              variant="ghost"
              :aria-label="t('common.delete')"
              @click="remove(goal)"
            />
          </div>
        </li>
        <li v-if="!goals.length" class="py-6 px-4 text-center text-sm text-muted">
          {{ t('settings.goals.empty') }}
        </li>
      </ul>
    </UPageCard>

    <UModal v-model:open="open" :title="editingId ? t('settings.goals.edit') : t('settings.goals.add')">
      <template #body>
        <UForm
          ref="form"
          :state="state"
          class="space-y-4"
          @submit="save"
        >
          <UFormField :label="t('common.name')" name="name" required>
            <UInput v-model="state.name" class="w-full" />
          </UFormField>
          <UFormField :label="t('settings.goals.type')" name="type">
            <USelect v-model="state.type" :items="typeItems" class="w-full" />
          </UFormField>
          <UFormField
            v-if="state.type === 'pageview'"
            :label="t('settings.goals.path')"
            :description="t('settings.goals.pathHint')"
            name="match.path"
            required
          >
            <UInput v-model="state.path" class="w-full font-mono" />
          </UFormField>
          <UFormField
            v-else
            :label="state.type === 'event' ? t('settings.goals.eventName') : t('settings.goals.conversionName')"
            name="match.name"
            required
          >
            <UInput v-model="state.eventName" class="w-full font-mono" />
          </UFormField>
          <div v-if="state.type === 'event'" class="space-y-2">
            <p class="text-sm font-medium">
              {{ t('settings.goals.props') }}
            </p>
            <div v-for="(prop, index) in state.props" :key="index" class="flex gap-2">
              <UInput v-model="prop.key" :placeholder="t('columns.propKey')" class="flex-1" />
              <UInput v-model="prop.value" :placeholder="t('columns.propValue')" class="flex-1" />
              <UButton
                icon="i-lucide-x"
                color="neutral"
                variant="ghost"
                :aria-label="t('common.remove')"
                @click="state.props.splice(index, 1)"
              />
            </div>
            <UButton
              :label="t('settings.goals.addProp')"
              icon="i-lucide-plus"
              size="xs"
              color="neutral"
              variant="subtle"
              @click="state.props.push({ key: '', value: '' })"
            />
          </div>
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
  </div>
</template>
