import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getCandidates, getCandidateDates, getCandidateStats, getDailyReviewUrl, getDailyReviewShow, getDailyReviewDates, getMonitorStatus, getAnalyzeTipUrl } from '../api'
import { useAuthStore } from './auth'
import dayjs from 'dayjs'

function intradayPinKey() {
  const uid = useAuthStore().user?.id ?? 'guest'
  return `pinned_intraday_${uid}`
}

function loadPinnedIds(storageKey, date) {
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey) || 'null')
    if (stored && stored.date === date) return new Set(stored.ids)
  } catch {}
  return new Set()
}

export const useCandidateStore = defineStore('candidates', () => {
  const candidates = ref([])
  const currentDate = ref(dayjs().format('YYYY-MM-DD'))
  const currentMode = ref('intraday') // 'intraday' | 'overnight'
  const dates = ref([])
  const stats = ref(null)
  const loading = ref(false)
  const morningFilter = ref('all') // 'all' | 'AB' | 'C' | 'D'
  const lastUpdatedAt = ref('')
  const isHoliday = ref(false)
  const holidayName = ref('')
  const usIndices = ref([])

  // 釘選
  const pinnedIds = ref(new Set())

  function togglePin(id) {
    const next = new Set(pinnedIds.value)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    pinnedIds.value = next
    localStorage.setItem(intradayPinKey(), JSON.stringify({ date: currentDate.value, ids: [...next] }))
  }

  function isPinned(id) {
    return pinnedIds.value.has(id)
  }

  // 依盤前校準等級篩選，釘選的浮到最上面
  const filteredCandidates = computed(() => {
    let list = candidates.value
    if (morningFilter.value === 'AB') {
      list = list.filter(c => c.morning_grade === 'A' || c.morning_grade === 'B')
    } else if (morningFilter.value === 'C') {
      list = list.filter(c => c.morning_grade === 'C')
    } else if (morningFilter.value === 'D') {
      list = list.filter(c => c.morning_grade === 'D')
    }
    return [...list].sort((a, b) => {
      const ap = pinnedIds.value.has(a.id) ? 0 : 1
      const bp = pinnedIds.value.has(b.id) ? 0 : 1
      return ap - bp
    })
  })

  // 盤前校準統計
  const morningSummary = computed(() => {
    const all = candidates.value
    const hasGrade = all.filter(c => c.morning_grade)
    return {
      total: all.length,
      screened: hasGrade.length,
      gradeA: all.filter(c => c.morning_grade === 'A').length,
      gradeB: all.filter(c => c.morning_grade === 'B').length,
      gradeC: all.filter(c => c.morning_grade === 'C').length,
      gradeD: all.filter(c => c.morning_grade === 'D').length,
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

  async function fetchMonitors(date, mode = 'intraday') {
    monitorLoading.value = true
    try {
      const { data } = await getMonitorStatus(date || currentDate.value, mode)
      monitors.value = data.data
    } finally {
      monitorLoading.value = false
    }
  }

  function startMonitorPolling(date, mode = 'intraday') {
    stopMonitorPolling()
    fetchMonitors(date, mode)
    monitorPollingTimer = setInterval(() => fetchMonitors(date, mode), 30000)
  }

  function stopMonitorPolling() {
    if (monitorPollingTimer) {
      clearInterval(monitorPollingTimer)
      monitorPollingTimer = null
    }
  }

  async function fetchCandidates(date, mode) {
    loading.value = true
    const targetMode = mode || currentMode.value
    try {
      const { data } = await getCandidates(date || currentDate.value, targetMode)
      candidates.value = data.data
      currentDate.value = data.date
      currentMode.value = data.mode || targetMode
      pinnedIds.value = loadPinnedIds(intradayPinKey(), data.date)
      lastUpdatedAt.value = data.last_updated_at || ''
      isHoliday.value = data.is_holiday || false
      holidayName.value = data.holiday_name || ''
      usIndices.value = data.us_indices || []
    } finally {
      loading.value = false
    }
  }

  async function switchMode(mode) {
    currentMode.value = mode
    await fetchCandidates(currentDate.value, mode)
  }

  async function fetchDates() {
    const { data } = await getCandidateDates()
    dates.value = data
  }

  // 單日檢討報告
  const reviewing = ref(false)
  const reviewLogs = ref([])
  const reviewResult = ref(null)

  async function fetchStats(days = 30) {
    const { data } = await getCandidateStats(days)
    stats.value = data
  }

  const reviewStreamText = ref('')
  const reviewDates = ref([])

  // 明牌分析
  const tipAnalyzing = ref(false)
  const tipLogs = ref([])
  const tipStreamText = ref('')
  const tipResult = ref(null)

  async function fetchReviewDates() {
    const { data } = await getDailyReviewDates()
    reviewDates.value = data
  }

  async function fetchDailyReview(date) {
    const { data } = await getDailyReviewShow(date)
    if (data.exists) {
      reviewResult.value = {
        date: data.date,
        candidates_count: data.candidates_count,
        report: data.report,
      }
    } else {
      reviewResult.value = null
    }
  }

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

  function analyzeTip(date, symbol, notes) {
    tipAnalyzing.value = true
    tipLogs.value = []
    tipResult.value = null
    tipStreamText.value = ''

    return new Promise((resolve, reject) => {
      const url = getAnalyzeTipUrl(date, symbol, notes)
      const eventSource = new EventSource(url)

      eventSource.addEventListener('log', (e) => {
        const { message } = JSON.parse(e.data)
        tipLogs.value.push(message)
      })

      eventSource.addEventListener('chunk', (e) => {
        const { text } = JSON.parse(e.data)
        tipStreamText.value += text
      })

      eventSource.addEventListener('done', (e) => {
        tipResult.value = JSON.parse(e.data)
        tipStreamText.value = ''
        tipAnalyzing.value = false
        eventSource.close()
        resolve(tipResult.value)
      })

      eventSource.onerror = () => {
        if (tipStreamText.value) {
          tipResult.value = { date, symbol, report: tipStreamText.value }
          tipStreamText.value = ''
        }
        tipAnalyzing.value = false
        eventSource.close()
        if (!tipResult.value) {
          reject(new Error('SSE connection failed'))
        } else {
          resolve(tipResult.value)
        }
      }
    })
  }

  return {
    candidates, currentDate, currentMode, dates, stats, loading,
    morningFilter, filteredCandidates, morningSummary, lastUpdatedAt,
    isHoliday, holidayName, usIndices,
    pinnedIds, togglePin, isPinned,
    monitors, monitorLoading, activeMonitors, completedMonitors,
    reviewing, reviewLogs, reviewResult, reviewStreamText, reviewDates,
    tipAnalyzing, tipLogs, tipStreamText, tipResult,
    fetchCandidates, fetchDates, fetchStats, switchMode,
    fetchMonitors, startMonitorPolling, stopMonitorPolling,
    fetchReviewDates, fetchDailyReview, dailyReview, analyzeTip,
  }
})
