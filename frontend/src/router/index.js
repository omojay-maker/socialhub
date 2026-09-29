import { createRouter, createWebHistory, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('../views/Login.vue'), meta: { guest: true } },
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: () => import('../views/Dashboard.vue'), meta: { auth: true } },
  { path: '/posts', name: 'posts', component: () => import('../views/Posts.vue'), meta: { auth: true } },
  { path: '/create', name: 'create', component: () => import('../views/CreatePost.vue'), meta: { auth: true } },
  { path: '/calendar', name: 'calendar', component: () => import('../views/Calendar.vue'), meta: { auth: true } },
  { path: '/notifications', name: 'notifications', component: () => import('../views/Notifications.vue'), meta: { auth: true } },
  { path: '/analytics', name: 'analytics', component: () => import('../views/Analytics.vue'), meta: { auth: true } },
  { path: '/accounts', name: 'accounts', component: () => import('../views/Accounts.vue'), meta: { auth: true } },
  { path: '/media', name: 'media', component: () => import('../views/Media.vue'), meta: { auth: true } },
  { path: '/users', name: 'users', component: () => import('../views/Users.vue'), meta: { auth: true, admin: true } },
  { path: '/logs', name: 'logs', component: () => import('../views/Logs.vue'), meta: { auth: true } },
]

const router = createRouter({
  // Use hash history to avoid Apache rewrite requirement; change to createWebHistory if rewrite enabled
  history: createWebHashHistory(),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()
  if (auth.user === null && !auth.loading) {
    // try init once
    auth.loading = true
    await auth.init()
    auth.loading = false
  }
  if (to.meta.auth && !auth.isAuth) return next('/login')
  if (to.meta.guest && auth.isAuth) return next('/dashboard')
  if (to.meta.admin && !auth.isAdmin) return next('/dashboard')
  next()
})

export default router
