import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { guestOnly: true },
  },
  // ── viewer + admin ─────────────────────────────────────────────
  {
    path: '/',
    name: 'candidates',
    component: () => import('../views/CandidatesView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/overnight',
    name: 'overnight',
    component: () => import('../views/OvernightView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/stock/:id',
    name: 'stock-detail',
    component: () => import('../views/StockDetailView.vue'),
    meta: { requiresAuth: true },
  },
  // ── admin only ─────────────────────────────────────────────────
  {
    path: '/stats',
    name: 'stats',
    component: () => import('../views/StatsView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/overnight/stats',
    name: 'overnight-stats',
    component: () => import('../views/OvernightStatsView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/news',
    name: 'news',
    component: () => import('../views/NewsView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('../views/SettingsView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/spec',
    name: 'spec',
    component: () => import('../views/SpecView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/users',
    name: 'users',
    component: () => import('../views/UsersView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const authStore = useAuthStore()

  // 有 token 但 user 尚未 hydrate（頁面重整）
  if (authStore.token && !authStore.user) {
    await authStore.fetchMe()
  }

  const isAuthenticated = authStore.isAuthenticated
  const isAdmin = authStore.isAdmin

  // 已登入者不需要看 login 頁
  if (to.meta.guestOnly && isAuthenticated) {
    return { name: 'candidates' }
  }

  // 未登入 → login
  if (to.meta.requiresAuth && !isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // viewer 嘗試進 admin 頁面 → 靜默導回首頁
  if (to.meta.requiresAdmin && !isAdmin) {
    return { name: 'candidates' }
  }
})

export default router
