import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user    = ref(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const isAdmin         = computed(() => user.value?.role === 'admin')
  const isViewer        = computed(() => user.value?.role === 'viewer')
  const intradayEnabled = computed(() => user.value?.intraday_monitor_enabled !== false)

  async function login(identifier, password) {
    loading.value = true
    try {
      await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
      const { data } = await api.post('/auth/login', { identifier, password })
      user.value = data.user
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    try {
      const { data } = await api.get('/auth/me')
      user.value = data
    } catch (err) {
      if (err.response?.status === 401) {
        user.value = null
      }
    }
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      // 忽略網路錯誤，繼續清除本地狀態
    } finally {
      user.value = null
    }
  }

  return {
    user, loading,
    isAuthenticated, isAdmin, isViewer, intradayEnabled,
    login, logout, fetchMe,
  }
})
