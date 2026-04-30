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
      <span class="date-exit">{{ store.tradeDate ? formatDate(store.tradeDate) : '—' }}</span>
    </div>

    <div v-if="store.loading" class="loading-wrap">
      <el-skeleton :rows="5" animated />
    </div>

    <div v-else-if="store.isHoliday" class="empty-wrap">
      <el-empty :description="'休市日' + (store.holidayName ? '（' + store.holidayName + '）' : '')" :image-size="120" />
    </div>

    <div v-else-if="store.candidates.length === 0" class="empty-wrap">
      <el-empty description="此交易日尚無隔日沖候選標的（12:50 後執行選股）" />
    </div>

    <div v-else class="candidate-list">
      <div
        v-for="item in store.sortedCandidates"
        :key="item.id"
        class="stock-card"
        :class="{ 'ai-rejected': item.ai_selected === false && item.ai_reasoning, 'pinned-card': store.isPinned(item.id) }"
        @click="toggleExpand(item.id)"
      >
        <!-- 頭部：股票資訊 + Haiku 分數 -->
        <div class="card-top">
          <div class="stock-info" @click.stop="goDetail(item)">
            <span class="stock-symbol">{{ item.stock.symbol }}</span>
            <span class="stock-name">{{ item.stock.name }}</span>
            <span class="stock-industry">{{ item.stock.industry }}</span>
          </div>
          <div style="display:flex;align-items:center;gap:6px">
            <button class="quote-btn" @click.stop="goQuote(item)" title="即時報價">💹</button>
            <button class="pin-btn" :class="{ pinned: store.isPinned(item.id) }" @click.stop="store.togglePin(item.id)" title="釘選">
              {{ store.isPinned(item.id) ? '📌' : '📍' }}
            </button>
            <el-tooltip :content="item.haiku_reasoning || ''" placement="top" :disabled="!item.haiku_reasoning">
              <el-tag size="small" :type="scoreType(item.score)">
                Haiku {{ item.score }}
              </el-tag>
            </el-tooltip>
            <span class="chevron" :class="{ expanded: isExpanded(item.id) }">›</span>
          </div>
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

        <!-- AI 排除原因（折疊外直接顯示） -->
        <div v-if="item.ai_selected === false && item.ai_reasoning" class="reject-reason">
          {{ item.ai_reasoning }}
        </div>

        <!-- 三個關鍵價格 + 監控調整 -->
        <div class="card-prices">
          <div class="price-item">
            <span class="label">建議買入</span>
            <span class="value price-buy">{{ item.suggested_buy ?? '—' }}</span>
          </div>
          <div class="price-item">
            <span class="label">目標</span>
            <span class="value price-up">
              <template v-if="item.monitor && item.monitor.current_target && item.monitor.current_target != item.target_price">
                <span class="price-adjusted">{{ item.monitor.current_target }}</span>
                <span class="price-original">{{ item.target_price }}</span>
              </template>
              <template v-else>{{ item.target_price ?? '—' }}</template>
            </span>
          </div>
          <div class="price-item">
            <span class="label">停損</span>
            <span class="value price-down">
              <template v-if="item.monitor && item.monitor.current_stop && item.monitor.current_stop != item.stop_loss">
                <span class="price-adjusted">{{ item.monitor.current_stop }}</span>
                <span class="price-original">{{ item.stop_loss }}</span>
              </template>
              <template v-else>{{ item.stop_loss ?? '—' }}</template>
            </span>
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

        <!-- 監控狀態 badge + 最新 AI 摘要（折疊外） -->
        <div v-if="item.monitor && monitorStatusVisible(item.monitor.status)" class="monitor-summary">
          <span
            class="monitor-badge"
            :class="monitorBadgeClass(item.monitor.status)"
          >
            {{ monitorStatusLabel(item.monitor.status) }}
            <span v-if="item.monitor.exit_time && isTerminalStatus(item.monitor.status)" class="monitor-time">
              {{ formatExitTime(item.monitor.exit_time) }}
            </span>
          </span>
          <span v-if="latestAdvice(item.monitor)" class="monitor-latest-advice" :class="'log-' + latestAdvice(item.monitor).action">
            {{ latestAdvice(item.monitor).time }} {{ monitorActionLabel(latestAdvice(item.monitor).action) }}：{{ latestAdvice(item.monitor).notes }}
          </span>
          <span v-if="item.monitor.ai_advice_log?.length > 1" class="monitor-advice-count" @click.stop="toggleExpand(item.id)">
            共 {{ item.monitor.ai_advice_log.length }} 筆判斷
          </span>
        </div>

        <!-- 折疊區域 -->
        <div v-show="isExpanded(item.id)">

        <!-- 監控 AI 調整紀錄（T+1 盤中） -->
        <div v-if="item.monitor && monitorStatusVisible(item.monitor.status) && item.monitor.ai_advice_log?.length" class="card-monitor">
          <div class="monitor-log">
            <span
              v-for="(log, i) in item.monitor.ai_advice_log"
              :key="i"
              class="monitor-log-item"
              :class="'log-' + log.action"
            >
              {{ log.time }} {{ monitorActionLabel(log.action) }} {{ log.notes }}
            </span>
          </div>
        </div>

        <!-- 結構化分析區塊 -->
        <div class="card-sections">

          <!-- 支撐 -->
          <div v-if="supportLevels(item).length" class="card-section">
            <span class="section-label label-support">支撐</span>
            <div class="section-levels">
              <span
                v-for="(lv, i) in supportLevels(item)"
                :key="i"
                class="key-level-chip level-support"
              >{{ lv.price }}<span class="chip-reason"> {{ lv.reason }}</span></span>
            </div>
          </div>

          <!-- 壓力 -->
          <div v-if="resistanceLevels(item).length" class="card-section">
            <span class="section-label label-resistance">壓力</span>
            <div class="section-levels">
              <span
                v-for="(lv, i) in resistanceLevels(item)"
                :key="i"
                class="key-level-chip level-resistance"
              >{{ lv.price }}<span class="chip-reason"> {{ lv.reason }}</span></span>
            </div>
          </div>

          <!-- 消息題材面選入理由 -->
          <div v-if="item.overnight_news_reason || item.haiku_reasoning" class="card-section">
            <span class="section-label label-news">消息題材</span>
            <p class="section-text">{{ item.overnight_news_reason || item.haiku_reasoning }}</p>
          </div>

          <!-- 基本面選入理由 -->
          <div v-if="item.overnight_fundamental_reason || item.ai_reasoning" class="card-section">
            <span class="section-label label-fundamental">基本面</span>
            <p class="section-text">{{ item.overnight_fundamental_reason || item.ai_reasoning }}</p>
          </div>

          <!-- 操作須知 -->
          <div v-if="item.overnight_reasoning || item.ai_price_reasoning || item.ai_warnings?.length" class="card-section">
            <span class="section-label label-operation">操作須知</span>
            <div class="section-operation">
              <p v-if="item.overnight_reasoning" class="section-text">{{ item.overnight_reasoning }}</p>
              <p v-if="item.ai_price_reasoning" class="section-text section-price">{{ item.ai_price_reasoning }}</p>
              <div v-if="item.ai_warnings?.length" class="ai-warnings">
                <span v-for="(w, i) in item.ai_warnings" :key="i" class="ai-warning-chip">⚠ {{ w }}</span>
              </div>
            </div>
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

        </div><!-- end 折疊區域 -->
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useOvernightStore } from '../stores/overnight'
import dayjs from 'dayjs'

