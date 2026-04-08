import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'candidates',
    component: () => import('../views/CandidatesView.vue'),
  },
  {
    path: '/history',
    name: 'history',
    component: () => import('../views/HistoryView.vue'),
  },
  {
    path: '/stats',
    name: 'stats',
    component: () => import('../views/StatsView.vue'),
  },
  {
    path: '/news',
    name: 'news',
    component: () => import('../views/NewsView.vue'),
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('../views/SettingsView.vue'),
  },
  {
    path: '/spec',
    name: 'spec',
    component: () => import('../views/SpecView.vue'),
  },
  {
    path: '/stock/:id',
    name: 'stock-detail',
    component: () => import('../views/StockDetailView.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
