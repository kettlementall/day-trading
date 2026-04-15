<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">隔日沖回測</h1>
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
          <div class="stat-value highlight-up">{{ stats.gap_accuracy_rate }}%</div>
          <div class="stat-label">跳空預測率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value highlight-up">{{ stats.hit_target_rate }}%</div>
          <div class="stat-label">達標率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value" :class="stats.win_rate >= 50 ? 'highlight-up' : 'highlight-down'">
            {{ stats.win_rate }}%
          </div>
          <div class="stat-label">獲利率</div>
        </div>
        <div class="stat-card">
          <div class="stat-value" :class="stats.avg_open_gap > 0 ? 'highlight-up' : 'highlight-down'">
            {{ stats.avg_open_gap > 0 ? '+' : '' }}{{ stats.avg_open_gap }}%
          </div>
          <div class="stat-label">平均開盤跳空</div>
        </div>
      </div>

      <!-- 輔助指標 -->
      <div class="sub-stats">
        <span>停損觸及率 <b>{{ stats.hit_stop_rate }}%</b></span>
        <span>AI 通過率 <b>{{ stats.ai_approval_rate }}%</b></span>
      </div>

      <!-- 趨勢圖表 -->
      <div class="stock-card" style="margin-top: 16px;" v-if="stats.daily?.length">
        <h3 style="margin-bottom: 8px;">走勢趨勢</h3>
        <v-chart :option="chartOption" autoresize style="height: 260px;" />
      </div>

      <!-- 策略分類 -->
      <div v-if="stats.by_strategy && Object.keys(stats.by_strategy).length" style="margin-top: 16px;">
        <h3 style="margin-bottom: 8px;">策略分析</h3>
        <div class="strategy-grid">
          <div v-for="(m, type) in stats.by_strategy" :key="type" class="stock-card strategy-card">
            <div class="strategy-title">
              <el-tag :type="strategyTagType(type)" size="small">{{ strategyLabel(type) }}</el-tag>
              <span class="strategy-count">{{ m.evaluated }} 筆</span>
            </div>
            <div class="strategy-metrics">
              <div><span class="label">獲利率</span><span class="value" :class="m.win_rate >= 50 ? 'highlight-up' : 'highlight-down'">{{ m.win_rate }}%</span></div>
              <div><span class="label">達標率</span><span class="value highlight-up">{{ m.hit_target_rate }}%</span></div>
              <div><span class="label">跳空預測</span><span class="value">{{ m.gap_accuracy_rate }}%</span></div>
              <div><span class="label">停損率</span><span class="value highlight-down">{{ m.hit_stop_rate }}%</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 明牌分析 -->
      <div class="stock-card" style="margin-top: 16px;">
        <div class="ai-header">
          <h3>明牌分析</h3>
          <span class="tip-hint">隔日沖賺到了？告訴 AI 哪天買哪天賺，它幫你找出模式存成教訓</span>
        </div>
        <div class="tip-form">
          <el-input
            v-model="tipSymbol"
            placeholder="股票代號（如 2330）"
            size="small"
            style="width: 150px"
            clearable
          />
          <span class="tip-date-label">買入</span>
          <el-date-picker
            v-model="tipBuyDate"
            type="date"
            format="MM/DD"
            value-format="YYYY-MM-DD"
            :clearable="false"
            size="small"
            style="width: 100px"
            @change="onTipBuyDateChange"
          />
          <span class="tip-date-arrow">→ 出場</span>
          <span class="tip-date-exit">{{ tipExitDisplay }}</span>
          <el-button
            type="primary"
            size="small"
            :loading="store.tipAnalyzing"
            :disabled="!tipSymbol.trim()"
            @click="runTipAnalysis"
          >
            分析
          </el-button>
        </div>
        <el-input
          v-model="tipNotes"
          type="textarea"
          placeholder="備註（可選）：例如「尾盤急拉，隔日跳空開高出場」"
          :rows="2"
          size="small"
          style="margin-top: 8px;"
        />

        <div v-if="store.tipAnalyzing || store.tipResult" class="validation-section" style="margin-top: 8px;">
          <div v-if="store.tipAnalyzing" class="validation-status">
            <el-icon class="is-loading"><Loading /></el-icon>
            <span>AI 分析中...</span>
          </div>
          <div v-if="store.tipLogs.length" class="validation-logs">
            <div v-for="(log, i) in store.tipLogs" :key="i" class="log-line">{{ log }}</div>
          </div>
          <div v-if="store.tipAnalyzing && store.tipStreamText" class="review-report">
            <div class="report-content" v-html="renderMarkdown(store.tipStreamText)" />
          </div>
          <div v-else-if="store.tipResult?.report && !store.tipResult?.error" class="review-report">
            <div class="report-header">
              <span>{{ store.tipResult.symbol }} {{ store.tipResult.name }} — {{ store.tipResult.date }}</span>
              <el-tag v-if="store.tipResult.lesson" type="success" size="small" style="margin-left: 8px;">教訓已儲存 ★</el-tag>
              <el-tag v-else type="warning" size="small" style="margin-left: 8px;">未提取到教訓</el-tag>
            </div>
            <div class="report-content" v-html="renderMarkdown(store.tipResult.report)" />
          </div>
          <div v-else-if="store.tipResult?.error" class="review-error">{{ store.tipResult.error }}</div>
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
              @change="loadReview"
            />
            <el-button
              v-if="!store.reviewResult?.report || store.reviewing"
              type="warning"
              size="small"
              :loading="store.reviewing"
              @click="runDailyReview"
            >
              產出報告
            </el-button>
            <el-button v-else type="info" size="small" plain @click="runDailyReview">重新產出</el-button>
          </div>
        </div>

        <div v-if="reviewLoading" class="validation-section">
          <div class="validation-status">
            <el-icon class="is-loading"><Loading /></el-icon>
            <span>載入報告中...</span>
          </div>
        </div>

        <div v-else-if="store.reviewing || store.reviewResult" class="validation-section">
          <div v-if="store.reviewing" class="validation-status">
            <el-icon class="is-loading"><Loading /></el-icon>
            <span>AI 分析中...</span>
          </div>
          <div v-if="store.reviewLogs.length" class="validation-logs" ref="reviewLogBox">
            <div v-for="(log, i) in store.reviewLogs" :key="i" class="log-line">{{ log }}</div>
          </div>
          <div v-if="store.reviewing && store.reviewStreamText" class="review-report">
            <div class="report-header">
              <el-icon class="is-loading" style="margin-right: 6px;"><Loading /></el-icon>
              <span>報告生成中...</span>
            </div>
            <div class="report-content" v-html="renderMarkdown(store.reviewStreamText)" />
          </div>
          <div v-else-if="store.reviewResult?.report" class="review-report">
            <div class="report-header">
              <span>{{ store.reviewResult.date }} — {{ store.reviewResult.candidates_count }} 檔候選標的</span>
            </div>
            <div class="report-content" v-html="renderMarkdown(store.reviewResult.report)" />
          </div>
          <div v-else-if="store.reviewResult?.error" class="review-error">{{ store.reviewResult.error }}</div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useOvernightStore } from '../stores/overnight'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components'
