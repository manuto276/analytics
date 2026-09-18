<script setup lang="ts">
import type { ApiResponse, GlobalRole, SiteRole } from '~/types'

const emit = defineEmits<{
  created: []
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const api = useApi()
const { sites } = useSites()

const state = reactive({
  email: '',
  global_role: 'member' as GlobalRole,
  site_roles: [] as { site_id: number | undefined, role: SiteRole }[]
})
const link = ref<string | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)

watch(open, (value) => {
  if (value) {
    Object.assign(state, { email: '', global_role: 'member', site_roles: [] })
    link.value = null
    error.value = null
  }
})

const globalRoleItems = computed(() => (['member', 'admin'] as const).map(value => ({ label: t(`roles.global.${value}`), value })))
const siteRoleItems = computed(() => (['viewer', 'admin'] as const).map(value => ({ label: t(`roles.site.${value}`), value })))
const siteItems = computed(() => sites.value.filter(s => !s.archived).map(s => ({ label: s.name, value: s.id })))

async function submit() {
  saving.value = true
  error.value = null
  try {
    const res = await api<ApiResponse<'/invitations', 'post'>>('/invitations', {
      method: 'POST',
      body: {
        email: state.email,
        global_role: state.global_role,
        site_roles: state.global_role === 'admin' ? [] : state.site_roles.filter(r => r.site_id).map(r => ({ site_id: r.site_id!, role: r.role }))
      }
    })
    link.value = res.data.link
    emit('created')
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="t('admin.users.invite')" :description="t('admin.users.inviteDescription')">
    <template #body>
      <div v-if="link" class="space-y-3">
        <UAlert
          color="success"
          variant="subtle"
          icon="i-tabler-mail-check"
          :title="t('settings.members.linkReady')"
          :description="t('settings.members.linkHint')"
        />
        <CopyField :value="link" :label="t('settings.members.link')" />
      </div>
      <UForm
        v-else
        :state="state"
        class="space-y-4"
        @submit="submit"
      >
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :title="error"
        />
        <UFormField :label="t('common.email')" name="email" required>
          <UInput v-model="state.email" type="email" class="w-full" />
        </UFormField>
        <UFormField :label="t('admin.users.globalRole')" name="global_role">
          <USelect v-model="state.global_role" :items="globalRoleItems" class="w-full" />
        </UFormField>
        <div v-if="state.global_role !== 'admin'" class="space-y-2">
          <p class="text-sm font-medium">
            {{ t('admin.users.siteAccess') }}
          </p>
          <div v-for="(row, index) in state.site_roles" :key="index" class="flex gap-2">
            <USelect
              v-model="row.site_id"
              :items="siteItems"
              :placeholder="t('admin.users.selectSite')"
              class="flex-1"
            />
            <USelect v-model="row.role" :items="siteRoleItems" class="w-32" />
            <UButton
              icon="i-tabler-x"
              color="neutral"
              variant="ghost"
              :aria-label="t('common.remove')"
              @click="state.site_roles.splice(index, 1)"
            />
          </div>
          <UButton
            :label="t('admin.users.addSiteAccess')"
            icon="i-tabler-plus"
            size="xs"
            color="neutral"
            variant="subtle"
            @click="state.site_roles.push({ site_id: undefined, role: 'viewer' })"
          />
        </div>
        <div class="flex justify-end gap-2">
          <UButton
            :label="t('common.cancel')"
            color="neutral"
            variant="subtle"
            @click="open = false"
          />
          <UButton :label="t('settings.members.sendInvite')" type="submit" :loading="saving" />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
