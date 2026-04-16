import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getCandidates, getCandidateStats, getDailyReviewUrl, getDailyReviewShow, getDailyReviewDates, getAnalyzeTipUrl, getPins, pinCandidate, unpinCandidate } from '../api'
import dayjs from 'dayjs'

export const useOvernightStore = defineStore('overnight', () => {
  const candidates = ref([])
  const currentDate = ref(dayjs().add(1, 'day').format('YYYY-MM-DD')) // 預設 T+1
  const loading = ref(false)
  const lastUpdatedAt = ref('')
  const isHoliday = ref(false)
  const holidayName = ref('')

  // 釘選（存資料庫，跨裝置同步）
  const pinnedIds = ref(new Set())

  async function togglePin(id) {
    const next = new Set(pinnedIds.value)
    if (next.has(id)) {
      next.delete(id)
      pinnedIds.value = next
      await unpinCandidate(id)
    } else {
      next.add(id)
      pinnedIds.value = next
      await pinCandidate(id)
    }
  }

  function isPinned(id) {
    return pinnedIds.value.has(id)
  }

  async function fetchPins(date) {
    const { data } = await getPins(date, 'overnight')
    pinnedIds.value = new Set(data)
  }

  // 釘選的浮到最上面
  const sortedCandidates = computed(() =>
    [...candidates.value].sort((a, b) => {
      const ap = pinnedIds.value.has(a.id) ? 0 : 1
      const bp = pinnedIds.value.has(b.id) ? 0 : 1
      return ap - bp
    })
  )

  // 績效統計
  const stats = ref(null)

  // 單日 AI 檢討
  const reviewing = ref(false)
  const reviewLogs = ref([])
  const reviewResult = ref(null)
  const reviewStreamText = ref('')
  const reviewDates = ref([])

  // 明牌分析
  const tipAnalyzing = ref(false)
  const tipLogs = ref([])
  const tipStreamText = ref('')
  const tipResult = ref(null)

  async function fetchStats(days = 30) {
    const { data } = await getCandidateStats(days, 'overnight')
    stats.value = data
  }

  async function fetchCandidates(date) {
    loading.value = true
    try {
      const { data } = await getCandidates(date || currentDate.value, 'overnight')
      candidates.value = data.data
      currentDate.value = data.date
      lastUpdatedAt.value = data.last_updated_at || ''
      await fetchPins(data.date)
      isHoliday.value = data.is_holiday || false
      holidayName.value = data.holiday_name || ''
    } finally {
      loading.value = false
    }
  }

  async function fetchReviewDates() {
    const { data } = await getDailyReviewDates('overnight')
    reviewDates.value = data
  }

  async function fetchDailyReview(date) {
    const { data } = await getDailyReviewShow(date, 'overnight')
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
      const url = getDailyReviewUrl(date, 'overnight')
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
        if (reviewStreamText.value) {
          reviewResult.value = { date, candidates_count: null, report: reviewStreamText.value }
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
      const url = getAnalyzeTipUrl(date, symbol, notes, 'overnight')
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
    candidates, sortedCandidates, currentDate, loading, lastUpdatedAt, isHoliday, holidayName,
    pinnedIds, togglePin, isPinned,
    stats,
    reviewing, reviewLogs, reviewResult, reviewStreamText, reviewDates,
    tipAnalyzing, tipLogs, tipStreamText, tipResult,
    fetchCandidates, fetchStats, fetchReviewDates, fetchDailyReview, dailyReview, analyzeTip,
  }
})
