<script setup lang="ts">
import type { ApiResponse, Invitation, Member, SiteRole } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
const { user } = useAuth()
const { currentSite, currentSiteId, canManage } = useSites()
useHead({ title: () => t('settings.members.title') })

const { data: members, refresh } = useAsyncData<Member[]>(
  () => `members:${currentSiteId.value}`,
  async () => currentSiteId.value && canManage.value
    ? (await api<ApiResponse<'/sites/{siteId}/members'>>(`/sites/${currentSiteId.value}/members`)).data
    : [],
  { default: () => [] }
)

const { data: invitations, refresh: refreshInvitations } = useAsyncData<Invitation[]>(
  () => `site-invitations:${currentSiteId.value}`,
  async () => currentSiteId.value && canManage.value
    ? (await api<ApiResponse<'/sites/{siteId}/invitations'>>(`/sites/${currentSiteId.value}/invitations`)).data
    : [],
  { default: () => [] }
)

const pendingInvitations = computed(() => invitations.value.filter(i => i.status === 'pending'))

async function revokeInvitation(invitation: Invitation) {
  try {
    await api(`/sites/${currentSiteId.value}/invitations/${invitation.id}`, { method: 'DELETE' })
    toast.add({ title: t('settings.members.invitationRevoked'), color: 'success' })
    await refreshInvitations()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

const q = ref('')
const filtered = computed(() => {
  const needle = q.value.trim().toLowerCase()
  if (!needle) return members.value
  return members.value.filter(m => m.display_name.toLowerCase().includes(needle) || m.email.toLowerCase().includes(needle))
})

const roleItems = computed(() => (['admin', 'viewer'] as const).map(value => ({ label: t(`roles.site.${value}`), value })))

const inviteOpen = ref(false)
const inviteMode = ref<'invite' | 'existing'>('invite')
const inviteState = reactive({ email: '', role: 'viewer' as SiteRole })
const inviteLink = ref<string | null>(null)
const inviting = ref(false)

function openInvite(mode: 'invite' | 'existing') {
  inviteMode.value = mode
  inviteState.email = ''
  inviteState.role = 'viewer'
  inviteLink.value = null
  inviteOpen.value = true
}

async function submitInvite() {
  if (!currentSiteId.value) return
  inviting.value = true
  try {
    if (inviteMode.value === 'invite') {
      const res = await api<ApiResponse<'/sites/{siteId}/invitations', 'post'>>(`/sites/${currentSiteId.value}/invitations`, { method: 'POST', body: { ...inviteState } })
      inviteLink.value = res.data.link
      await refreshInvitations()
    } else {
      const res = await api<ApiResponse<'/sites/{siteId}/members', 'post'>>(`/sites/${currentSiteId.value}/members`, { method: 'POST', body: { ...inviteState } })
      members.value = res.data
      inviteOpen.value = false
      toast.add({ title: t('settings.members.added'), color: 'success' })
    }
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  } finally {
    inviting.value = false
  }
}

async function changeRole(member: Member, role: SiteRole) {
  try {
    const res = await api<ApiResponse<'/sites/{siteId}/members/{userId}', 'put'>>(`/sites/${currentSiteId.value}/members/${member.user_id}`, { method: 'PUT', body: { role } })
    members.value = res.data
    toast.add({ title: t('common.saved'), color: 'success' })
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
    await refresh()
  }
}

async function remove(member: Member) {
  try {
    await api(`/sites/${currentSiteId.value}/members/${member.user_id}`, { method: 'DELETE' })
    members.value = members.value.filter(m => m.user_id !== member.user_id)
    toast.add({ title: t('settings.members.removed'), color: 'success' })
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}
</script>

<template>
  <NoSiteNotice v-if="!currentSite" />
  <UAlert
    v-else-if="!canManage"
    color="neutral"
    variant="subtle"
    icon="i-tabler-lock"
    :title="t('errors.adminOnly')"
  />
  <div v-else>
    <UPageCard
      :title="t('settings.members.title')"
      :description="t('settings.members.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <div class="flex gap-2 lg:ms-auto">
        <UButton
          :label="t('settings.members.addExisting')"
          color="neutral"
          variant="subtle"
          @click="openInvite('existing')"
        />
        <UButton :label="t('settings.members.invite')" color="neutral" @click="openInvite('invite')" />
      </div>
    </UPageCard>

    <UPageCard variant="subtle" :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch', header: 'p-4 mb-0 border-b border-default' }">
      <template #header>
        <UInput
          v-model="q"
          icon="i-tabler-search"
          :placeholder="t('settings.members.search')"
          class="w-full"
        />
      </template>

      <ul role="list" class="divide-y divide-default">
        <li
          v-for="member in filtered"
          :key="member.user_id"
          class="flex items-center justify-between gap-3 py-3 px-4 sm:px-6"
        >
          <div class="flex items-center gap-3 min-w-0">
            <UAvatar :alt="member.display_name" size="md" />
            <div class="text-sm min-w-0">
              <p class="text-highlighted font-medium truncate">
                {{ member.display_name }}
                <UBadge
                  v-if="member.status !== 'active'"
                  color="neutral"
                  variant="subtle"
                  size="sm"
                  :label="t(`users.status.${member.status}`)"
                />
              </p>
              <p class="text-muted truncate">
                {{ member.email }}
              </p>
            </div>
          </div>

          <div class="flex items-center gap-3">
            <UBadge
              v-if="member.inherited"
              color="primary"
              variant="subtle"
              :label="t('settings.members.inherited')"
            />
            <USelect
              v-else
              :model-value="member.role"
              :items="roleItems"
              color="neutral"
              :disabled="member.user_id === user?.id"
              @update:model-value="(role: SiteRole) => changeRole(member, role)"
            />
            <UButton
              v-if="!member.inherited && member.user_id !== user?.id"
              icon="i-tabler-user-minus"
              color="error"
              variant="ghost"
              :aria-label="t('settings.members.remove')"
              @click="remove(member)"
            />
          </div>
        </li>
      </ul>
    </UPageCard>

    <UPageCard
      v-if="pendingInvitations.length"
      :title="t('settings.members.pendingInvitations')"
      variant="subtle"
      class="mt-4"
      :ui="{ container: 'gap-y-2' }"
    >
      <ul class="divide-y divide-default text-sm" data-testid="site-invitations">
        <li v-for="invitation in pendingInvitations" :key="invitation.id" class="py-2 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="text-highlighted truncate">
              {{ invitation.email }}
            </p>
            <p class="text-xs text-muted">
              {{ t('admin.users.expires', { when: fmt.dateTime(invitation.expires_at) }) }}
            </p>
          </div>
          <UButton
            :label="t('admin.users.revoke')"
            size="xs"
            color="error"
            variant="ghost"
            data-testid="revoke-invitation"
            @click="revokeInvitation(invitation)"
          />
        </li>
      </ul>
    </UPageCard>

    <UModal
      v-model:open="inviteOpen"
      :title="inviteMode === 'invite' ? t('settings.members.invite') : t('settings.members.addExisting')"
      :description="inviteMode === 'invite' ? t('settings.members.inviteDescription') : t('settings.members.addExistingDescription')"
    >
      <template #body>
        <div v-if="inviteLink" class="space-y-3">
          <UAlert
            color="success"
            variant="subtle"
            icon="i-tabler-mail-check"
            :title="t('settings.members.linkReady')"
            :description="t('settings.members.linkHint')"
          />
          <CopyField :value="inviteLink" :label="t('settings.members.link')" />
        </div>
        <UForm
          v-else
          :state="inviteState"
          class="space-y-4"
          @submit="submitInvite"
        >
          <UFormField :label="t('common.email')" name="email" required>
            <UInput
              v-model="inviteState.email"
              type="email"
              class="w-full"
              autofocus
            />
          </UFormField>
          <UFormField :label="t('common.role')" name="role">
            <USelect v-model="inviteState.role" :items="roleItems" class="w-full" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              :label="t('common.cancel')"
              color="neutral"
              variant="subtle"
              @click="inviteOpen = false"
            />
            <UButton :label="inviteMode === 'invite' ? t('settings.members.sendInvite') : t('common.add')" type="submit" :loading="inviting" />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
