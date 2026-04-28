<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">當沖候選標的</h1>
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

    <!-- 市場指數 -->
    <div v-if="store.usIndices.length" class="us-indices-bar">
      <span
        v-for="idx in store.usIndices"
        :key="idx.symbol"
        class="us-index-item"
        :class="{ 'us-index-highlight': idx.symbol === 'TX' }"
      >
        <span class="us-index-name">{{ idx.name }}</span>
        <span :class="idx.change_percent >= 0 ? 'price-up' : 'price-down'">
          {{ idx.change_percent >= 0 ? '+' : '' }}{{ idx.change_percent }}%
        </span>
      </span>
    </div>

    <!-- 盤前確認摘要 & 篩選 -->
    <div v-if="authStore.intradayEnabled && store.morningSummary.screened > 0" class="morning-summary">
      <div class="summary-stats">
        <span>候選 <strong>{{ store.morningSummary.total }}</strong> 檔</span>
        <span class="summary-divider">|</span>
        <span>校準
          <strong class="text-success">A:{{ store.morningSummary.gradeA }}</strong>
          <strong class="text-primary">B:{{ store.morningSummary.gradeB }}</strong>
          <strong class="text-warning">C:{{ store.morningSummary.gradeC }}</strong>
          <strong class="text-danger">D:{{ store.morningSummary.gradeD }}</strong>
        </span>
      </div>
      <el-radio-group v-model="store.morningFilter" size="small">
        <el-radio-button value="all">全部</el-radio-button>
        <el-radio-button value="AB">A+B</el-radio-button>
        <el-radio-button value="C">C 觀察</el-radio-button>
        <el-radio-button value="D">D 放棄</el-radio-button>
      </el-radio-group>
    </div>

    <!-- 盤中即時監控面板 -->
    <div v-if="authStore.intradayEnabled && store.monitors.length > 0" class="monitor-panel">
      <div class="monitor-header">
        <h3 class="monitor-title">盤中監控</h3>
        <span class="monitor-count">
          持有 <strong>{{ store.monitors.filter(m => m.status === 'holding').length }}</strong>
          觀望 <strong>{{ store.monitors.filter(m => m.status === 'watching').length }}</strong>
          完成 <strong>{{ store.completedMonitors.length }}</strong>
        </span>
      </div>
      <div class="monitor-list">
        <div
          v-for="m in sortedMonitors"
          :key="m.id"
          class="monitor-item"
          :class="['monitor-' + m.status, { 'monitor-finished': isFinished(m.status) }]"
        >
          <!-- 已結束：收合一行 -->
          <template v-if="isFinished(m.status)">
            <div class="monitor-finished-row">
              <span class="stock-symbol">{{ m.symbol }}</span>
              <span class="stock-name">{{ m.name }}</span>
              <el-tag size="small" :type="monitorStatusType(m.status)" round>{{ monitorStatusLabel(m.status) }}</el-tag>
              <span v-if="m.profit_pct !== null" class="finished-pnl" :class="m.profit_pct >= 0 ? 'price-up' : 'price-down'">
                {{ m.profit_pct >= 0 ? '+' : '' }}{{ m.profit_pct }}%
              </span>
              <span v-if="m.entry_price" class="finished-detail">{{ m.entry_price }}→{{ m.exit_price || '-' }}</span>
              <span v-if="m.entry_time" class="finished-detail">{{ m.entry_time }}-{{ m.exit_time || '-' }}</span>
              <span v-if="m.exit_reason" class="finished-reason">{{ m.exit_reason }}</span>
            </div>
          </template>

          <!-- 跳過：收合一行 -->
          <template v-else-if="m.status === 'skipped'">
            <div class="monitor-finished-row">
              <span class="stock-symbol">{{ m.symbol }}</span>
              <span class="stock-name">{{ m.name }}</span>
              <el-tag size="small" type="info" round>跳過</el-tag>
              <span class="finished-detail">{{ m.skip_reason }}</span>
            </div>
          </template>

          <!-- HOLDING：持有中 -->
          <template v-else-if="m.status === 'holding' || m.status === 'entry_signal'">
            <div class="monitor-item-top">
              <div class="monitor-stock">
                <span class="stock-symbol">{{ m.symbol }}</span>
                <span class="stock-name">{{ m.name }}</span>
                <el-tag size="small" class="strategy-tag">{{ strategyLabel(m.strategy) }}</el-tag>
              </div>
              <div class="monitor-tags">
                <el-tag v-if="m.limit_up" size="small" type="danger" round>漲停</el-tag>
                <el-tag size="small" :type="monitorStatusType(m.status)" round>
                  {{ monitorStatusLabel(m.status) }}
                  <template v-if="m.holding_minutes"> {{ m.holding_minutes }}分</template>
                </el-tag>
              </div>
            </div>
            <!-- 大字損益 -->
            <div class="holding-hero">
              <span class="holding-pnl" :class="m.profit_pct >= 0 ? 'price-up' : 'price-down'">
                {{ m.profit_pct >= 0 ? '+' : '' }}{{ m.profit_pct }}%
              </span>
              <span class="holding-price" :class="{ 'price-limit-up': m.limit_up }">{{ m.current_price }}</span>
            </div>
            <!-- 進度條：停損 → 現價 → 目標 -->
            <div v-if="holdingGaugePct(m) !== null" class="holding-gauge">
              <div class="gauge-labels">
                <span class="price-down">停損 {{ m.current_stop }}</span>
                <span>進場 {{ m.entry_price }}</span>
                <span class="price-up">目標 {{ m.current_target }}</span>
              </div>
              <div class="gauge-bar">
                <div class="gauge-fill" :style="{ width: holdingGaugePct(m) + '%' }"
                     :class="m.profit_pct >= 0 ? 'gauge-profit' : 'gauge-loss'"></div>
                <div class="gauge-marker" :style="{ left: holdingGaugePct(m) + '%' }"></div>
              </div>
              <div class="gauge-dist">
                <span class="price-down">距停損 {{ m.dist_stop_pct }}%</span>
                <span class="price-up">距目標 {{ m.dist_target_pct }}%</span>
              </div>
            </div>
            <!-- AI 評語 -->
            <div v-if="m.last_ai_advice && m.last_ai_advice.action !== 'skip'" class="monitor-ai" @click="toggleAi(m.id)">
              <span class="monitor-ai-label">AI {{ m.last_ai_advice.time?.substring(0,5) || '' }}</span>
              <span v-if="m.last_ai_advice.adjustments?.target" class="monitor-ai-adj price-up">目標→{{ m.last_ai_advice.adjustments.target }}</span>
              <span v-if="m.last_ai_advice.adjustments?.stop" class="monitor-ai-adj price-down">停損→{{ m.last_ai_advice.adjustments.stop }}</span>
              <div class="monitor-ai-notes" :class="{ expanded: expandedAiIds.has(m.id) }">{{ m.last_ai_advice.notes }}</div>
            </div>
          </template>

          <!-- WATCHING：觀望中 -->
          <template v-else>
            <div class="monitor-item-top">
              <div class="monitor-stock">
                <span class="stock-symbol">{{ m.symbol }}</span>
                <span class="stock-name">{{ m.name }}</span>
                <el-tag size="small" class="strategy-tag">{{ strategyLabel(m.strategy) }}</el-tag>
              </div>
              <div class="monitor-tags">
                <el-tag v-if="m.limit_up" size="small" type="danger" round>漲停</el-tag>
                <el-tag size="small" :type="monitorStatusType(m.status)" round>{{ monitorStatusLabel(m.status) }}</el-tag>
              </div>
            </div>
            <div class="watching-body">
              <div class="watching-price">
                <span class="watching-current" :class="{ 'price-limit-up': m.limit_up, 'price-limit-down': m.limit_down }">{{ m.current_price || '-' }}</span>
              </div>
              <div class="watching-condition">
                <span class="watching-trigger">{{ m.entry_trigger }}</span>
              </div>
              <div class="watching-levels">
                <span v-if="m.dist_stop_pct !== null" class="price-down">支撐 {{ m.current_stop }}（{{ m.dist_stop_pct }}%）</span>
                <span v-if="m.dist_target_pct !== null" class="price-up">壓力 {{ m.current_target }}（{{ m.dist_target_pct }}%）</span>
              </div>
            </div>
            <!-- AI 評語 -->
            <div v-if="m.last_ai_advice && m.last_ai_advice.action !== 'skip'" class="monitor-ai" @click="toggleAi(m.id)">
              <span class="monitor-ai-label">AI {{ m.last_ai_advice.time?.substring(0,5) || '' }}</span>
              <span v-if="m.last_ai_advice.adjustments?.target" class="monitor-ai-adj price-up">目標→{{ m.last_ai_advice.adjustments.target }}</span>
              <span v-if="m.last_ai_advice.adjustments?.stop" class="monitor-ai-adj price-down">停損→{{ m.last_ai_advice.adjustments.stop }}</span>
              <div class="monitor-ai-notes" :class="{ expanded: expandedAiIds.has(m.id) }">{{ m.last_ai_advice.notes }}</div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <div v-if="store.loading" class="loading-wrap">
      <el-skeleton :rows="5" animated />
    </div>

    <div v-else-if="store.isHoliday" class="empty-wrap">
      <el-empty :description="'今日休市' + (store.holidayName ? '（' + store.holidayName + '）' : '')" :image-size="120" />
    </div>

    <div v-else-if="store.candidates.length === 0" class="empty-wrap">
      <el-empty description="今日尚無候選標的" />
    </div>

    <div v-else class="candidate-list">
      <div
        v-for="item in store.filteredCandidates"
        :key="item.id"
        class="stock-card"
        :class="{ 'ai-rejected': item.ai_selected === false && item.ai_reasoning, 'pinned-card': store.isPinned(item.id) }"
        @click="toggleExpand(item.id)"
      >
        <div class="card-top">
          <div class="stock-info" @click.stop="goDetail(item)">
            <span class="stock-symbol">{{ item.stock.symbol }}</span>
            <span class="stock-name">{{ item.stock.name }}</span>
          </div>
          <div style="display:flex;align-items:center;gap:6px">
            <button class="quote-btn" @click.stop="goQuote(item)" title="即時報價">💹</button>
            <button class="pin-btn" :class="{ pinned: store.isPinned(item.id) }" @click.stop="store.togglePin(item.id)" title="釘選">
              {{ store.isPinned(item.id) ? '📌' : '📍' }}
            </button>
            <el-tooltip
              :content="item.haiku_reasoning || ''"
              placement="top"
              :disabled="!item.haiku_reasoning"
            >
              <el-tag size="small" :type="scoreType(item.score)">
                Haiku {{ item.score }}
              </el-tag>
            </el-tooltip>
            <span class="chevron" :class="{ expanded: isExpanded(item.id) }">›</span>
          </div>
        </div>

        <div class="card-prices">
          <div class="price-item">
            <span class="label">建議買入</span>
            <span class="value price-down">{{ item.suggested_buy }}</span>
          </div>
          <div class="price-item">
            <span class="label">目標獲利</span>
            <span class="value price-up">
              <template v-if="item.monitor && item.monitor.current_target && item.monitor.current_target != item.target_price">
                <span class="price-adjusted">{{ item.monitor.current_target }}</span>
                <span class="price-original">{{ item.target_price }}</span>
              </template>
              <template v-else>{{ item.target_price }}</template>
            </span>
          </div>
          <div class="price-item">
            <span class="label">停損</span>
            <span class="value price-down">
              <template v-if="item.monitor && item.monitor.current_stop && item.monitor.current_stop != item.stop_loss">
                <span class="price-adjusted">{{ item.monitor.current_stop }}</span>
                <span class="price-original">{{ item.stop_loss }}</span>
              </template>
              <template v-else>{{ item.stop_loss }}</template>
            </span>
          </div>
          <div class="price-item">
            <span class="label">風報比</span>
            <span class="value">{{ item.risk_reward_ratio }}</span>
          </div>
        </div>

        <!-- 折疊區域 -->
        <div v-show="isExpanded(item.id)">

        <div class="card-tags">
          <el-tag
            v-if="item.strategy_type"
            size="small"
            :type="item.strategy_type === 'bounce' ? 'warning' : 'success'"
            round
          >
            {{ item.strategy_type === 'bounce' ? '跌深反彈' : '突破追多' }}
          </el-tag>
          <el-tag
            v-if="item.intraday_strategy && item.intraday_strategy !== item.strategy_type"
            size="small"
            type="primary"
            round
          >
            {{ item.intraday_strategy }}
          </el-tag>
          <el-tag
            v-if="item.ai_selected"
            size="small"
            type="success"
            effect="dark"
            round
          >
            AI 選入{{ item.ai_score_adjustment ? ` (${item.ai_score_adjustment > 0 ? '+' : ''}${item.ai_score_adjustment})` : '' }}
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

        <!-- AI 選股理由 -->
        <div v-if="item.ai_reasoning" class="card-ai-reasoning">
          <div class="ai-reasoning-text">{{ item.ai_reasoning }}</div>
          <div v-if="item.ai_price_reasoning" class="ai-price-reasoning">
            {{ item.ai_price_reasoning }}
          </div>
          <div v-if="item.ai_warnings" class="ai-warnings">
            <span v-for="(w, i) in (Array.isArray(item.ai_warnings) ? item.ai_warnings : [item.ai_warnings])" :key="i" class="ai-warning-chip">
              {{ w }}
            </span>
          </div>
          <div v-if="item.reference_support || item.reference_resistance" class="ai-ref-prices">
            <span v-if="item.reference_support" class="ref-price">支撐 {{ item.reference_support }}</span>
            <span v-if="item.reference_resistance" class="ref-price">壓力 {{ item.reference_resistance }}</span>
          </div>
        </div>

        <!-- 策略細節 -->
        <div v-if="item.strategy_detail && Object.keys(item.strategy_detail).length > 0" class="card-strategy">
          <span v-if="item.strategy_detail.washout_drop_pct" class="strategy-chip">
            急跌 {{ item.strategy_detail.washout_drop_pct }}%
          </span>
          <span v-if="item.strategy_detail.two_day_drop_pct" class="strategy-chip">
            兩日跌 {{ item.strategy_detail.two_day_drop_pct }}%
          </span>
          <span v-if="item.strategy_detail.bounce_from_low_pct" class="strategy-chip">
            反彈 {{ item.strategy_detail.bounce_from_low_pct }}%
          </span>
          <span v-if="item.strategy_detail.prev_5d_high" class="strategy-chip">
            前高 {{ item.strategy_detail.prev_5d_high }}
          </span>
          <span v-if="item.strategy_detail.avg_amplitude_10d" class="strategy-chip">
            10日振幅 {{ item.strategy_detail.avg_amplitude_10d }}%
          </span>
          <span v-if="item.strategy_detail.gain_20d" class="strategy-chip">
            20日漲 {{ item.strategy_detail.gain_20d }}%
          </span>
          <span v-if="item.strategy_detail.foreign_net_ratio" class="strategy-chip">
            外資佔比 {{ item.strategy_detail.foreign_net_ratio }}%
          </span>
          <span v-if="item.strategy_detail.dealer_net_ratio" class="strategy-chip">
            自營佔比 {{ item.strategy_detail.dealer_net_ratio }}%
          </span>
          <span
            v-if="item.strategy_detail.news_factor && item.strategy_detail.news_factor !== 1"
            class="strategy-chip"
            :class="item.strategy_detail.news_factor > 1 ? 'chip-bullish' : 'chip-bearish'"
          >
            消息面 {{ item.strategy_detail.news_factor > 1 ? '+' : '' }}{{ Math.round((item.strategy_detail.news_factor - 1) * 100) }}%
          </span>
          <span v-if="item.strategy_detail.news_sentiment" class="strategy-chip">
            情緒 {{ item.strategy_detail.news_sentiment }}
          </span>
          <span v-if="item.strategy_detail.news_industry_sentiment" class="strategy-chip">
            產業情緒 {{ item.strategy_detail.news_industry_sentiment }}
          </span>
          <span v-if="item.strategy_detail.news_panic >= 60" class="strategy-chip chip-bearish">
            恐慌 {{ item.strategy_detail.news_panic }}
          </span>
        </div>

        <!-- 盤前確認信號 -->
        <div v-if="item.morning_signals && item.morning_signals.length > 0" class="card-morning">
          <el-divider style="margin: 8px 0" />
          <div class="morning-header">
            <span class="morning-label">盤前校準</span>
            <el-tag
              size="small"
              :type="gradeTagType(item.morning_grade)"
            >
              {{ gradeLabel(item.morning_grade) }}
            </el-tag>
          </div>
          <div class="morning-signals">
            <div
              v-for="signal in item.morning_signals"
              :key="signal.rule"
              class="signal-item"
              :class="signal.passed ? 'signal-pass' : 'signal-fail'"
            >
              <span class="signal-icon">{{ signal.passed ? '✓' : '✗' }}</span>
              <span class="signal-rule">{{ signal.rule }}</span>
              <span class="signal-detail">{{ signal.detail }}</span>
            </div>
          </div>
        </div>

        <!-- 盤後結果 -->
        <div v-if="item.result" class="card-result">
          <el-divider style="margin: 8px 0" />
          <div class="result-row">
            <span>實際表現：</span>
            <el-tag
              size="small"
              :type="item.result.hit_target ? 'success' : 'danger'"
            >
              {{ item.result.hit_target ? '達標' : '未達標' }}
            </el-tag>
            <span class="result-profit" :class="item.result.max_profit_percent > 0 ? 'price-up' : 'price-down'">
              最高 {{ item.result.max_profit_percent }}%
            </span>
          </div>
        </div>

        </div><!-- end 折疊區域 -->
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useCandidateStore } from '../stores/candidates'
import { useAuthStore } from '../stores/auth'

