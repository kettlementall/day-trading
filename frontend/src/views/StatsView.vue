<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">績效統計</h1>
      <el-select v-model="days" size="small" style="width: 100px" @change="fetchData">
        <el-option :value="7" label="近 7 天" />
        <el-option :value="14" label="近 14 天" />
        <el-option :value="30" label="近 30 天" />
        <el-option :value="60" label="近 60 天" />
      </el-select>
    </div>

    <el-skeleton v-if="loading" :rows="4" animated />

    <template v-else-if="stats">
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-value">{{ stats.total_candidates }}</div>
          <div class="stat-label">候選標的數</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ stats.evaluated }}</div>
          <div class="stat-label">已驗證</div>
        </div>
        <div class="stat-card">
          <div class="stat-value highlight-up">{{ stats.hit_rate }}%</div>
          <div class="stat-label">命中率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value" :class="stats.avg_max_profit > 0 ? 'highlight-up' : 'highlight-down'">
            {{ stats.avg_max_profit }}%
          </div>
          <div class="stat-label">平均最高獲利</div>
        </div>
      </div>

      <div class="stock-card" style="margin-top: 16px;">
        <h3 style="margin-bottom: 8px;">命中率趨勢</h3>
        <v-chart :option="chartOption" autoresize style="height: 240px;" />
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useCandidateStore } from '../stores/candidates'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart } from 'echarts/charts'
import { GridComponent, TooltipComponent } from 'echarts/components'
import VChart from 'vue-echarts'

use([CanvasRenderer, BarChart, GridComponent, TooltipComponent])

const store = useCandidateStore()
const days = ref(30)
const loading = ref(false)
const stats = computed(() => store.stats)

async function fetchData() {
  loading.value = true
  try {
    await store.fetchStats(days.value)
  } finally {
    loading.value = false
  }
}

const chartOption = computed(() => ({
  grid: { top: 10, right: 16, bottom: 24, left: 40 },
  xAxis: { type: 'category', data: ['佔位資料'], axisLabel: { fontSize: 11 } },
  yAxis: { type: 'value', max: 100, axisLabel: { formatter: '{value}%', fontSize: 11 } },
  series: [{
    type: 'bar',
    data: [stats.value?.hit_rate || 0],
    itemStyle: { color: '#409eff', borderRadius: [4, 4, 0, 0] },
    barMaxWidth: 40,
  }],
}))

onMounted(() => fetchData())
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}

.stat-card {
  background: #fff;
  border-radius: 8px;
  padding: 16px 12px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
}

.stat-label {
  font-size: 12px;
  color: #909399;
  margin-top: 4px;
}

.highlight-up { color: #f56c6c; }
.highlight-down { color: #67c23a; }
</style>
