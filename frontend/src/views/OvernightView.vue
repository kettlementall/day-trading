<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">隔日沖候選標的</h1>
      <div class="header-actions">
        <el-date-picker
          v-model="store.currentDate"
          type="date"
          format="MM/DD (dd)"
          value-format="YYYY-MM-DD"
          :clearable="false"
          size="small"
          style="width: 140px"
          @change="onDateChange"
        />
        <span v-if="store.lastUpdatedAt" class="last-updated">{{ formatTime(store.lastUpdatedAt) }}</span>
      </div>
    </div>

    <div v-if="store.loading" class="loading-wrap">
      <el-skeleton :rows="5" animated />
    </div>

    <div v-else-if="store.isHoliday" class="empty-wrap">
      <el-empty :description="'休市日' + (store.holidayName ? '（' + store.holidayName + '）' : '')" :image-size="120" />
    </div>

    <div v-else-if="store.candidates.length === 0" class="empty-wrap">
      <el-empty description="此交易日尚無隔日沖候選標的（12:30 後執行選股）" />
    </div>

    <div v-else class="candidate-list">
      <div
        v-for="item in store.candidates"
        :key="item.id"
        class="stock-card"
        :class="{ 'ai-rejected': item.ai_selected === false && item.ai_reasoning }"
        @click="goDetail(item)"
      >
        <!-- 頭部：股票資訊 + Haiku 分數 -->
        <div class="card-top">
          <div class="stock-info">
            <span class="stock-symbol">{{ item.stock.symbol }}</span>
            <span class="stock-name">{{ item.stock.name }}</span>
            <span class="stock-industry">{{ item.stock.industry }}</span>
          </div>
          <el-tooltip :content="item.haiku_reasoning || ''" placement="top" :disabled="!item.haiku_reasoning">
            <el-tag size="small" :type="scoreType(item.score)">
              Haiku {{ item.score }}
            </el-tag>
          </el-tooltip>
        </div>

        <!-- 標籤列 -->
        <div class="card-tags">
          <!-- 進場策略類型 -->
          <el-tag
            v-if="item.overnight_strategy"
            size="small"
            type="primary"
            effect="light"
            round
          >
            {{ entryLabel(item.overnight_strategy) }}
          </el-tag>
          <!-- AI 判斷 -->
          <el-tag
            v-if="item.ai_selected"
            size="small"
            type="success"
            effect="dark"
            round
          >
            AI 選入
          </el-tag>
          <el-tag
            v-else-if="item.ai_selected === false && item.ai_reasoning"
            size="small"
            type="danger"
            effect="dark"
            round
          >
            AI 排除
          </el-tag>
          <!-- 事實標籤 -->
          <el-tag
            v-for="reason in (item.reasons || [])"
            :key="reason"
            size="small"
            effect="plain"
            round
          >
            {{ reason }}
          </el-tag>
        </div>

        <!-- 三個關鍵價格 -->
        <div class="card-prices">
          <div class="price-item">
            <span class="label">建議買入</span>
            <span class="value price-buy">{{ item.suggested_buy ?? '—' }}</span>
          </div>
          <div class="price-item">
            <span class="label">目標</span>
            <span class="value price-up">{{ item.target_price ?? '—' }}</span>
          </div>
          <div class="price-item">
            <span class="label">停損</span>
            <span class="value price-down">{{ item.stop_loss ?? '—' }}</span>
          </div>
          <div class="price-item">
            <span class="label">風報比</span>
            <span class="value">{{ item.risk_reward_ratio ?? '—' }}</span>
          </div>
          <div v-if="item.gap_potential_percent" class="price-item">
            <span class="label">預測跳空</span>
            <span class="value price-up">+{{ item.gap_potential_percent }}%</span>
          </div>
        </div>

        <!-- Opus 進場策略說明 -->
        <div v-if="item.overnight_reasoning" class="card-strategy-text">
          {{ item.overnight_reasoning }}
        </div>

        <!-- AI 理由 + 警告 -->
        <div v-if="item.ai_reasoning" class="card-ai-reasoning">
          <div class="ai-reasoning-text">{{ item.ai_reasoning }}</div>
          <div v-if="item.ai_price_reasoning" class="ai-price-reasoning">
            {{ item.ai_price_reasoning }}
          </div>
          <div v-if="item.ai_warnings?.length" class="ai-warnings">
            <span v-for="(w, i) in item.ai_warnings" :key="i" class="ai-warning-chip">{{ w }}</span>
          </div>
        </div>

        <!-- 盤後結果（若已回填） -->
        <div v-if="item.result" class="card-result">
          <span class="result-label" :class="outcomeClass(item.result.overnight_outcome)">
            {{ outcomeLabel(item.result.overnight_outcome) }}
          </span>
          <span v-if="item.result.open_gap_percent !== null" class="result-gap">
            開盤跳空 {{ item.result.open_gap_percent >= 0 ? '+' : '' }}{{ item.result.open_gap_percent }}%
          </span>
          <span v-if="item.result.actual_close" class="result-close">
            收 {{ item.result.actual_close }}
          </span>
        </div>
      </div>
    </div>

    <!-- 隔日沖 AI 單日檢討 -->
    <div class="stock-card review-card">
      <div class="review-header">
        <h3>隔日沖 AI 檢討</h3>
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
            v-if="!store.reviewResult?.report || store.reviewing"
            type="warning"
            size="small"
            :loading="store.reviewing"
            @click="runReview"
          >
            產出報告
          </el-button>
          <el-button v-else type="info" size="small" plain @click="runReview">重新產出</el-button>
        </div>
      </div>

      <div v-if="reviewLoading" class="review-status">
        <el-icon class="is-loading"><Loading /></el-icon>
        <span>載入報告中...</span>
      </div>

      <div v-else-if="store.reviewing || store.reviewResult">
        <div v-if="store.reviewing" class="review-status">
          <el-icon class="is-loading"><Loading /></el-icon>
          <span>AI 分析中...</span>
        </div>
        <div v-if="store.reviewLogs.length" class="review-logs">
          <div v-for="(log, i) in store.reviewLogs" :key="i" class="log-line">{{ log }}</div>
        </div>
        <div v-if="store.reviewing && store.reviewStreamText" class="review-report">
          <div class="report-content" v-html="renderMarkdown(store.reviewStreamText)" />
        </div>
        <div v-else-if="store.reviewResult?.report" class="review-report">
          <div class="report-header">
            {{ store.reviewResult.date }} — {{ store.reviewResult.candidates_count }} 檔候選標的
          </div>
          <div class="report-content" v-html="renderMarkdown(store.reviewResult.report)" />
        </div>
        <div v-else-if="store.reviewResult?.error" class="review-error">
          {{ store.reviewResult.error }}
        </div>
      </div>
    </div>

    <!-- 隔日沖明牌分析 -->
    <div class="stock-card review-card">
      <div class="review-header">
        <h3>隔日沖明牌分析</h3>
        <span class="tip-hint">今晚買了哪支隔日沖賺到了？AI 從數值找理由存成高優先教訓</span>
      </div>
      <div class="tip-form">
        <el-input
          v-model="tipSymbol"
          placeholder="股票代號（如 2330）"
          size="small"
          style="width: 150px"
          clearable
        />
        <el-date-picker
          v-model="tipDate"
          type="date"
          format="MM/DD"
          value-format="YYYY-MM-DD"
          :clearable="false"
          size="small"
          style="width: 110px"
        />
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
        placeholder="備註（可選）：例如「今日強勢收盤，明日預期延續」"
        :rows="2"
        size="small"
        style="margin-top: 8px"
      />

      <div v-if="store.tipAnalyzing || store.tipResult" style="margin-top: 8px;">
        <div v-if="store.tipAnalyzing" class="review-status">
          <el-icon class="is-loading"><Loading /></el-icon>
          <span>AI 分析中...</span>
        </div>
        <div v-if="store.tipLogs.length" class="review-logs">
          <div v-for="(log, i) in store.tipLogs" :key="i" class="log-line">{{ log }}</div>
        </div>
        <div v-if="store.tipAnalyzing && store.tipStreamText" class="review-report">
          <div class="report-content" v-html="renderMarkdown(store.tipStreamText)" />
        </div>
        <div v-else-if="store.tipResult?.report && !store.tipResult?.error" class="review-report">
          <div class="report-header">
            {{ store.tipResult.symbol }} {{ store.tipResult.name }} — {{ store.tipResult.date }}
            <el-tag v-if="store.tipResult.lesson" type="success" size="small" style="margin-left: 8px;">教訓已儲存 ★</el-tag>
            <el-tag v-else type="warning" size="small" style="margin-left: 8px;">未提取到教訓</el-tag>
          </div>
          <div class="report-content" v-html="renderMarkdown(store.tipResult.report)" />
        </div>
        <div v-else-if="store.tipResult?.error" class="review-error">
          {{ store.tipResult.error }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useOvernightStore } from '../stores/overnight'
