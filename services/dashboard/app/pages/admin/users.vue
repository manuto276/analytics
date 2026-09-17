<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiResponse, GlobalRole, Invitation, User } from '~/types'

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const fmt = useFormatters()
const { user: me } = useAuth()
const { sites } = useSites()
useHead({ title: () => t('nav.users') })

const USelect = resolveComponent('USelect')
const UButton = resolveComponent('UButton')
const UBadge = resolveComponent('UBadge')

const { data: users, refresh, status } = useAsyncData<User[]>('admin:users', async () => (await api<ApiResponse<'/users'>>('/users')).data, { default: () => [] })
const { data: invitations, refresh: refreshInvitations } = useAsyncData<Invitation[]>('admin:invitations', async () => (await api<ApiResponse<'/invitations'>>('/invitations')).data, { default: () => [] })

const q = ref('')
const filtered = computed(() => {
  const needle = q.value.toLowerCase().trim()
  return needle ? users.value.filter(u => u.email.toLowerCase().includes(needle) || u.display_name.toLowerCase().includes(needle)) : users.value
})

async function patch(user: User, body: { global_role?: GlobalRole, status?: 'active' | 'disabled' }) {
  try {
    const res = await api<ApiResponse<'/users/{userId}', 'patch'>>(`/users/${user.id}`, { method: 'PATCH', body })
    users.value = users.value.map(u => (u.id === user.id ? res.data : u))
    toast.add({ title: t('common.saved'), color: 'success' })
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
    await refresh()
  }
}

async function resetTotp(user: User) {
  try {
    await api(`/users/${user.id}/totp`, { method: 'DELETE' })
    toast.add({ title: t('admin.users.totpReset'), color: 'success' })
    await refresh()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

async function revokeInvitation(invitation: Invitation) {
  try {
    await api(`/invitations/${invitation.id}`, { method: 'DELETE' })
    await refreshInvitations()
  } catch (error) {
    toast.add({ title: t('errors.generic'), description: (error as Error).message, color: 'error' })
  }
}

function siteName(id: number) {
  return sites.value.find(s => s.id === id)?.name ?? `#${id}`
}

const roleItems = computed(() => (['member', 'admin'] as const).map(value => ({ label: t(`roles.global.${value}`), value })))
const statusItems = computed(() => (['active', 'disabled'] as const).map(value => ({ label: t(`users.status.${value}`), value })))

const columns = computed<TableColumn<User>[]>(() => [
  {
    accessorKey: 'display_name',
    header: t('common.name'),
    cell: ({ row }) => h('div', { class: 'min-w-0' }, [
      h('p', { class: 'font-medium text-highlighted truncate' }, row.original.display_name),
      h('p', { class: 'text-muted truncate' }, row.original.email)
    ])
  },
  {
    accessorKey: 'global_role',
    header: t('admin.users.globalRole'),
    cell: ({ row }) => h(USelect, {
      'modelValue': row.original.global_role,
      'items': roleItems.value,
      'disabled': row.original.id === me.value?.id,
      'onUpdate:modelValue': (value: GlobalRole) => patch(row.original, { global_role: value })
    })
  },
  {
    accessorKey: 'status',
    header: t('admin.users.status'),
    cell: ({ row }) => h(USelect, {
      'modelValue': row.original.status,
      'items': statusItems.value,
      'disabled': row.original.id === me.value?.id,
      'onUpdate:modelValue': (value: 'active' | 'disabled') => patch(row.original, { status: value })
    })
  },
  {
    accessorKey: 'site_roles',
    header: t('admin.users.sites'),
    cell: ({ row }) => row.original.global_role === 'admin'
      ? t('admin.users.allSites')
      : row.original.site_roles.map(r => `${siteName(r.site_id)} (${t(`roles.site.${r.role}`)})`).join(', ') || EMPTY_VALUE
  },
  {
    accessorKey: 'mfa_enabled',
    header: t('admin.users.mfa'),
    cell: ({ row }) => h(UBadge, { color: row.original.mfa_enabled ? 'success' : 'neutral', variant: 'subtle', label: row.original.mfa_enabled ? t('common.on') : t('common.off') })
  },
  {
    accessorKey: 'last_login_at',
    header: t('admin.users.lastLogin'),
    cell: ({ row }) => fmt.dateTime(row.original.last_login_at)
  },
  {
    id: 'actions',
    cell: ({ row }) => row.original.mfa_enabled && row.original.id !== me.value?.id
      ? h(UButton, { label: t('admin.users.resetTotp'), size: 'xs', color: 'warning', variant: 'ghost', onClick: () => resetTotp(row.original) })
      : null
  }
])

const inviteOpen = ref(false)
const pendingInvitations = computed(() => invitations.value.filter(i => i.status === 'pending'))
</script>

<template>
  <UDashboardPanel id="admin-users">
    <template #header>
      <PageNavbar :title="t('nav.users')">
        <template #right>
          <UButton :label="t('admin.users.invite')" icon="i-lucide-user-plus" @click="inviteOpen = true" />
        </template>
      </PageNavbar>
    </template>

    <template #body>
      <UInput
        v-model="q"
        icon="i-lucide-search"
        :placeholder="t('admin.users.search')"
        class="max-w-sm"
      />

      <UTable
        :data="filtered"
        :columns="columns"
        :loading="status === 'pending'"
        class="shrink-0"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default'
        }"
      />

      <UPageCard :title="t('admin.users.invitations')" variant="subtle" :ui="{ container: 'gap-y-2' }">
        <ul class="divide-y divide-default text-sm">
          <li v-for="inv in invitations" :key="inv.id" class="py-2 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="text-highlighted truncate">
                {{ inv.email }}
              </p>
              <p class="text-xs text-muted">
                {{ t(`roles.global.${inv.global_role}`) }} · {{ t('admin.users.expires', { when: fmt.dateTime(inv.expires_at) }) }}
              </p>
            </div>
            <div class="flex items-center gap-2">
              <UBadge :color="inv.status === 'pending' ? 'warning' : 'neutral'" variant="subtle" :label="t(`admin.users.invitationStatus.${inv.status}`)" />
              <UButton
                v-if="inv.status === 'pending'"
                :label="t('admin.users.revoke')"
                size="xs"
                color="error"
                variant="ghost"
                @click="revokeInvitation(inv)"
              />
            </div>
          </li>
          <li v-if="!invitations.length" class="py-2 text-muted">
            {{ t('admin.users.noInvitations') }}
          </li>
        </ul>
        <p v-if="pendingInvitations.length" class="text-xs text-muted">
          {{ t('admin.users.pendingCount', { n: pendingInvitations.length }) }}
        </p>
      </UPageCard>

      <AdminInvitationModal v-model:open="inviteOpen" @created="refreshInvitations" />
    </template>
  </UDashboardPanel>
</template>
