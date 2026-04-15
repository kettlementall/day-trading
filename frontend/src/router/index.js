import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'candidates',
    component: () => import('../views/CandidatesView.vue'),
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
    path: '/overnight',
    name: 'overnight',
    component: () => import('../views/OvernightView.vue'),
  },
  {
    path: '/overnight/review',
    name: 'overnight-review',
    component: () => import('../views/OvernightReviewView.vue'),
  },
  {
    path: '/overnight/tip',
    name: 'overnight-tip',
    component: () => import('../views/OvernightTipView.vue'),
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
