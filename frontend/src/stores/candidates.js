import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getCandidates, getCandidateDates, getCandidateStats, getBacktestRounds, triggerBacktestOptimize, applyBacktestRound, getOptimizeValidatedUrl, getDailyReviewUrl, getMonitorStatus } from '../api'
import dayjs from 'dayjs'

export const useCandidateStore = defineStore('candidates', () => {
  const candidates = ref([])
  const currentDate = ref(dayjs().format('YYYY-MM-DD'))
  const dates = ref([])
  const stats = ref(null)
  const loading = ref(false)
  const morningFilter = ref('all') // 'all' | 'confirmed' | 'unconfirmed'
  const lastUpdatedAt = ref('')

  // 依盤前確認狀態篩選
  const filteredCandidates = computed(() => {
    if (morningFilter.value === 'confirmed') {
      return candidates.value.filter(c => c.morning_confirmed)
    }
    if (morningFilter.value === 'unconfirmed') {
      return candidates.value.filter(c => !c.morning_confirmed && c.morning_signals?.length > 0)
    }
    return candidates.value
  })

  // 盤前確認統計
  const morningSummary = computed(() => {
    const all = candidates.value
    const hasMorning = all.filter(c => c.morning_signals?.length > 0)
    const confirmed = all.filter(c => c.morning_confirmed)
    return {
      total: all.length,
      screened: hasMorning.length,
      confirmed: confirmed.length,
    }
  })

  // 盤中監控
  const monitors = ref([])
  const monitorLoading = ref(false)
  let monitorPollingTimer = null

  const activeMonitors = computed(() =>
    monitors.value.filter(m => ['watching', 'entry_signal', 'holding'].includes(m.status))
  )

  const completedMonitors = computed(() =>
    monitors.value.filter(m => ['target_hit', 'stop_hit', 'trailing_stop', 'closed', 'skipped'].includes(m.status))
  )

  async function fetchMonitors(date) {
    monitorLoading.value = true
    try {
      const { data } = await getMonitorStatus(date || currentDate.value)
      monitors.value = data.data
    } finally {
      monitorLoading.value = false
    }
  }

  function startMonitorPolling(date) {
    stopMonitorPolling()
    fetchMonitors(date)
    monitorPollingTimer = setInterval(() => fetchMonitors(date), 30000)
  }

  function stopMonitorPolling() {
    if (monitorPollingTimer) {
      clearInterval(monitorPollingTimer)
      monitorPollingTimer = null
    }
  }

  async function fetchCandidates(date) {
    loading.value = true
    try {
      const { data } = await getCandidates(date || currentDate.value)
      candidates.value = data.data
      currentDate.value = data.date
      lastUpdatedAt.value = data.last_updated_at || ''
    } finally {
      loading.value = false
    }
  }

  async function fetchDates() {
    const { data } = await getCandidateDates()
    dates.value = data
  }

  const backtestRounds = ref([])
  const optimizing = ref(false)
  const validating = ref(false)
  const validationLogs = ref([])
  const validationResult = ref(null)

  // 單日檢討報告
  const reviewing = ref(false)
  const reviewLogs = ref([])
  const reviewResult = ref(null)

  async function fetchStats(days = 30) {
    const { data } = await getCandidateStats(days)
    stats.value = data
  }

  async function fetchBacktestRounds() {
    const { data } = await getBacktestRounds()
    backtestRounds.value = data
  }

  async function optimize(from, to) {
    optimizing.value = true
    try {
      const { data } = await triggerBacktestOptimize(from, to)
      backtestRounds.value.unshift(data)
      return data
    } finally {
      optimizing.value = false
    }
  }

  async function applyRound(id) {
    const { data } = await applyBacktestRound(id)
    const idx = backtestRounds.value.findIndex(r => r.id === id)
    if (idx !== -1) backtestRounds.value[idx] = data
    return data
  }

  const reviewStreamText = ref('')

  function dailyReview(date) {
    reviewing.value = true
    reviewLogs.value = []
    reviewResult.value = null
    reviewStreamText.value = ''

    return new Promise((resolve, reject) => {
      const url = getDailyReviewUrl(date)
      const eventSource = new EventSource(url)

      eventSource.addEventListener('log', (e) => {
        const { message } = JSON.parse(e.data)
        reviewLogs.value.push(message)
      })

      eventSource.addEventListener('chunk', (e) => {
        const { text } = JSON.parse(e.data)
        reviewStreamText.value += text
      })

      eventSource.addEventListener('done', (e) => {
        reviewResult.value = JSON.parse(e.data)
        reviewStreamText.value = ''
        reviewing.value = false
        eventSource.close()
        resolve(reviewResult.value)
      })

      eventSource.onerror = () => {
        // 若已收到串流文字，保留為報告結果而非丟棄
        if (reviewStreamText.value) {
          reviewResult.value = {
            date,
            candidates_count: null,
            report: reviewStreamText.value,
          }
          reviewStreamText.value = ''
        }
        reviewing.value = false
        eventSource.close()
        if (!reviewResult.value) {
          reject(new Error('SSE connection failed'))
        } else {
          resolve(reviewResult.value)
        }
      }
    })
  }

  function optimizeValidated(from, to, maxAttempts = 10) {
    validating.value = true
    validationLogs.value = []
    validationResult.value = null

    return new Promise((resolve, reject) => {
      const url = getOptimizeValidatedUrl(from, to, maxAttempts)
      const eventSource = new EventSource(url)

      eventSource.addEventListener('log', (e) => {
        const { message } = JSON.parse(e.data)
        validationLogs.value.push(message)
      })

      eventSource.addEventListener('done', (e) => {
        validationResult.value = JSON.parse(e.data)
        validating.value = false
        eventSource.close()
        // 重新載入 rounds
        fetchBacktestRounds()
        resolve(validationResult.value)
      })

      eventSource.onerror = () => {
        validating.value = false
        eventSource.close()
        reject(new Error('SSE connection failed'))
      }
    })
  }

  return {
    candidates, currentDate, dates, stats, loading,
    morningFilter, filteredCandidates, morningSummary, lastUpdatedAt,
    monitors, monitorLoading, activeMonitors, completedMonitors,
    backtestRounds, optimizing, validating, validationLogs, validationResult,
    reviewing, reviewLogs, reviewResult, reviewStreamText,
    fetchCandidates, fetchDates, fetchStats,
    fetchMonitors, startMonitorPolling, stopMonitorPolling,
    fetchBacktestRounds, optimize, applyRound, optimizeValidated, dailyReview,
  }
})
