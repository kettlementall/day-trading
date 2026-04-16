import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

const TOKEN_KEY = 'auth_token'

export const useAuthStore = defineStore('auth', () => {
  const token   = ref(localStorage.getItem(TOKEN_KEY) || null)
  const user    = ref(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const isAdmin         = computed(() => user.value?.role === 'admin')
  const isViewer        = computed(() => user.value?.role === 'viewer')

  // 初始化：若 localStorage 有 token，立即注入 axios header
  if (token.value) {
    api.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
  }

  async function login(email, password) {
    loading.value = true
    try {
      const { data } = await api.post('/auth/login', { email, password })
      token.value = data.token
      user.value  = data.user
      localStorage.setItem(TOKEN_KEY, data.token)
      api.defaults.headers.common['Authorization'] = `Bearer ${data.token}`
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    if (!token.value) return
    try {
      const { data } = await api.get('/auth/me')
      user.value = data
    } catch {
      // token 過期或被撤銷
      _clearAuth()
    }
  }

  async function logout() {
    try {
      if (token.value) {
        await api.post('/auth/logout')
      }
    } catch {
      // 忽略網路錯誤，繼續清除本地狀態
    } finally {
      _clearAuth()
    }
  }

  function _clearAuth() {
    token.value = null
    user.value  = null
    localStorage.removeItem(TOKEN_KEY)
    delete api.defaults.headers.common['Authorization']
  }

  return {
    token, user, loading,
    isAuthenticated, isAdmin, isViewer,
    login, logout, fetchMe,
  }
})
