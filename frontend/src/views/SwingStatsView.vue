<template>
  <div class="page swing-stats">
    <header class="page-header">
      <h1 class="page-title">短線績效</h1>
      <el-select v-model="days" size="small" style="width: 110px" @change="fetchData">
        <el-option :value="7" label="近 7 天" />
        <el-option :value="14" label="近 14 天" />
        <el-option :value="30" label="近 30 天" />
        <el-option :value="60" label="近 60 天" />
      </el-select>
    </header>

    <el-skeleton v-if="loading" :rows="6" animated />

    <template v-else-if="stats">
      <!-- 候選統計 -->
      <section class="section">
        <h2 class="section-title">候選統計</h2>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">總候選</div>
            <div class="stat-value">{{ stats.total_candidates }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">AI 選入</div>
            <div class="stat-value">{{ stats.ai_selected }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">已走完 20 日</div>
            <div class="stat-value">{{ stats.evaluated }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">平均風報比</div>
            <div class="stat-value">{{ stats.avg_risk_reward }}</div>
          </div>
        </div>
      </section>

      <!-- 紙上績效（候選依 entry/target/stop 模擬走 20 個交易日） -->
      <section class="section">
        <h2 class="section-title">紙上績效（模擬持有 20 日）</h2>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">達標率</div>
            <div class="stat-value highlight-up">{{ stats.paper_target_reach_rate }}%</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">停損率</div>
            <div class="stat-value highlight-down">{{ stats.paper_stop_loss_rate }}%</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">期望值</div>
            <div class="stat-value" :class="stats.paper_expected_value > 0 ? 'highlight-up' : 'highlight-down'">
              {{ signed(stats.paper_expected_value) }}%
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-label">平均持有日數</div>
            <div class="stat-value">{{ stats.paper_avg_holding_days }}</div>
          </div>
        </div>
      </section>

      <!-- 實際持倉績效 -->
      <section class="section" v-if="stats.realized">
        <h2 class="section-title">實現績效（已平倉/停損結束）</h2>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">已結束筆數</div>
            <div class="stat-value">{{ stats.realized.closed_positions }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">實際勝率</div>
            <div class="stat-value" :class="stats.realized.win_rate >= 50 ? 'highlight-up' : 'highlight-down'">
              {{ stats.realized.win_rate }}%
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-label">平均實際報酬</div>
            <div class="stat-value" :class="stats.realized.avg_return > 0 ? 'highlight-up' : 'highlight-down'">
              {{ signed(stats.realized.avg_return) }}%
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-label">停損結束率</div>
            <div class="stat-value highlight-down">{{ stats.realized.hit_stop_rate }}%</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">平均持有日數</div>
            <div class="stat-value">{{ stats.realized.avg_holding_days }}</div>
          </div>
        </div>
      </section>

      <!-- 單日 AI 檢討：每日 19:30 產出完整 swing 檢討報告 -->
      <section class="section">
        <div class="section-heading-row">
          <h2 class="section-title">單日 AI 檢討</h2>
          <div class="review-controls">
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
              v-if="!reviewResult?.report || reviewing"
              type="warning"
              size="small"
              :loading="reviewing"
              @click="runDailyReview"
            >
              產出報告
            </el-button>
            <el-button
              v-else
              type="info"
              size="small"
              plain
              @click="runDailyReview"
            >
              重新產出
            </el-button>
          </div>
        </div>

        <div class="stock-card review-card">
          <div v-if="reviewLoading" class="validation-status">
            <el-icon class="is-loading"><Loading /></el-icon>
            <span>載入報告中...</span>
          </div>

          <template v-else>
            <div v-if="reviewing" class="validation-status">
              <el-icon class="is-loading"><Loading /></el-icon>
              <span>AI 分析中...</span>
            </div>

            <div v-if="reviewLogs.length" class="validation-logs" ref="reviewLogBox">
              <div v-for="(log, i) in reviewLogs" :key="i" class="log-line">{{ log }}</div>
            </div>

            <div v-if="reviewing && reviewStreamText" class="review-report">
              <div class="report-header">
                <el-icon class="is-loading" style="margin-right: 6px;"><Loading /></el-icon>
                <span>報告生成中...</span>
              </div>
              <div class="report-content" v-html="renderMarkdown(reviewStreamText)" />
            </div>

            <div v-else-if="reviewResult?.report" class="review-report">
              <div class="report-header">
                <span>{{ reviewResult.date }} — {{ reviewResult.candidates_count }} 檔候選/持倉檢討</span>
              </div>
              <div class="report-content" v-html="renderMarkdown(reviewResult.report)" />
            </div>

            <div v-else-if="reviewResult?.error" class="review-error">
              {{ reviewResult.error }}
            </div>

            <el-empty v-else description="此日期尚無短線 AI 檢討報告" :image-size="60" />
          </template>
        </div>
      </section>

      <!-- AI 教訓：每週日 17:00 從上週平倉資料萃取，14 天有效期 -->
      <section class="section">
        <h2 class="section-title">AI 教訓</h2>
        <div v-if="lessons.length" class="lesson-list">
          <article v-for="l in lessons" :key="l.id" class="stock-card lesson-card">
            <div class="lesson-head">
              <el-tag :type="lessonTagType(l.type)" size="small">{{ lessonTagLabel(l.type) }}</el-tag>
              <span v-if="l.category" class="lesson-category">{{ l.category }}</span>
              <span v-if="l.source === 'tip'" class="lesson-source-tip">★ 明牌</span>
              <span v-else-if="l.source === 'weekly'" class="lesson-source-weekly">每週萃取</span>
              <span class="lesson-meta">{{ l.trade_date }} · 剩 {{ l.days_left }} 天</span>
            </div>
            <div class="lesson-content">{{ l.content }}</div>
          </article>
        </div>
        <el-empty v-else description="尚無短線教訓（每週日 17:00 自動萃取）" :image-size="60" />
      </section>

      <!-- 走勢趨勢 -->
      <section class="section" v-if="stats.daily?.length">
        <h2 class="section-title">候選走勢</h2>
        <div class="chart-card">
          <v-chart :option="chartOption" autoresize style="height: 260px;" />
        </div>
      </section>

      <!-- by_strategy -->
      <section class="section" v-if="byStrategyList.length">
        <h2 class="section-title">策略分析</h2>
        <div class="strategy-grid">
          <article
            v-for="item in byStrategyList"
            :key="item.key"
            class="stock-card strategy-card"
          >
            <div class="strategy-title">
              <el-tag :type="strategyTagType(item.key)" size="small">{{ strategyLabel(item.key) }}</el-tag>
              <span class="strategy-count">{{ item.evaluated }} 筆</span>
            </div>
            <div class="strategy-metrics">
              <div><span class="label">達標率</span><span class="value highlight-up">{{ item.paper_target_reach_rate }}%</span></div>
              <div><span class="label">停損率</span><span class="value highlight-down">{{ item.paper_stop_loss_rate }}%</span></div>
              <div><span class="label">期望值</span><span class="value" :class="item.paper_expected_value > 0 ? 'highlight-up' : 'highlight-down'">{{ signed(item.paper_expected_value) }}%</span></div>
              <div><span class="label">平均日數</span><span class="value">{{ item.paper_avg_holding_days }}</span></div>
            </div>
          </article>
        </div>
      </section>

      <!-- by_thesis -->
      <section class="section" v-if="stats.by_thesis?.length">
        <h2 class="section-title">論點命中率</h2>
        <div class="stock-card thesis-card">
          <div class="thesis-row thesis-head">
            <div class="thesis-name">論點</div>
            <div>樣本</div>
            <div>達標</div>
            <div>停損</div>
            <div>期望值</div>
          </div>
          <div v-for="(t, idx) in stats.by_thesis" :key="idx" class="thesis-row">
            <div class="thesis-name">{{ t.thesis }}</div>
            <div>{{ t.count }}</div>
            <div class="highlight-up">{{ t.paper_target_reach_rate }}%</div>
            <div class="highlight-down">{{ t.paper_stop_loss_rate }}%</div>
            <div :class="t.paper_expected_value > 0 ? 'highlight-up' : 'highlight-down'">
              {{ signed(t.paper_expected_value) }}%
            </div>
          </div>
        </div>
      </section>
    </template>

    <el-empty v-else description="尚無短線資料" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart, BarChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components'
import VChart from 'vue-echarts'
import { Loading } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import {
  getCandidateStats,
  getDailyReviewDates,
  getDailyReviewShow,
  getDailyReviewUrl,
  getSwingLessons,
} from '../api'

use([CanvasRenderer, LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent])

const days = ref(30)
const stats = ref(null)
const loading = ref(false)
const lessons = ref([])
const reviewDate = ref(dayjs().format('YYYY-MM-DD'))
const reviewLoading = ref(false)
const reviewLogs = ref([])
const reviewResult = ref(null)
const reviewStreamText = ref('')
const reviewing = ref(false)
const reviewLogBox = ref(null)

onMounted(() => {
  fetchData()
  fetchLessons()
  initReview()
})

async function fetchData() {
  loading.value = true
  try {
    const { data } = await getCandidateStats(days.value, 'swing')
    stats.value = data
  } finally {
    loading.value = false
  }
}

async function fetchLessons() {
  try {
    const { data } = await getSwingLessons()
    lessons.value = data.data || []
  } catch {
    lessons.value = []
  }
}

async function initReview() {
  try {
    const { data } = await getDailyReviewDates('swing')
    if (data?.length) {
      reviewDate.value = data[0]
    }
  } finally {
    await loadReview()
  }
}

async function loadReview() {
  reviewLoading.value = true
  reviewResult.value = null
  reviewLogs.value = []
  reviewStreamText.value = ''
  try {
    const { data } = await getDailyReviewShow(reviewDate.value, 'swing')
    if (data.exists) {
      reviewResult.value = {
        date: data.date,
        candidates_count: data.candidates_count,
        report: data.report,
      }
    }
  } finally {
    reviewLoading.value = false
  }
}

function runDailyReview() {
  reviewing.value = true
  reviewLogs.value = []
  reviewResult.value = null
  reviewStreamText.value = ''

  const eventSource = new EventSource(getDailyReviewUrl(reviewDate.value, 'swing'))

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
  })

  eventSource.onerror = () => {
    if (reviewStreamText.value) {
      reviewResult.value = {
        date: reviewDate.value,
        candidates_count: null,
        report: reviewStreamText.value,
      }
      reviewStreamText.value = ''
    } else {
      reviewResult.value = { error: 'AI 檢討連線失敗，請稍後重試' }
    }
    reviewing.value = false
    eventSource.close()
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

function lessonTagType(type) {
  return {
    screening: 'primary',
    entry: 'success',
    exit: 'warning',
    market: 'info',
  }[type] || 'info'
}

function lessonTagLabel(type) {
  return {
    screening: '選股',
    entry: '進場',
    exit: '出場',
    market: '大盤',
  }[type] || type
}

const byStrategyList = computed(() => {
  const map = stats.value?.by_strategy || {}
  return Object.entries(map).map(([key, m]) => ({ key, ...m }))
})

const chartOption = computed(() => {
  const daily = stats.value?.daily || []
  return {
    tooltip: { trigger: 'axis' },
    legend: { data: ['候選', 'AI 選入', '已走完'], top: 0 },
    grid: { left: 40, right: 16, top: 30, bottom: 24 },
    xAxis: {
      type: 'category',
      data: daily.map(d => d.date.slice(5)),
      axisLabel: { fontSize: 10 },
    },
    yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
    series: [
      { name: '候選', type: 'bar', data: daily.map(d => d.candidates), itemStyle: { color: '#94a3b8' } },
      { name: 'AI 選入', type: 'bar', data: daily.map(d => d.ai_selected), itemStyle: { color: '#1d4ed8' } },
      { name: '已走完', type: 'line', data: daily.map(d => d.evaluated), itemStyle: { color: '#67c23a' } },
    ],
  }
})

function signed(v) {
  if (v === null || v === undefined) return '-'
  return `${v >= 0 ? '+' : ''}${v}`
}

function strategyLabel(key) {
  return {
    trend_pullback: '趨勢回檔',
    trend_follow: '趨勢追蹤',
    base_breakout: '盤整突破',
  }[key] || key
}

function strategyTagType(key) {
  return {
    trend_pullback: 'success',
    trend_follow: 'primary',
    base_breakout: 'warning',
  }[key] || 'info'
}

watch(() => reviewLogs.value.length, async () => {
  await nextTick()
  if (reviewLogBox.value) {
    reviewLogBox.value.scrollTop = reviewLogBox.value.scrollHeight
  }
})
</script>

<style scoped>
.swing-stats {
  --c-primary: #1d4ed8;
  --c-up: #f56c6c;
  --c-down: #67c23a;
  --c-text: #0f172a;
  --c-text-sub: #475569;
  --c-text-muted: #94a3b8;
  --c-border: #e2e8f0;
  --c-surface: #ffffff;
  --shadow-card: 0 1px 2px rgba(15, 23, 42, 0.04), 0 1px 3px rgba(15, 23, 42, 0.06);

  font-feature-settings: 'tnum';
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.page-title {
  font-size: 22px;
  font-weight: 700;
  margin: 0;
}

.section {
  margin-top: 18px;
}

.section-title {
  display: flex;
  align-items: center;
  font-size: 15px;
  font-weight: 700;
  margin: 0 0 12px;
}

.section-title::before {
  content: '';
  display: inline-block;
  width: 4px;
  height: 14px;
  border-radius: 2px;
  background: var(--c-primary);
  margin-right: 8px;
}

.section-heading-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}

.section-heading-row .section-title {
  margin-bottom: 0;
}

.review-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 8px;
}

.stat-card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 6px;
  padding: 10px 12px;
  box-shadow: var(--shadow-card);
}

.stat-label {
  font-size: 11px;
  color: var(--c-text-muted);
  letter-spacing: 0.4px;
  margin-bottom: 4px;
}

.stat-value {
  font-size: 20px;
  font-weight: 700;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}

.highlight-up { color: var(--c-up); }
.highlight-down { color: var(--c-down); }

.chart-card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 6px;
  padding: 12px;
  box-shadow: var(--shadow-card);
}

.strategy-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 8px;
}