const store = useCandidateStore()
const authStore = useAuthStore()
const router = useRouter()

const monitorStatusOrder = { holding: 0, entry_signal: 1, watching: 2, target_hit: 3, trailing_stop: 4, stop_hit: 5, closed: 6, skipped: 7, pending: 8 }
const sortedMonitors = computed(() =>
  [...store.monitors].sort((a, b) => (monitorStatusOrder[a.status] ?? 9) - (monitorStatusOrder[b.status] ?? 9))
)
const expandedAiIds = reactive(new Set())
function toggleAi(id) {
  if (expandedAiIds.has(id)) expandedAiIds.delete(id)
  else expandedAiIds.add(id)
}

const expandedIds = reactive(new Set())

function isExpanded(id) {
  return expandedIds.has(id)
}

function toggleExpand(id) {
  if (expandedIds.has(id)) expandedIds.delete(id)
  else expandedIds.add(id)
}

function isMarketHours() {
  const now = new Date()
  const h = now.getHours()
  const m = now.getMinutes()
  return (h === 9 || h === 10 || h === 11 || h === 12 || (h === 13 && m <= 30))
}

onMounted(() => {
  store.fetchCandidates()
  if (authStore.intradayEnabled) {
    if (isMarketHours()) {
      store.startMonitorPolling(store.currentDate)
    } else {
      store.fetchMonitors(store.currentDate)
    }
  }
})

