<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">回測分析</h1>
      <el-select v-model="days" size="small" style="width: 100px" @change="fetchData">
        <el-option :value="7" label="近 7 天" />
        <el-option :value="14" label="近 14 天" />
        <el-option :value="30" label="近 30 天" />
        <el-option :value="60" label="近 60 天" />
      </el-select>
    </div>

    <el-skeleton v-if="loading" :rows="6" animated />

    <template v-else-if="stats">
      <!-- 核心指標卡片 -->
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
          <div class="stat-value highlight-up">{{ stats.buy_reach_rate }}%</div>
          <div class="stat-label">買入可達率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value highlight-up">{{ stats.target_reach_rate }}%</div>
          <div class="stat-label">目標可達率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value" :class="stats.dual_reach_rate >= 30 ? 'highlight-up' : 'highlight-down'">
            {{ stats.dual_reach_rate }}%
          </div>
          <div class="stat-label">雙達率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value" :class="stats.expected_value > 0 ? 'highlight-up' : 'highlight-down'">
            {{ stats.expected_value }}%
          </div>
          <div class="stat-label">期望值</div>
        </div>
      </div>

      <!-- 輔助指標 -->
      <div class="sub-stats">
        <span>停損觸及率 <b>{{ stats.hit_stop_loss_rate }}%</b></span>
        <span>平均買入間距 <b>{{ stats.avg_buy_gap }}%</b></span>
        <span>平均目標間距 <b>{{ stats.avg_target_gap }}%</b></span>
        <span>平均風報比 <b>{{ stats.avg_risk_reward }}</b></span>
      </div>

      <!-- 趨勢圖表 -->
      <div class="stock-card" style="margin-top: 16px;" v-if="stats.daily?.length">
        <h3 style="margin-bottom: 8px;">可達率趨勢</h3>
        <v-chart :option="chartOption" autoresize style="height: 260px;" />
      </div>

      <!-- 策略分類 -->
      <div v-if="stats.by_strategy" style="margin-top: 16px;">
        <h3 style="margin-bottom: 8px;">策略分析</h3>
        <div class="strategy-grid">
          <div v-for="(m, type) in stats.by_strategy" :key="type" class="stock-card strategy-card">
            <div class="strategy-title">
              <el-tag :type="type === 'bounce' ? 'warning' : 'success'" size="small">
                {{ type === 'bounce' ? '跌深反彈' : '突破追多' }}
              </el-tag>
              <span class="strategy-count">{{ m.evaluated }} 筆</span>
            </div>
            <div class="strategy-metrics">
              <div><span class="label">買入可達</span><span class="value">{{ m.buy_reach_rate }}%</span></div>
              <div><span class="label">目標可達</span><span class="value">{{ m.target_reach_rate }}%</span></div>
              <div><span class="label">雙達率</span><span class="value">{{ m.dual_reach_rate }}%</span></div>
              <div><span class="label">期望值</span><span class="value" :class="m.expected_value > 0 ? 'highlight-up' : 'highlight-down'">{{ m.expected_value }}%</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- AI 優化 -->
      <div class="stock-card" style="margin-top: 16px;">
        <div class="ai-header">
          <h3>AI 公式優化</h3>
          <el-button type="primary" size="small" :loading="store.optimizing" @click="runOptimize">
            執行分析
          </el-button>
        </div>

        <div v-if="store.backtestRounds.length" class="rounds-list">
          <div v-for="round in store.backtestRounds" :key="round.id" class="round-item">
            <div class="round-header">
              <span class="round-date">{{ round.analyzed_from }} ~ {{ round.analyzed_to }}</span>
              <span class="round-count">{{ round.sample_count }} 筆</span>
              <el-tag v-if="round.applied" type="success" size="small">已套用</el-tag>
              <el-button
                v-else
                type="warning"
                size="small"
                @click="applyRound(round.id)"
              >
                套用建議
              </el-button>
            </div>
            <div class="round-analysis">{{ round.suggestions?.analysis }}</div>
            <div v-if="round.suggestions?.adjustments" class="round-adjustments">
              <div v-for="(changes, type) in round.suggestions.adjustments" :key="type">
                <span class="adj-type">[{{ type }}]</span>
                <span v-for="(val, key) in changes" :key="key" class="adj-item">{{ key }}: {{ val }}</span>
              </div>
            </div>
          </div>
        </div>
        <el-empty v-else description="尚無優化紀錄" :image-size="60" />
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useCandidateStore } from '../stores/candidates'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components'
import VChart from 'vue-echarts'
import dayjs from 'dayjs'

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent])