.strategy-card {
  padding: 12px;
}

.strategy-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.strategy-count {
  font-size: 12px;
  color: var(--c-text-muted);
}

.strategy-metrics {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px 12px;
  font-size: 13px;
}

.strategy-metrics .label {
  color: var(--c-text-muted);
  margin-right: 6px;
}

.strategy-metrics .value {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.thesis-card {
  padding: 0;
  overflow: hidden;
}

.thesis-row {
  display: grid;
  grid-template-columns: 2fr 60px 70px 70px 90px;
  gap: 8px;
  padding: 8px 12px;
  font-size: 13px;
  border-bottom: 1px solid var(--c-border);
  font-variant-numeric: tabular-nums;
}

.thesis-row:last-child {
  border-bottom: none;
}

.thesis-head {
  background: #f8fafc;
  font-weight: 600;
  color: var(--c-text-sub);
  font-size: 12px;
}

.thesis-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* AI 教訓卡片 */
.lesson-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lesson-card {
  padding: 10px 12px;
}

.lesson-head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 6px;
  font-size: 12px;
}

.lesson-category {
  color: var(--c-text-sub);
  background: #f1f5f9;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
}

.lesson-source-tip {
  color: #d97706;
  font-weight: 700;
}

.lesson-source-weekly {
  color: var(--c-text-muted);
  font-size: 11px;
}