onUnmounted(() => {
  store.stopMonitorPolling()
})

function onDateChange() {
  store.stopMonitorPolling()
  store.fetchCandidates()
  if (authStore.intradayEnabled) {
    store.fetchMonitors(store.currentDate)
  }
}


function goDetail(item) {
  const route = router.resolve(`/stock/${item.stock_id}`)
  window.open(route.href, '_blank')
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

function monitorStatusType(status) {
  const map = {
    pending: 'info', watching: '', entry_signal: 'warning',
    holding: 'success', target_hit: 'success', stop_hit: 'danger',
    trailing_stop: 'warning', closed: 'info', skipped: 'info',
  }
  return map[status] || 'info'
}

function skipTime(m) {
  if (m.last_ai_advice?.time) return m.last_ai_advice.time.substring(0, 5)
  if (m.updated_at) {
    const d = new Date(m.updated_at)
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
  }
  return ''
}

function monitorStatusLabel(status) {
  const map = {
    pending: '等待校準', watching: '觀望中', entry_signal: '進場訊號',
    holding: '持有中', target_hit: '達標', stop_hit: '停損',
    trailing_stop: '停利', closed: '收盤平倉', skipped: '已跳過',
  }
  return map[status] || status
}

function strategyLabel(s) {
  const map = { breakout_fresh: '突破', breakout_retest: '回測', gap_pullback: '跳空回拉', bounce: '反彈', momentum: '動能', gap_reversal: '超跌反轉' }
  return map[s] || s || ''
}

function isFinished(status) {
  return ['target_hit', 'stop_hit', 'trailing_stop', 'closed'].includes(status)
}

function holdingGaugePct(m) {
  if (!m.entry_price || !m.current_stop || !m.current_target) return null
  const entry = parseFloat(m.entry_price)
  const stop = parseFloat(m.current_stop)
  const target = parseFloat(m.current_target)
  const price = parseFloat(m.current_price)
  const range = target - stop
  if (range <= 0) return null
  return Math.max(0, Math.min(100, ((price - stop) / range) * 100))
}

function gradeTagType(grade) {
  return { A: 'success', B: '', C: 'warning', D: 'danger' }[grade] || 'info'
}

function gradeLabel(grade) {
  return { A: 'A 強力推薦', B: 'B 標準進場', C: 'C 觀察', D: 'D 放棄' }[grade] || '未校準'
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
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

.us-indices-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  padding: 8px 12px;
  background: #f5f7fa;
  border-radius: 6px;
  margin-bottom: 10px;
  font-size: 12px;
}

.us-index-item {
  display: flex;
  gap: 4px;
  align-items: center;
}

.us-index-name {
  color: #909399;
}

.us-index-highlight {
  font-weight: 600;
}

.us-index-highlight .us-index-name {
  color: #303133;
}

.morning-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
  margin-bottom: 10px;
  background: #f5f7fa;
  border-radius: 8px;
  font-size: 13px;
}