const store = useOvernightStore()
const router = useRouter()

const expandedIds = reactive(new Set())

function isExpanded(id) {
  return expandedIds.has(id)
}

function toggleExpand(id) {
  if (expandedIds.has(id)) expandedIds.delete(id)
  else expandedIds.add(id)
}

// 建倉日（T+0）= store.currentDate（現在就是建倉日）
const entryDate = ref(store.currentDate)

onMounted(() => {
  store.fetchCandidates()
})

function onEntryDateChange() {
  store.currentDate = entryDate.value
  store.fetchCandidates(store.currentDate)
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  return dayjs(dateStr).format('MM/DD (dd)')
}

function goDetail(item) {
  const r = router.resolve(`/stock/${item.stock_id}`)
  window.open(r.href, '_blank')
}

function goQuote(item) {
  router.push({ path: '/quote', query: { symbol: item.stock.symbol } })
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

function monitorStatusVisible(status) {
  return ['holding', 'target_hit', 'stop_hit', 'closed'].includes(status)
}

function monitorStatusLabel(status) {
  const map = {
    holding: '持倉監控中',
    target_hit: '已達目標',
    stop_hit: '已觸停損',
    closed: '提前出場',
  }
  return map[status] || status
}

function monitorBadgeClass(status) {
  if (status === 'target_hit') return 'badge-win'
  if (status === 'stop_hit') return 'badge-lose'
  if (status === 'closed') return 'badge-exit'
  return 'badge-holding'
}

function monitorActionLabel(action) {
  return { hold: '維持', adjust: '調整', exit: '出場' }[action] || action
}

function isTerminalStatus(status) {
  return ['target_hit', 'stop_hit', 'trailing_stop', 'closed'].includes(status)
}

function formatExitTime(exitTime) {
  if (!exitTime) return ''
  const d = dayjs(exitTime)
  return d.format('HH:mm')
}

function latestAdvice(monitor) {
  const logs = monitor?.ai_advice_log
  if (!logs?.length) return null
  return logs[logs.length - 1]
}

function supportLevels(item) {
  return (item.overnight_key_levels || []).filter(l => l.type === 'support')
}

function resistanceLevels(item) {
  return (item.overnight_key_levels || []).filter(l => l.type === 'resistance')
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

.pinned-card {
  border-left: 3px solid #e6a23c !important;
}

.quote-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 16px;
  padding: 0 2px;
  line-height: 1;
  opacity: 0.4;
  transition: opacity 0.15s;
}
.quote-btn:hover { opacity: 1; }

.pin-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 16px;
  padding: 0 2px;
  line-height: 1;
  opacity: 0.4;
  transition: opacity 0.15s;
}