.lesson-meta {
  margin-left: auto;
  color: var(--c-text-muted);
  font-size: 11px;
  font-variant-numeric: tabular-nums;
}

.lesson-content {
  font-size: 13px;
  color: var(--c-text);
  line-height: 1.55;
}

.review-card {
  padding: 12px;
}

.validation-status {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--c-primary);
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
  max-height: 180px;
  overflow-y: auto;
  margin-bottom: 10px;
}

.log-line {
  white-space: pre-wrap;
  word-break: break-all;
}

.review-report {
  margin-top: 6px;
}

.report-header {
  font-size: 13px;
  font-weight: 600;
  color: var(--c-text);
  margin-bottom: 8px;
  padding: 6px 10px;
  background: #f8fafc;
  border-radius: 4px;
}

.report-content {
  font-size: 13px;
  line-height: 1.7;
  color: var(--c-text);
  padding: 10px;
  border: 1px solid var(--c-border);
  border-radius: 6px;
  max-height: 620px;
  overflow-y: auto;
}

.report-content :deep(h3) {
  font-size: 15px;
  margin: 16px 0 8px;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--c-border);
}

.report-content :deep(h4) {
  font-size: 14px;
  margin: 12px 0 6px;
  color: var(--c-primary);
}

.report-content :deep(strong) {
  color: #d97706;
}

.report-content :deep(ul) {
  margin: 4px 0;
  padding-left: 20px;
}

.report-content :deep(li) {
  margin: 2px 0;
}

.review-error {
  padding: 10px;
  background: #fef0f0;
  color: #f56c6c;
  border-radius: 6px;
  font-size: 13px;
}

@media (max-width: 640px) {
  .section-heading-row {
    align-items: flex-start;
    flex-direction: column;
  }

  .review-controls {
    justify-content: flex-start;
  }
}
</style>
