<template>
  <div class="page">
    <h1 class="page-title">歷史紀錄</h1>

    <el-skeleton v-if="loading" :rows="6" animated />

    <div v-else>
      <div
        v-for="item in dates"
        :key="item.trade_date"
        class="stock-card date-card"
        @click="goDate(item.trade_date)"
      >
        <div class="date-row">
          <span class="date-text">{{ formatDate(item.trade_date) }}</span>
          <el-tag size="small">{{ item.count }} 檔</el-tag>
        </div>
      </div>

      <el-empty v-if="dates.length === 0" description="尚無歷史資料" />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getCandidateDates } from '../api'
import { useCandidateStore } from '../stores/candidates'
import dayjs from 'dayjs'

const router = useRouter()
const store = useCandidateStore()
const dates = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await getCandidateDates()
    dates.value = data
  } finally {
    loading.value = false
  }
})

function formatDate(d) {
  return dayjs(d).format('YYYY/MM/DD (dd)')
}

function goDate(date) {
  store.currentDate = date
  store.fetchCandidates(date)
  router.push('/')
}
</script>

<style scoped>
.date-card {
  cursor: pointer;
}

.date-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.date-text {
  font-size: 15px;
  font-weight: 500;
}
</style>
