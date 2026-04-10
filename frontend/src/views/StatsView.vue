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

      <!-- 單日 AI 檢討 -->
      <div class="stock-card" style="margin-top: 16px;">
        <div class="ai-header">
          <h3>單日 AI 檢討</h3>
          <div class="ai-buttons">
            <el-date-picker
              v-model="reviewDate"
              type="date"
              format="MM/DD"
              value-format="YYYY-MM-DD"
              :clearable="false"
              size="small"
              style="width: 110px"
              placeholder="選擇日期"
            />
            <el-button type="warning" size="small" :loading="store.reviewing" @click="runDailyReview">
              產出報告
            </el-button>
          </div>
        </div>

        <div v-if="store.reviewing || store.reviewResult" class="validation-section">
          <div v-if="store.reviewing" class="validation-status">
            <el-icon class="is-loading"><Loading /></el-icon>
            <span>AI 分析中...</span>
          </div>

          <div v-if="store.reviewLogs.length" class="validation-logs" ref="reviewLogBox">
            <div v-for="(log, i) in store.reviewLogs" :key="i" class="log-line">{{ log }}</div>
          </div>

          <!-- 串流中的即時報告 -->
          <div v-if="store.reviewing && store.reviewStreamText" class="review-report">
            <div class="report-header">
              <el-icon class="is-loading" style="margin-right: 6px;"><Loading /></el-icon>
              <span>報告生成中...</span>
            </div>
            <div class="report-content" v-html="renderMarkdown(store.reviewStreamText)" />
          </div>

          <!-- 完成的報告 -->
          <div v-else-if="store.reviewResult?.report" class="review-report">
            <div class="report-header">
              <span>{{ store.reviewResult.date }} — {{ store.reviewResult.candidates_count }} 檔候選標的</span>
            </div>
            <div class="report-content" v-html="renderMarkdown(store.reviewResult.report)" />
          </div>
          <div v-else-if="store.reviewResult?.error" class="review-error">
            {{ store.reviewResult.error }}
          </div>
        </div>
      </div>

      <!-- AI 優化 -->
      <div class="stock-card" style="margin-top: 16px;">
        <div class="ai-header">
          <h3>AI 公式優化</h3>
          <div class="ai-buttons">
            <el-button size="small" :loading="store.optimizing" @click="runOptimize">
              單次分析
            </el-button>
            <el-button type="primary" size="small" :loading="store.validating" @click="runValidated">
              自動優化
            </el-button>
          </div>
        </div>

        <!-- 驗證優化進度 -->
        <div v-if="store.validating || store.validationResult" class="validation-section">
          <div v-if="store.validating" class="validation-status">
            <el-icon class="is-loading"><Loading /></el-icon>
            <span>優化循環執行中...</span>
          </div>

          <!-- 執行日誌 -->
          <div v-if="store.validationLogs.length" class="validation-logs" ref="logBox">
            <div v-for="(log, i) in store.validationLogs" :key="i" class="log-line">{{ log }}</div>
          </div>

          <!-- 最終結果 -->
          <div v-if="store.validationResult" class="validation-result">
            <div :class="['result-badge', store.validationResult.improved ? 'improved' : 'unchanged']">
              {{ store.validationResult.improved ? '已改善' : '維持原樣' }}
              <span class="attempt-count">（{{ store.validationResult.attempts }} 次嘗試）</span>
            </div>
            <table class="comparison-table">
              <thead>
                <tr><th>指標</th><th>優化前</th><th>優化後</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="item in comparisonRows" :key="item.key">
                  <td>{{ item.label }}</td>
                  <td>{{ item.before }}</td>
                  <td>{{ item.after }}</td>
                  <td :class="item.cls">{{ item.arrow }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 歷史紀錄 -->
        <div v-if="store.backtestRounds.length" class="rounds-list">
          <h4 style="margin: 12px 0 8px;">優化紀錄</h4>
          <div v-for="round in store.backtestRounds" :key="round.id"
               :class="['round-item', { 'round-rolled-back': round.metrics_after && !round.applied }]">
            <div class="round-header">
              <span class="round-date">{{ formatDate(round.analyzed_from) }} ~ {{ formatDate(round.analyzed_to) }}</span>
              <span class="round-count">{{ round.sample_count }} 筆</span>
              <el-tag v-if="round.metrics_after && round.applied" type="success" size="small">驗證通過</el-tag>
              <el-tag v-else-if="round.metrics_after && !round.applied" type="danger" size="small">已回滾</el-tag>
              <el-tag v-else-if="round.applied" type="success" size="small">已套用</el-tag>
              <el-tag v-else type="info" size="small">未套用</el-tag>
              <el-tag v-if="round.suggestions?.focus" size="small">{{ focusLabel(round.suggestions.focus) }}</el-tag>
            </div>

            <!-- before/after 完整對比 -->
            <div v-if="round.metrics_after" class="round-comparison-full">
              <table class="comparison-table">
                <thead><tr><th></th><th>調整前</th><th>調整後</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="item in roundComparison(round)" :key="item.key">
                    <td>{{ item.label }}</td>
                    <td>{{ item.before }}</td>
                    <td>{{ item.after }}</td>
                    <td :class="item.cls">{{ item.arrow }}</td>
                  </tr>
                </tbody>
              </table>
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
        <el-empty v-else-if="!store.validating && !store.validationResult" description="尚無優化紀錄" :image-size="60" />
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useCandidateStore } from '../stores/candidates'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components'
import VChart from 'vue-echarts'
import { Loading } from '@element-plus/icons-vue'
import dayjs from 'dayjs'

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent])