.pin-btn:hover,
.pin-btn.pinned {
  opacity: 1;
}

.chevron {
  font-size: 18px;
  color: #c0c4cc;
  line-height: 1;
  transform: rotate(90deg);
  display: inline-block;
  transition: transform 0.2s;
}

.chevron.expanded {
  transform: rotate(270deg);
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
  cursor: pointer;
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

.reject-reason {
  font-size: 12px;
  color: #f56c6c;
  line-height: 1.5;
  margin-bottom: 6px;
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

.card-sections {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 4px;
}

.card-section {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.section-label {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
  margin-top: 1px;
  letter-spacing: 0.5px;
}

.label-support     { background: #f0f9eb; color: #67c23a; }
.label-resistance  { background: #fef0f0; color: #f56c6c; }
.label-news        { background: #ecf5ff; color: #409eff; }
.label-fundamental { background: #fdf6ec; color: #e6a23c; }
.label-operation   { background: #f5f7fa; color: #606266; }

.section-levels {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.key-level-chip {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 4px;
}

.level-support    { background: #f0f9eb; color: #67c23a; }
.level-resistance { background: #fef0f0; color: #f56c6c; }

.chip-reason {
  font-weight: 400;
  opacity: 0.8;
}

.section-text {
  font-size: 12px;
  color: #606266;
  line-height: 1.5;
  margin: 0;
}

.section-price {
  color: #909399;
  margin-top: 3px;
}

.section-operation {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.ai-warnings {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 2px;
}

.ai-warning-chip {
  background: #fef0f0;
  color: #f56c6c;
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 11px;
}

.price-adjusted {
  font-weight: 600;
}

.price-original {
  font-size: 11px;
  color: #c0c4cc;
  text-decoration: line-through;
  margin-left: 3px;
}

.card-monitor {
  margin-top: 6px;
  padding: 6px 8px;
  background: #f5f7fa;
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.monitor-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 4px;
  display: inline-block;
}

.monitor-summary {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 4px;
}

.monitor-latest-advice {
  font-size: 11px;
  line-height: 1.4;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.monitor-advice-count {
  font-size: 10px;
  color: #909399;
  white-space: nowrap;
  text-decoration: underline;
  cursor: pointer;
}

.monitor-time {
  font-weight: 400;
  opacity: 0.75;
  margin-left: 2px;
}

.badge-holding { background: #ecf5ff; color: #409eff; }
.badge-win     { background: #fef0f0; color: #f56c6c; }
.badge-lose    { background: #f0f9eb; color: #67c23a; }
.badge-exit    { background: #fdf6ec; color: #e6a23c; }

.monitor-log {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.monitor-log-item {
  font-size: 11px;
  color: #606266;
  line-height: 1.4;
}

.log-adjust { color: #e6a23c; }
.log-exit   { color: #f56c6c; }
.log-hold   { color: #909399; }

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