.summary-stats {
  display: flex;
  align-items: center;
  gap: 6px;
}

.summary-divider {
  color: #dcdfe6;
}

.text-success {
  color: #67c23a;
}

.card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.stock-info {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.stock-symbol {
  font-size: 16px;
  font-weight: 700;
}

.stock-name {
  font-size: 13px;
  color: #606266;
}

.card-prices {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
  text-align: center;
}

.price-item .label {
  display: block;
  font-size: 11px;
  color: #909399;
}

.price-item .value {
  display: block;
  font-size: 15px;
  font-weight: 600;
}

.price-adjusted {
  font-weight: 600;
}

.price-original {
  font-size: 11px;
  color: #c0c4cc;
  text-decoration: line-through;
  margin-left: 4px;
}

.card-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 8px;
}

.ai-rejected {
  opacity: 0.55;
  border-left: 3px solid #f56c6c !important;
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

.stock-info {
  cursor: pointer;
}
.ai-rejected .card-ai-reasoning {
  background: #fef0f0;
  border-left-color: #f56c6c;
}
.card-ai-reasoning {
  margin-top: 8px;
  padding: 8px 10px;
  background: #f0f5ff;
  border-radius: 6px;
  border-left: 3px solid #409eff;
}
.ai-reasoning-text {
  font-size: 12px;
  color: #303133;
  line-height: 1.5;
}
.ai-price-reasoning {
  margin-top: 4px;
  font-size: 11px;
  color: #606266;
  font-style: italic;
}
.ai-warnings {
  margin-top: 4px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.ai-warning-chip {
  display: inline-block;
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 11px;
  color: #e6a23c;
  background: #fdf6ec;
  border: 1px solid #f5dab1;
}
.ai-ref-prices {
  margin-top: 4px;
  display: flex;
  gap: 12px;
}
.ref-price {
  font-size: 11px;
  color: #909399;
}

.card-strategy {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 6px;
}

.strategy-chip {
  display: inline-block;
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 11px;
  color: #606266;
  background: #f0f2f5;
}

.chip-bullish {
  color: #67c23a;
  background: #f0f9eb;
}

.chip-bearish {
  color: #f56c6c;
  background: #fef0f0;
}

.card-result {
  font-size: 13px;
}

.result-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.result-profit {
  font-weight: 600;
}

.card-morning {
  font-size: 13px;
}

.morning-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.morning-label {
  font-weight: 600;
  font-size: 13px;
}

.morning-signals {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.signal-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  line-height: 1.6;
}

.signal-icon {
  font-weight: 700;
  width: 14px;
  flex-shrink: 0;
}

.signal-rule {
  font-weight: 600;
  white-space: nowrap;
}

.signal-detail {
  color: #606266;
}

.signal-pass .signal-icon {
  color: #67c23a;
}

.signal-fail .signal-icon {
  color: #f56c6c;
}

.monitor-panel {
  margin-bottom: 12px;
  padding: 12px 14px;
  background: #ecf5ff;
  border-radius: 8px;
  border: 1px solid #d9ecff;
}

.monitor-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.monitor-title {
  font-size: 14px;
  font-weight: 700;
  margin: 0;
}

.monitor-count {
  font-size: 12px;
  color: #606266;
  display: flex;
  gap: 8px;
}

.monitor-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.monitor-item {
  background: #fff;
  border-radius: 6px;
  padding: 10px 12px;
  border-left: 3px solid #dcdfe6;
}
.monitor-item.monitor-finished {
  padding: 6px 12px;
  opacity: 0.75;
}

.monitor-item.monitor-watching { border-left-color: #409eff; }
.monitor-item.monitor-entry_signal { border-left-color: #e6a23c; }
.monitor-item.monitor-holding { border-left-color: #67c23a; }
.monitor-item.monitor-target_hit { border-left-color: #67c23a; }
.monitor-item.monitor-stop_hit { border-left-color: #f56c6c; }
.monitor-item.monitor-trailing_stop { border-left-color: #e6a23c; }
.monitor-item.monitor-skipped { border-left-color: #dcdfe6; padding: 6px 12px; opacity: 0.6; }

.monitor-tags {
  display: flex;
  gap: 4px;
}

.strategy-tag {
  margin-left: 6px;
  font-size: 11px !important;
}

.price-limit-up {
  color: #f56c6c !important;
  animation: limit-pulse 1.5s ease-in-out infinite;
}
.price-limit-down {
  color: #67c23a !important;
  animation: limit-pulse 1.5s ease-in-out infinite;
}
@keyframes limit-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.monitor-item-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}

/* 已結束/跳過 一行式 */
.monitor-finished-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}
.monitor-finished-row .stock-name { color: #909399; }
.finished-pnl { font-weight: 700; font-size: 14px; margin-left: auto; }
.finished-detail { font-size: 12px; color: #909399; }
.finished-reason { font-size: 11px; color: #b0b3b8; font-style: italic; }

/* HOLDING 大字損益 */
.holding-hero {
  display: flex;
  align-items: baseline;
  gap: 12px;
  margin-bottom: 8px;
}
.holding-pnl {
  font-size: 24px;
  font-weight: 700;
  letter-spacing: -0.5px;
}
.holding-price {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

/* 進度條 gauge */
.holding-gauge {
  margin-bottom: 6px;
}
.gauge-labels {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: #909399;
  margin-bottom: 3px;
}
.gauge-bar {
  position: relative;
  height: 6px;
  background: #f0f0f0;
  border-radius: 3px;
  overflow: visible;
}
.gauge-fill {
  height: 100%;
  border-radius: 3px;
  transition: width 0.3s ease;
}
.gauge-fill.gauge-profit { background: linear-gradient(90deg, #e6a23c, #67c23a); }
.gauge-fill.gauge-loss { background: linear-gradient(90deg, #f56c6c, #e6a23c); }
.gauge-marker {
  position: absolute;
  top: -3px;
  width: 3px;
  height: 12px;
  background: #303133;
  border-radius: 2px;
  transform: translateX(-50%);
}
.gauge-dist {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  margin-top: 2px;
}

/* WATCHING */
.watching-body {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}
.watching-current {
  font-size: 18px;
  font-weight: 700;
  color: #303133;
}
.watching-condition {
  flex: 1;
}
.watching-trigger {
  font-size: 12px;
  color: #606266;
  background: #f5f7fa;
  padding: 2px 8px;
  border-radius: 4px;
}
.watching-levels {
  display: flex;
  gap: 12px;
  font-size: 12px;
}

/* AI 評語 */
.monitor-ai {
  margin-top: 6px;
  font-size: 12px;
  color: #409eff;
  cursor: pointer;
}
.monitor-ai-label {
  font-weight: 600;
}
.monitor-ai-adj {
  margin-left: 6px;
  font-weight: 600;
  font-size: 11px;
}
.monitor-ai-notes {
  margin-top: 3px;
  color: #606266;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.monitor-ai-notes.expanded {
  -webkit-line-clamp: unset;
  overflow: visible;
}

.loading-wrap, .empty-wrap {
  padding: 40px 0;
}

@media (max-width: 380px) {
  .card-prices {
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
  }
}
</style>