import { Loading } from '@element-plus/icons-vue'
import dayjs from 'dayjs'

const store = useOvernightStore()
const router = useRouter()

const reviewDate = ref(dayjs().format('YYYY-MM-DD'))
const reviewLoading = ref(false)
const tipSymbol = ref('')
const tipDate = ref(dayjs().format('YYYY-MM-DD'))
const tipNotes = ref('')

onMounted(() => {
  store.fetchCandidates()
  store.fetchReviewDates()
})

function onDateChange() {
  store.fetchCandidates(store.currentDate)
}

function goDetail(item) {
  const route = router.resolve(`/stock/${item.stock_id}`)
  window.open(route.href, '_blank')
}

function formatTime(dt) {
  const d = new Date(dt)
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return `${d.getMonth() + 1}/${d.getDate()} ${hh}:${mm}`
}

function scoreType(score) {
  if (score >= 65) return 'success'
  if (score >= 40) return 'warning'
  return 'danger'
}

function entryLabel(entryType) {
  const map = {
    gap_up_open: '跳空高開',
    pullback_entry: '拉回建倉',
    open_follow_through: '延續開盤',
    limit_up_chase: '漲停追強',
  }
  return map[entryType] || entryType || ''
}

function outcomeLabel(outcome) {
  const map = {
    hit_target: '達標',
    hit_stop: '觸停損',
    gap_up_strong: '強跳空',
    gap_up: '小跳空',
    gap_down: '跳空低開',
    up: '收漲',
    down: '收跌',
    neutral: '持平',
  }
  return map[outcome] || outcome || ''
}

