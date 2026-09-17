<script setup lang="ts">
import type { ApiResponse, Funnel, FunnelInput, Goal } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('settings.funnels.title') })

const { data: funnels, refresh } = useAsyncData<Funnel[]>(
  () => `funnels:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/funnels'>>(`/sites/${currentSiteId.value}/funnels`)).data : [],
  { default: () => [] }
)
const { data: goals } = useAsyncData<Goal[]>(
  () => `goals:${currentSiteId.value}`,
  async () => currentSiteId.value ? (await api<ApiResponse<'/sites/{siteId}/goals'>>(`/sites/${currentSiteId.value}/goals`)).data : [],
  { default: () => [] }
)

const goalItems = computed(() => goals.value.map(g => ({ label: g.name, value: g.id })))
const scopeItems = computed(() => (['visit', 'visitor'] as const).map(value => ({ label: t(`settings.funnels.scopes.${value}`), value })))

const open = ref(false)
const editingId = ref<number | null>(null)
const state = reactive({ name: '', scope: 'visit' as 'visit' | 'visitor', window_days: 30, goal_ids: [] as (number | undefined)[] })
const saving = ref(false)

function openEditor(funnel?: Funnel) {
  editingId.value = funnel?.id ?? null
  state.name = funnel?.name ?? ''
  state.scope = funnel?.scope ?? 'visit'
  state.window_days = funnel?.window_days ?? 30
  state.goal_ids = funnel ? [...funnel.steps].sort((a, b) => a.position - b.position).map(s => s.goal_id) : [undefined, undefined]
  open.value = true
}

function move(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= state.goal_ids.length) return
  const ids = [...state.goal_ids]
  ;[ids[index], ids[target]] = [ids[target], ids[index]]
  state.goal_ids = ids
}

const valid = computed(() => state.name.trim() && state.goal_ids.length >= 2 && state.goal_ids.length <= 10 && state.goal_ids.every(id => id !== undefined))

async function save() {
  if (!currentSiteId.value || !valid.value) return
  const body: FunnelInput = { name: state.name, scope: state.scope, window_days: state.window_days, goal_ids: state.goal_ids as number[] }
  saving.value = true
  try {
    if (editingId.value) await api(`/sites/${currentSiteId.value}/funnels/${editingId.value}`, { method: 'PATCH', body })
    else await api(`/sites/${currentSiteId.value}/funnels`, { method: 'POST', body })
    open.value = false
    toast.add({ title: t('common.saved'), color: 'success' })
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    saving.value = false
  }
}

async function remove(funnel: Funnel) {
  try {
    await api(`/sites/${currentSiteId.value}/funnels/${funnel.id}`, { method: 'DELETE' })
    toast.add({ title: t('common.deleted'), color: 'success' })
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}
</script>

<template>
  <NoSiteNotice v-if="!currentSite" />
  <div v-else>
    <UPageCard
      :title="t('settings.funnels.title')"
      :description="t('settings.funnels.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="canManage"
        :label="t('settings.funnels.add')"
        icon="i-lucide-plus"
        color="neutral"
        class="w-fit lg:ms-auto"
        :disabled="goals.length < 2"
        @click="openEditor()"
      />
    </UPageCard>

    <UPageCard variant="subtle" :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }">
      <ul role="list" class="divide-y divide-default">
        <li v-for="funnel in funnels" :key="funnel.id" class="flex items-center justify-between gap-3 py-3 px-4 sm:px-6">
          <div class="min-w-0 text-sm">
            <p class="font-medium text-highlighted truncate">
              {{ funnel.name }}
            </p>
            <p class="text-muted truncate">
              {{ funnel.steps.map(s => s.goal_name).join(' → ') }}
            </p>
          </div>
          <div v-if="canManage" class="flex items-center gap-1">
            <UButton
              icon="i-lucide-pencil"
              color="neutral"
              variant="ghost"
              :aria-label="t('common.edit')"
              @click="openEditor(funnel)"
            />
            <UButton
              icon="i-lucide-trash"
              color="error"
              variant="ghost"
              :aria-label="t('common.delete')"
              @click="remove(funnel)"
            />
          </div>
        </li>
        <li v-if="!funnels.length" class="py-6 px-4 text-center text-sm text-muted">
          {{ goals.length < 2 ? t('settings.funnels.needGoals') : t('settings.funnels.empty') }}
        </li>
      </ul>
    </UPageCard>

    <UModal v-model:open="open" :title="editingId ? t('settings.funnels.edit') : t('settings.funnels.add')">
      <template #body>
        <UForm :state="state" class="space-y-4" @submit="save">
          <UFormField :label="t('common.name')" name="name" required>
            <UInput v-model="state.name" class="w-full" />
          </UFormField>
          <div class="grid grid-cols-2 gap-4">
            <UFormField :label="t('settings.funnels.scope')" name="scope">
              <USelect v-model="state.scope" :items="scopeItems" class="w-full" />
            </UFormField>
            <UFormField :label="t('settings.funnels.window')" name="window_days">
              <UInputNumber v-model="state.window_days" :min="1" :max="90" />
            </UFormField>
          </div>
          <div class="space-y-2">
            <p class="text-sm font-medium">
              {{ t('settings.funnels.steps') }}
            </p>
            <div v-for="(_, index) in state.goal_ids" :key="index" class="flex items-center gap-2">
              <span class="w-6 text-sm text-muted text-right">{{ index + 1 }}.</span>
              <USelect
                v-model="state.goal_ids[index]"
                :items="goalItems"
                :placeholder="t('settings.funnels.selectGoal')"
                class="flex-1"
              />
              <UButton
                icon="i-lucide-arrow-up"
                color="neutral"
                variant="ghost"
                :disabled="index === 0"
                :aria-label="t('common.moveUp')"
                @click="move(index, -1)"
              />
              <UButton
                icon="i-lucide-arrow-down"
                color="neutral"
                variant="ghost"
                :disabled="index === state.goal_ids.length - 1"
                :aria-label="t('common.moveDown')"
                @click="move(index, 1)"
              />
              <UButton
                icon="i-lucide-x"
                color="neutral"
                variant="ghost"
                :disabled="state.goal_ids.length <= 2"
                :aria-label="t('common.remove')"
                @click="state.goal_ids.splice(index, 1)"
              />
            </div>
            <UButton
              :label="t('settings.funnels.addStep')"
              icon="i-lucide-plus"
              size="xs"
              color="neutral"
              variant="subtle"
              :disabled="state.goal_ids.length >= 10"
              @click="state.goal_ids.push(undefined)"
            />
          </div>
          <div class="flex justify-end gap-2">
            <UButton
              :label="t('common.cancel')"
              color="neutral"
              variant="subtle"
              @click="open = false"
            />
            <UButton
              :label="t('common.save')"
              type="submit"
              :loading="saving"
              :disabled="!valid"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
