import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getCandidates, getCandidateDates, getCandidateStats } from '../api'
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

  async function fetchStats(days = 30) {
    const { data } = await getCandidateStats(days)
    stats.value = data
  }

  return {
    candidates, currentDate, dates, stats, loading,
    morningFilter, filteredCandidates, morningSummary, lastUpdatedAt,
    fetchCandidates, fetchDates, fetchStats,
  }
})
