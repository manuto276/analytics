import { createSharedComposable } from '@vueuse/core'

const _useDashboard = () => {
  const router = useRouter()
  const route = router.currentRoute
  const isJobsSlideoverOpen = ref(false)
  const isSiteModalOpen = ref(false)

  defineShortcuts({
    'g-o': () => router.push({ path: '/', query: { site: route.value.query.site } }),
    'g-r': () => router.push({ path: '/realtime', query: { site: route.value.query.site } }),
    'g-s': () => router.push({ path: '/settings', query: { site: route.value.query.site } })
  })

  watch(() => route.value.path, () => {
    isJobsSlideoverOpen.value = false
  })

  return {
    isJobsSlideoverOpen,
    isSiteModalOpen
  }
}

export const useDashboard = createSharedComposable(_useDashboard)
