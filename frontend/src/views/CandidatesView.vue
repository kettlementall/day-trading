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
          @change="store.fetchCandidates"
        />
        <span v-if="store.lastUpdatedAt" class="last-updated">{{ formatTime(store.lastUpdatedAt) }}</span>
      </div>
    </div>

    <!-- 盤前確認摘要 & 篩選 -->
    <div v-if="store.morningSummary.screened > 0" class="morning-summary">
      <div class="summary-stats">
        <span>候選 <strong>{{ store.morningSummary.total }}</strong> 檔</span>
        <span class="summary-divider">|</span>
        <span>盤前確認 <strong class="text-success">{{ store.morningSummary.confirmed }}</strong> 檔通過</span>
      </div>
      <el-radio-group v-model="store.morningFilter" size="small">
        <el-radio-button value="all">全部</el-radio-button>
        <el-radio-button value="confirmed">已確認</el-radio-button>
        <el-radio-button value="unconfirmed">未通過</el-radio-button>
      </el-radio-group>
    </div>

    <div v-if="store.loading" class="loading-wrap">
      <el-skeleton :rows="5" animated />
    </div>

    <div v-else-if="store.candidates.length === 0" class="empty-wrap">
      <el-empty description="今日尚無候選標的" />
    </div>

    <div v-else class="candidate-list">
      <div
        v-for="item in store.filteredCandidates"
        :key="item.id"
        class="stock-card"
        @click="goDetail(item)"
      >
        <div class="card-top">
          <div class="stock-info">
            <span class="stock-symbol">{{ item.stock.symbol }}</span>
            <span class="stock-name">{{ item.stock.name }}</span>
          </div>
          <el-tag size="small" :type="scoreType(item.score)">
            {{ item.score }} 分
          </el-tag>
        </div>

        <div class="card-prices">
          <div class="price-item">
            <span class="label">建議買入</span>
            <span class="value price-down">{{ item.suggested_buy }}</span>
          </div>
          <div class="price-item">
            <span class="label">目標獲利</span>
            <span class="value price-up">{{ item.target_price }}</span>
          </div>
          <div class="price-item">
            <span class="label">停損</span>
            <span class="value">{{ item.stop_loss }}</span>
          </div>
          <div class="price-item">
            <span class="label">風報比</span>
            <span class="value">{{ item.risk_reward_ratio }}</span>
          </div>
        </div>

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
            v-for="reason in (item.reasons || [])"
            :key="reason"
            size="small"
            effect="plain"
            round
          >
            {{ reason }}
          </el-tag>
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
            <span class="morning-label">盤前確認</span>
            <el-tag
              size="small"
              :type="item.morning_confirmed ? 'success' : 'warning'"
            >
              {{ item.morning_confirmed ? '通過確認' : '未通過' }}
              ({{ item.morning_score }}分)
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
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCandidateStore } from '../stores/candidates'

const store = useCandidateStore()
const router = useRouter()

onMounted(() => {
  store.fetchCandidates()
})

function goDetail(item) {
  router.push(`/stock/${item.stock_id}`)
}

function formatTime(dt) {
  const d = new Date(dt)
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return `${d.getMonth() + 1}/${d.getDate()} ${hh}:${mm}`
}

function scoreType(score) {
  if (score >= 80) return 'danger'
  if (score >= 60) return 'warning'
  return 'info'
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

.card-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 8px;
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