function outcomeClass(outcome) {
  if (['hit_target', 'gap_up_strong', 'gap_up', 'up'].includes(outcome)) return 'result-win'
  if (['hit_stop', 'gap_down', 'down'].includes(outcome)) return 'result-lose'
  return 'result-neutral'
}

async function loadReview() {
  reviewLoading.value = true
  try {
    await store.fetchDailyReview(reviewDate.value)
  } finally {
    reviewLoading.value = false
  }
}

async function runReview() {
  try {
    await store.dailyReview(reviewDate.value)
  } catch (e) {
    console.error(e)
  }
}

async function runTipAnalysis() {
  try {
    await store.analyzeTip(tipDate.value, tipSymbol.value.trim(), tipNotes.value.trim())
  } catch (e) {
    console.error(e)
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
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.header-actions {
  display: flex;
  gap: 6px;
  align-items: center;
}

.last-updated {
  font-size: 11px;
  color: #909399;
  white-space: nowrap;
}

.loading-wrap,
.empty-wrap {
  padding: 40px 0;
  text-align: center;
}

.candidate-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.stock-card {
  background: #fff;
  border-radius: 10px;
  padding: 12px 14px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
  cursor: pointer;
  transition: box-shadow 0.15s;
}

.stock-card:active {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

.ai-rejected {
  opacity: 0.6;
}

.card-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 6px;
}

.stock-info {
  display: flex;
  align-items: baseline;
  gap: 6px;
  flex-wrap: wrap;
}

.stock-symbol {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

.stock-name {
  font-size: 13px;
  color: #606266;
}

.stock-industry {
  font-size: 11px;
  color: #909399;
  background: #f5f7fa;
  padding: 1px 5px;
  border-radius: 4px;
}

.card-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 8px;
}

.card-prices {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 8px;
}

.price-item {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.price-item .label {
  font-size: 10px;
  color: #909399;
}

.price-item .value {
  font-size: 14px;
  font-weight: 500;
  color: #303133;
}

.price-buy  { color: #e6a23c; }
.price-up   { color: #f56c6c; }
.price-down { color: #67c23a; }

.card-strategy-text {
  font-size: 12px;
  color: #606266;
  line-height: 1.5;
  margin-bottom: 6px;
  padding: 6px 8px;
  background: #f5f7fa;
  border-radius: 6px;
}

.card-ai-reasoning {
  font-size: 12px;
  color: #606266;
  line-height: 1.4;
  margin-top: 4px;
}

.ai-reasoning-text {
  margin-bottom: 3px;
}

.ai-price-reasoning {
  color: #909399;
  margin-bottom: 3px;
}

.ai-warnings {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 3px;
}

.ai-warning-chip {
  background: #fef0f0;
  color: #f56c6c;
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 11px;
}

.card-result {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid #f0f0f0;
  font-size: 12px;
}

.result-label {
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 4px;
}

.result-win    { color: #f56c6c; background: #fef0f0; }
.result-lose   { color: #67c23a; background: #f0f9eb; }
.result-neutral{ color: #909399; background: #f5f7fa; }

.result-gap,
.result-close {
  color: #606266;
}

/* 檢討 & 明牌區塊 */
.review-card {
  margin-top: 16px;
  cursor: default;
}

.review-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  flex-wrap: wrap;
  gap: 6px;
}

.review-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
}

.review-controls {
  display: flex;
  gap: 6px;
  align-items: center;
}

.tip-hint {
  font-size: 11px;
  color: #909399;
}

.tip-form {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}

.review-status {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #909399;
  font-size: 13px;
  padding: 8px 0;
}

.review-logs {
  background: #f5f7fa;
  border-radius: 6px;
  padding: 8px;
  margin: 6px 0;
  font-size: 12px;
  color: #606266;
  max-height: 120px;
  overflow-y: auto;
}

.log-line {
  padding: 1px 0;
}

.review-report {
  margin-top: 8px;
}

.report-header {
  font-size: 12px;
  color: #909399;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

.report-content {
  font-size: 13px;
  line-height: 1.7;
  color: #303133;
  overflow-x: auto;
}

.report-content :deep(h3) {
  font-size: 14px;
  margin: 12px 0 6px;
  color: #303133;
}

.report-content :deep(h4) {
  font-size: 13px;
  margin: 8px 0 4px;
  color: #606266;
}

.report-content :deep(ul) {
  padding-left: 16px;
  margin: 4px 0;
}

.report-content :deep(strong) {
  color: #303133;
}

.review-error {
  color: #f56c6c;
  font-size: 13px;
  padding: 8px 0;
}
</style>
