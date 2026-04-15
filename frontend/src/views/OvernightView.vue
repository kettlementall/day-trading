<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">隔日沖候選標的</h1>
      <span v-if="store.lastUpdatedAt" class="last-updated">{{ formatTime(store.lastUpdatedAt) }}</span>
    </div>

    <div class="date-bar">
      <span class="date-label">建倉</span>
      <el-date-picker
        v-model="entryDate"
        type="date"
        format="MM/DD (dd)"
        value-format="YYYY-MM-DD"
        :clearable="false"
        size="small"
        style="width: 130px"
        @change="onEntryDateChange"
      />
      <span class="date-arrow">→ 出場</span>
      <span class="date-exit">{{ formatDate(store.currentDate) }}</span>
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
          <el-tag
            v-if="item.overnight_strategy"
            size="small"
            type="primary"
            effect="light"
            round
          >
            {{ entryLabel(item.overnight_strategy) }}
          </el-tag>
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
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useOvernightStore } from '../stores/overnight'
import dayjs from 'dayjs'

const store = useOvernightStore()
const router = useRouter()

// 建倉日（T+0）= store.currentDate（出場日 T+1）往前一個交易日
const entryDate = ref(prevTradingDay(store.currentDate))

onMounted(() => {
  store.fetchCandidates()
})

function onEntryDateChange() {
  store.currentDate = nextTradingDay(entryDate.value)
  store.fetchCandidates(store.currentDate)
}

/** 下一個交易日（略過週末） */
function nextTradingDay(dateStr) {
  let d = dayjs(dateStr).add(1, 'day')
  while (d.day() === 0 || d.day() === 6) d = d.add(1, 'day')
  return d.format('YYYY-MM-DD')
}

/** 前一個交易日（略過週末） */
function prevTradingDay(dateStr) {
  let d = dayjs(dateStr).subtract(1, 'day')
  while (d.day() === 0 || d.day() === 6) d = d.subtract(1, 'day')
  return d.format('YYYY-MM-DD')
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  return dayjs(dateStr).format('MM/DD (dd)')
}

function goDetail(item) {
  const r = router.resolve(`/stock/${item.stock_id}`)
  window.open(r.href, '_blank')
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
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.last-updated {
  font-size: 11px;
  color: #909399;
  white-space: nowrap;
}

.date-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  background: #fff;
  border-radius: 8px;
  padding: 8px 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.date-label {
  font-size: 12px;
  color: #606266;
  font-weight: 500;
}

.date-arrow {
  font-size: 12px;
  color: #909399;
}

.date-exit {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
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
</style>
