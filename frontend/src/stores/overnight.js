import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getCandidates, getCandidateStats, getDailyReviewUrl, getDailyReviewShow, getDailyReviewDates, getAnalyzeTipUrl } from '../api'
import dayjs from 'dayjs'

export const useOvernightStore = defineStore('overnight', () => {
  const candidates = ref([])
  const currentDate = ref(dayjs().add(1, 'day').format('YYYY-MM-DD')) // 預設 T+1
  const loading = ref(false)
  const lastUpdatedAt = ref('')
  const isHoliday = ref(false)
  const holidayName = ref('')

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
    candidates, currentDate, loading, lastUpdatedAt, isHoliday, holidayName,
    stats,
    reviewing, reviewLogs, reviewResult, reviewStreamText, reviewDates,
    tipAnalyzing, tipLogs, tipStreamText, tipResult,
    fetchCandidates, fetchStats, fetchReviewDates, fetchDailyReview, dailyReview, analyzeTip,
  }
})