const store = useCandidateStore()
const days = ref(30)
const loading = ref(false)
const stats = computed(() => store.stats)
const logBox = ref(null)
const reviewLogBox = ref(null)
const reviewDate = ref(dayjs().format('YYYY-MM-DD'))

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

async function runDailyReview() {
  try {
    await store.dailyReview(reviewDate.value)
  } catch (e) {
    console.error('Daily review failed:', e)
  }
}

function renderMarkdown(text) {
  if (!text) return ''
  return text
    .replace(/### (.*)/g, '<h4>$1</h4>')
    .replace(/## (.*)/g, '<h3>$1</h3>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n- /g, '\n<li>')
    .replace(/<li>(.*?)(?=\n|$)/g, '<li>$1</li>')
    .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
    .replace(/<\/ul>\s*<ul>/g, '')
    .replace(/\n/g, '<br>')
}

async function runValidated() {
  const from = dayjs().subtract(60, 'day').format('YYYY-MM-DD')
  const to = dayjs().format('YYYY-MM-DD')
  try {
    await store.optimizeValidated(from, to)
    await store.fetchStats(days.value)
  } catch (e) {
    console.error('Validated optimization failed:', e)
  }
}

function focusLabel(focus) {
  const map = { price: '價格', scoring: '評分', thresholds: '門檻' }
  return map[focus] || focus
}

function formatDate(d) {
  if (!d) return ''
  return d.slice(0, 10)
}

function roundComparison(round) {
  const b = round.metrics_before || {}
  const a = round.metrics_after || {}
  const keys = [
    { key: 'buy_reach_rate', label: '買入可達', suffix: '%', higherBetter: true },
    { key: 'target_reach_rate', label: '目標可達', suffix: '%', higherBetter: true },
    { key: 'dual_reach_rate', label: '雙達率', suffix: '%', higherBetter: true },
    { key: 'expected_value', label: '期望值', suffix: '%', higherBetter: true },
    { key: 'hit_stop_loss_rate', label: '停損率', suffix: '%', higherBetter: false },
  ]
  return keys.map(({ key, label, suffix, higherBetter }) => {
    const bv = b[key] ?? 0
    const av = a[key] ?? 0
    const better = higherBetter ? av > bv : av < bv
    const same = Math.abs(av - bv) < 0.01
    return {
      key, label,
      before: bv.toFixed(1) + suffix,
      after: av.toFixed(1) + suffix,
      arrow: same ? '=' : (better ? '↑' : '↓'),
      cls: same ? '' : (better ? 'highlight-up' : 'highlight-down'),
    }
  })
}

const comparisonRows = computed(() => {
  const r = store.validationResult
  if (!r) return []
  const keys = [
    { key: 'buy_reach_rate', label: '買入可達率', suffix: '%', higherBetter: true },
    { key: 'target_reach_rate', label: '目標可達率', suffix: '%', higherBetter: true },
    { key: 'dual_reach_rate', label: '雙達率', suffix: '%', higherBetter: true },
    { key: 'expected_value', label: '期望值', suffix: '%', higherBetter: true },
    { key: 'hit_stop_loss_rate', label: '停損率', suffix: '%', higherBetter: false },
    { key: 'avg_risk_reward', label: '風報比', suffix: '', higherBetter: true },
  ]
  return keys.map(({ key, label, suffix, higherBetter }) => {
    const b = r.baseline?.[key] ?? 0
    const a = r.final?.[key] ?? 0
    const better = higherBetter ? a > b : a < b
    const same = Math.abs(a - b) < 0.01
    return {
      key,
      label,
      before: (key === 'avg_risk_reward' ? b.toFixed(2) : b.toFixed(1)) + suffix,
      after: (key === 'avg_risk_reward' ? a.toFixed(2) : a.toFixed(1)) + suffix,
      arrow: same ? '=' : (better ? '↑' : '↓'),
      cls: same ? '' : (better ? 'highlight-up' : 'highlight-down'),
    }
  })
})

// 自動滾動日誌到底部
watch(() => store.validationLogs.length, async () => {
  await nextTick()
  if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
})

watch(() => store.reviewLogs.length, async () => {
  await nextTick()
  if (reviewLogBox.value) reviewLogBox.value.scrollTop = reviewLogBox.value.scrollHeight
})

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

.ai-buttons {
  display: flex;
  gap: 6px;
}

.validation-section {
  margin-bottom: 12px;
}

.validation-status {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #409eff;
  margin-bottom: 8px;
}

.validation-logs {
  background: #1e1e1e;
  color: #d4d4d4;
  font-family: monospace;
  font-size: 11px;
  line-height: 1.5;
  padding: 10px;
  border-radius: 6px;
  max-height: 200px;
  overflow-y: auto;
  margin-bottom: 10px;
}

.log-line {
  white-space: pre-wrap;
  word-break: break-all;
}

.validation-result {
  margin-bottom: 8px;
}

.result-badge {
  display: inline-block;
  font-size: 13px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 4px;
  margin-bottom: 8px;
}

.result-badge.improved {
  background: #f0f9eb;
  color: #67c23a;
}

.result-badge.unchanged {
  background: #fdf6ec;
  color: #e6a23c;
}

.attempt-count {
  font-weight: 400;
  font-size: 12px;
}

.comparison-table {
  width: 100%;
  font-size: 12px;
  border-collapse: collapse;
}

.comparison-table th {
  text-align: left;
  color: #909399;
  font-weight: 500;
  padding: 4px 8px;
  border-bottom: 1px solid #ebeef5;
}

.comparison-table td {
  padding: 4px 8px;
  border-bottom: 1px solid #f4f4f5;
}

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

.round-rolled-back {
  opacity: 0.65;
  border-color: #fde2e2;
}

.round-comparison-full {
  margin: 6px 0;
}

.round-header {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
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

.round-comparison {
  display: flex;
  gap: 12px;
  margin-top: 4px;
  font-size: 11px;
}

.cmp-item {
  color: #606266;
  font-family: monospace;
}

.review-report {
  margin-top: 10px;
}

.report-header {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 8px;
  padding: 6px 10px;
  background: #f5f7fa;
  border-radius: 4px;
}

.report-content {
  font-size: 13px;
  line-height: 1.7;
  color: #303133;
  padding: 10px;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  max-height: 600px;
  overflow-y: auto;
}

.report-content :deep(h3) {
  font-size: 15px;
  margin: 16px 0 8px;
  padding-bottom: 4px;
  border-bottom: 1px solid #ebeef5;
}

.report-content :deep(h4) {
  font-size: 14px;
  margin: 12px 0 6px;
  color: #409eff;
}

.report-content :deep(strong) {
  color: #e6a23c;
}

.report-content :deep(ul) {
  margin: 4px 0;
  padding-left: 20px;
}

.report-content :deep(li) {
  margin: 2px 0;
}

.review-error {
  margin-top: 10px;
  padding: 10px;
  background: #fef0f0;
  color: #f56c6c;
  border-radius: 6px;
  font-size: 13px;
}
</style>
