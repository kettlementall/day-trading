import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getCandidates, getCandidateDates, getCandidateStats, getDailyReviewUrl, getDailyReviewShow, getDailyReviewDates, getMonitorStatus } from '../api'
import dayjs from 'dayjs'

export const useCandidateStore = defineStore('candidates', () => {
  const candidates = ref([])
  const currentDate = ref(dayjs().format('YYYY-MM-DD'))
  const dates = ref([])
  const stats = ref(null)
  const loading = ref(false)
  const morningFilter = ref('all') // 'all' | 'AB' | 'C' | 'D'
  const lastUpdatedAt = ref('')
  const isHoliday = ref(false)
  const holidayName = ref('')
  const usIndices = ref([])

  // 依盤前校準等級篩選
  const filteredCandidates = computed(() => {
    if (morningFilter.value === 'AB') {
      return candidates.value.filter(c => c.morning_grade === 'A' || c.morning_grade === 'B')
    }
    if (morningFilter.value === 'C') {
      return candidates.value.filter(c => c.morning_grade === 'C')
    }
    if (morningFilter.value === 'D') {
      return candidates.value.filter(c => c.morning_grade === 'D')
    }
    return candidates.value
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
      isHoliday.value = data.is_holiday || false
      holidayName.value = data.holiday_name || ''
      usIndices.value = data.us_indices || []
    } finally {
      loading.value = false
    }
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

  return {
    candidates, currentDate, dates, stats, loading,
    morningFilter, filteredCandidates, morningSummary, lastUpdatedAt,
    isHoliday, holidayName, usIndices,
    monitors, monitorLoading, activeMonitors, completedMonitors,
    reviewing, reviewLogs, reviewResult, reviewStreamText, reviewDates,
    fetchCandidates, fetchDates, fetchStats,
    fetchMonitors, startMonitorPolling, stopMonitorPolling,
    fetchReviewDates, fetchDailyReview, dailyReview,
  }
})
