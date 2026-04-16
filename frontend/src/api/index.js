import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: {
    'Accept': 'application/json',
  },
})

// 401 interceptor — lazy import 避免 circular dependency
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      const { useAuthStore } = await import('../stores/auth')
      const authStore = useAuthStore()
      authStore.token = null
      authStore.user  = null
      localStorage.removeItem('auth_token')
      delete api.defaults.headers.common['Authorization']
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  }
)

// 候選標的
export const getCandidates = (date, mode = 'intraday') =>
  api.get('/candidates', { params: { date, mode } })

export const getCandidateDates = () =>
  api.get('/candidates/dates')

export const getCandidateStats = (days = 30, mode = 'intraday') =>
  api.get('/candidates/stats', { params: { days, mode } })

export const getCandidate = (id) =>
  api.get(`/candidates/${id}`)

export const getMorningSignals = (date) =>
  api.get('/candidates/morning', { params: { date } })

export const getCandidateSnapshots = (candidateId) =>
  api.get(`/candidates/${candidateId}/snapshots`)

export const getMonitorStatus = (date, mode = 'intraday') =>
  api.get('/candidates/monitors', { params: { date, mode } })

export const getCandidateMonitor = (candidateId) =>
  api.get(`/candidates/${candidateId}/monitor`)

// 股票
export const getStocks = (params) =>
  api.get('/stocks', { params })

export const getStock = (id) =>
  api.get(`/stocks/${id}`)

export const getStockKline = (id, days = 60) =>
  api.get(`/stocks/${id}/kline`, { params: { days } })

export const getStockDetail = (id, days = 5) =>
  api.get(`/stocks/${id}/detail`, { params: { days } })

// 篩選規則
export const getScreeningRules = () =>
  api.get('/screening-rules')

export const createScreeningRule = (data) =>
  api.post('/screening-rules', data)

export const updateScreeningRule = (id, data) =>
  api.put(`/screening-rules/${id}`, data)

export const deleteScreeningRule = (id) =>
  api.delete(`/screening-rules/${id}`)

// 公式設定
export const getFormulaSettings = () =>
  api.get('/formula-settings')

export const updateFormulaSetting = (type, config) =>
  api.put(`/formula-settings/${type}`, { config })

// 手動同步
export const triggerDataSync = (date, tasks) =>
  api.post('/data-sync', { date, tasks })

// 消息面
export const getNewsDashboard = (date) =>
  api.get('/news/dashboard', { params: { date } })

export const fetchNews = (date) =>
  api.post('/news/fetch', { date })

export const getNewsFetchStatus = (date) =>
  api.get('/news/fetch-status', { params: { date } })

// 回測系統
export const getDailyReviewUrl = (date, mode = 'intraday') => {
  const base = api.defaults.baseURL || '/api'
  const token = localStorage.getItem('auth_token') || ''
  return `${base}/backtest/daily-review?date=${date}&mode=${mode}&token=${token}`
}

export const getDailyReviewShow = (date, mode = 'intraday') =>
  api.get('/backtest/daily-review-show', { params: { date, mode } })

export const getDailyReviewDates = (mode = 'intraday') =>
  api.get('/backtest/daily-review-dates', { params: { mode } })

export const getAnalyzeTipUrl = (date, symbol, notes, mode = 'intraday') => {
  const base = api.defaults.baseURL || '/api'
  const token = localStorage.getItem('auth_token') || ''
  const params = new URLSearchParams({ date, symbol, notes: notes || '', mode, token })
  return `${base}/backtest/analyze-tip?${params}`
}

// 釘選
export const getPins = (date, mode) =>
  api.get('/pins', { params: { date, mode } })

export const pinCandidate = (candidateId) =>
  api.post(`/pins/${candidateId}`)

export const unpinCandidate = (candidateId) =>
  api.delete(`/pins/${candidateId}`)

// 用戶管理（admin only）
export const getUsers = () =>
  api.get('/users')

export const createUser = (data) =>
  api.post('/users', data)

export const updateUser = (id, data) =>
  api.put(`/users/${id}`, data)

export const deleteUser = (id) =>
  api.delete(`/users/${id}`)

// 系統規格
export const getSpec = () =>
  api.get('/spec')

export default api