const store = useCandidateStore()
const days = ref(30)
const loading = ref(false)
const stats = computed(() => store.stats)

async function fetchData() {
  loading.value = true
  try {
    await Promise.all([
      store.fetchStats(days.value),
      store.fetchBacktestRounds(),
    ])
  } finally {
    loading.value = false
  }
}

async function runOptimize() {
  const from = dayjs().subtract(days.value, 'day').format('YYYY-MM-DD')
  const to = dayjs().format('YYYY-MM-DD')
  await store.optimize(from, to)
}

async function applyRound(id) {
  await store.applyRound(id)
  // 重新載入指標看套用後效果
  await store.fetchStats(days.value)
}

const chartOption = computed(() => {
  const daily = stats.value?.daily || []
  return {
    grid: { top: 36, right: 16, bottom: 24, left: 40 },
    tooltip: { trigger: 'axis' },
    legend: { data: ['買入可達率', '目標可達率', '雙達率'], top: 0, textStyle: { fontSize: 11 } },
    xAxis: {
      type: 'category',
      data: daily.map(d => d.date?.slice(5)),
      axisLabel: { fontSize: 10 },
    },
    yAxis: {
      type: 'value',
      max: 100,
      axisLabel: { formatter: '{value}%', fontSize: 11 },
    },
    series: [
      {
        name: '買入可達率',
        type: 'line',
        data: daily.map(d => d.buy_reach_rate),
        smooth: true,
        itemStyle: { color: '#409eff' },
      },
      {
        name: '目標可達率',
        type: 'line',
        data: daily.map(d => d.target_reach_rate),
        smooth: true,
        itemStyle: { color: '#e6a23c' },
      },
      {
        name: '雙達率',
        type: 'line',
        data: daily.map(d => d.dual_reach_rate),
        smooth: true,
        itemStyle: { color: '#67c23a' },
      },
    ],
  }
})

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
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}

.stat-card {
  background: #fff;
  border-radius: 8px;
  padding: 14px 10px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.stat-value {
  font-size: 22px;
  font-weight: 700;
}

.stat-label {
  font-size: 11px;
  color: #909399;
  margin-top: 2px;
}

.highlight-up { color: #f56c6c; }
.highlight-down { color: #67c23a; }

.sub-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 10px;
  padding: 8px 12px;
  background: #fafafa;
  border-radius: 6px;
  font-size: 12px;
  color: #606266;
}

.sub-stats b {
  margin-left: 2px;
  color: #303133;
}

.strategy-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}

.strategy-card {
  padding: 12px;
}

.strategy-title {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}

.strategy-count {
  font-size: 11px;
  color: #909399;
}

.strategy-metrics {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  font-size: 12px;
}

.strategy-metrics .label {
  color: #909399;
  margin-right: 4px;
}

.strategy-metrics .value {
  font-weight: 600;
}

.ai-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.ai-header h3 { margin: 0; }

.rounds-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.round-item {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 10px;
}

.round-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.round-date {
  font-size: 12px;
  font-weight: 600;
}

.round-count {
  font-size: 11px;
  color: #909399;
}

.round-analysis {
  font-size: 12px;
  color: #606266;
  margin-bottom: 4px;
}

.round-adjustments {
  font-size: 11px;
  color: #909399;
  font-family: monospace;
}

.adj-type {
  font-weight: 600;
  margin-right: 4px;
}

.adj-item {
  margin-right: 8px;
}
</style>
