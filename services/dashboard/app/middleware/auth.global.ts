export default defineNuxtRouteMiddleware(async (to) => {
  const { ensureLoaded, isGlobalAdmin } = useAuth()
  const user = await ensureLoaded()

  if (isPublicRoute(to.path)) {
    if (user && to.path.startsWith('/login')) {
      return navigateTo('/')
    }
    return
  }

  if (!user) {
    return navigateTo({ path: '/login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} })
  }

  if (to.path.startsWith('/admin') && !isGlobalAdmin.value) {
    return navigateTo('/')
  }
})
