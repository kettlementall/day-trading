import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  timeout: 15000,
})

// 候選標的
export const getCandidates = (date) =>
  api.get('/candidates', { params: { date } })

export const getCandidateDates = () =>
  api.get('/candidates/dates')

export const getCandidateStats = (days = 30) =>
  api.get('/candidates/stats', { params: { days } })

export const getCandidate = (id) =>
  api.get(`/candidates/${id}`)

export const getMorningSignals = (date) =>
  api.get('/candidates/morning', { params: { date } })

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
export const getBacktestRounds = () =>
  api.get('/backtest/rounds')

export const triggerBacktestOptimize = (from, to) =>
  api.post('/backtest/optimize', { from, to }, { timeout: 120000 })

export const applyBacktestRound = (id) =>
  api.post(`/backtest/rounds/${id}/apply`)

// 系統規格
export const getSpec = () =>
  api.get('/spec')

export default api