import VChart from 'vue-echarts'
import { Loading } from '@element-plus/icons-vue'
import dayjs from 'dayjs'

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent])

const store = useOvernightStore()
const days = ref(30)
const loading = ref(false)
const stats = computed(() => store.stats)
const reviewLogBox = ref(null)
const reviewDate = ref(dayjs().format('YYYY-MM-DD'))
const reviewLoading = ref(false)

const tipSymbol = ref('')
const tipBuyDate = ref(dayjs().subtract(1, 'day').format('YYYY-MM-DD')) // 建倉日（昨天買）
const tipExitDate = ref(dayjs().format('YYYY-MM-DD'))                   // 出場日（今天賣）
const tipExitDisplay = computed(() => dayjs(tipExitDate.value).format('MM/DD'))
const tipNotes = ref('')

function onTipBuyDateChange() {
  tipExitDate.value = nextTradingDay(tipBuyDate.value)
}

function nextTradingDay(dateStr) {
  let d = dayjs(dateStr).add(1, 'day')
  while (d.day() === 0 || d.day() === 6) d = d.add(1, 'day')
  return d.format('YYYY-MM-DD')
}

async function fetchData() {
  loading.value = true
  try {
    await store.fetchStats(days.value)
  } finally {
    loading.value = false
  }
}

async function loadReview() {
  reviewLoading.value = true
  try {
    await store.fetchDailyReview(reviewDate.value)
  } finally {
    reviewLoading.value = false
  }
}

async function runDailyReview() {
  try {
    await store.dailyReview(reviewDate.value)
  } catch (e) {
    console.error(e)
  }
}

async function runTipAnalysis() {
  if (!tipSymbol.value) return
  try {
    // 傳出場日（trade_date）給後端，後端用這天的資料計算結果
    await store.analyzeTip(tipExitDate.value, tipSymbol.value.trim(), tipNotes.value.trim())
  } catch (e) {
    console.error(e)
  }
}

function strategyLabel(type) {
  const map = {
    gap_up_open: '跳空高開',
    pullback_entry: '拉回建倉',
    open_follow_through: '延續開盤',
    limit_up_chase: '漲停追強',
  }
  return map[type] || type
}

function strategyTagType(type) {
  const map = {
    gap_up_open: 'danger',
    pullback_entry: 'warning',
    open_follow_through: 'success',
    limit_up_chase: 'primary',
  }
  return map[type] || 'info'
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

watch(() => store.reviewLogs.length, async () => {
  await nextTick()
  if (reviewLogBox.value) reviewLogBox.value.scrollTop = reviewLogBox.value.scrollHeight
})

const chartOption = computed(() => {
  const daily = stats.value?.daily || []
  return {
    grid: { top: 36, right: 16, bottom: 24, left: 40 },
    tooltip: { trigger: 'axis' },
    legend: { data: ['獲利率', '跳空預測率', '達標率'], top: 0, textStyle: { fontSize: 11 } },
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
      { name: '獲利率', type: 'line', data: daily.map(d => d.win_rate), smooth: true, itemStyle: { color: '#f56c6c' } },
      { name: '跳空預測率', type: 'line', data: daily.map(d => d.gap_accuracy_rate), smooth: true, itemStyle: { color: '#409eff' } },
      { name: '達標率', type: 'line', data: daily.map(d => d.hit_target_rate), smooth: true, itemStyle: { color: '#67c23a' } },
    ],
  }
})

onMounted(() => {
  fetchData()
  store.fetchReviewDates()
  loadReview()
})
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
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.ai-header h3 { margin: 0; }

.tip-hint {
  font-size: 11px;
  color: #909399;
}

.ai-buttons {
  display: flex;
  gap: 6px;
  margin-left: auto;
}

.tip-date-label {
  font-size: 12px;
  color: #606266;
  white-space: nowrap;
}

.tip-date-arrow {
  font-size: 12px;
  color: #909399;
  white-space: nowrap;
}

.tip-date-exit {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  white-space: nowrap;
}

.tip-form {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
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
  display: flex;
  align-items: center;
  flex-wrap: wrap;
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
