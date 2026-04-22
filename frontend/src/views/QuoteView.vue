<template>
  <div class="page">
    <h2 class="page-title">即時報價</h2>

    <!-- 搜尋列 -->
    <div class="search-bar">
      <el-input
        v-model="symbolInput"
        placeholder="輸入股票代號，例如 2330"
        clearable
        @keyup.enter="fetchQuote"
        style="flex: 1"
      >
        <template #prefix>
          <el-icon><Search /></el-icon>
        </template>
      </el-input>
      <el-button type="primary" :loading="loading" @click="fetchQuote">查詢</el-button>
    </div>

    <!-- 報價卡片 -->
    <template v-if="quote">
      <div class="quote-card">
        <div class="quote-header">
          <div class="quote-title">
            <span class="symbol">{{ quote.symbol }}</span>
            <span class="name">{{ quote.name }}</span>
            <el-tag v-if="quote.is_close" size="small" type="info">已收盤</el-tag>
            <el-tag v-if="quote.source === 'db'" size="small" type="warning">快照</el-tag>
          </div>
          <div class="quote-price" :class="priceClass">
            <span class="current-price">{{ quote.close.toFixed(2) }}</span>
            <span class="change-pct">{{ quote.change_pct >= 0 ? '+' : '' }}{{ quote.change_pct }}%</span>
          </div>
        </div>

        <!-- OHLC 資訊 -->
        <div class="ohlc-grid">
          <div class="ohlc-item">
            <span class="label">昨收</span>
            <span>{{ quote.prev_close.toFixed(2) }}</span>
          </div>
          <div class="ohlc-item">
            <span class="label">開盤</span>
            <span :class="quote.open > quote.prev_close ? 'up' : quote.open < quote.prev_close ? 'down' : ''">
              {{ quote.open.toFixed(2) }}
            </span>
          </div>
          <div class="ohlc-item">
            <span class="label">最高</span>
            <span class="up">{{ quote.high.toFixed(2) }}</span>
          </div>
          <div class="ohlc-item">
            <span class="label">最低</span>
            <span class="down">{{ quote.low.toFixed(2) }}</span>
          </div>
          <div class="ohlc-item">
            <span class="label">成交量</span>
            <span>{{ formatNumber(quote.volume) }} 張</span>
          </div>
          <div class="ohlc-item">
            <span class="label">成交筆</span>
            <span>{{ formatNumber(quote.transaction) }}</span>
          </div>
          <div class="ohlc-item">
            <span class="label">外盤比</span>
            <span :class="quote.external_ratio > 55 ? 'up' : quote.external_ratio < 45 ? 'down' : ''">
              {{ quote.external_ratio }}%
            </span>
          </div>
          <div class="ohlc-item">
            <span class="label">振幅</span>
            <span>{{ amplitude }}%</span>
          </div>
        </div>
      </div>

      <!-- 五檔 -->
      <div class="quote-card orderbook">
        <h3>五檔報價</h3>
        <div class="orderbook-grid">
          <div class="ob-side ob-ask">
            <div class="ob-row" v-for="(a, i) in quote.asks" :key="'a'+i">
              <span class="ob-label">賣{{ i+1 }}</span>
              <span class="ob-price up">{{ a.price.toFixed(2) }}</span>
              <span class="ob-size">{{ a.size }}</span>
            </div>
          </div>
          <div class="ob-side ob-bid">
            <div class="ob-row" v-for="(b, i) in quote.bids" :key="'b'+i">
              <span class="ob-label">買{{ i+1 }}</span>
              <span class="ob-price down">{{ b.price.toFixed(2) }}</span>
              <span class="ob-size">{{ b.size }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- AI 持倉分析 -->
      <div class="quote-card ai-section">
        <h3>AI 持倉分析</h3>
        <div class="ai-input-row">
          <el-input
            v-model="costInput"
            placeholder="輸入成本價"
            type="number"
            style="flex: 1"
          >
            <template #prefix>$</template>
          </el-input>
          <el-button type="warning" :loading="analyzing" @click="doAnalyze">AI 分析</el-button>
        </div>
        <div v-if="aiResult" class="ai-result">
          <div class="ai-action" :class="aiActionClass">
            <span class="ai-action-label">{{ aiResult.action }}</span>
            <span class="ai-pnl" :class="aiResult.pnl_pct >= 0 ? 'up' : 'down'">
              帳面 {{ aiResult.pnl_pct >= 0 ? '+' : '' }}{{ aiResult.pnl_pct }}%
            </span>
          </div>
          <div class="ai-levels" v-if="aiResult.stop_profit || aiResult.stop_loss">
            <span v-if="aiResult.stop_profit" class="ai-level up">停利 {{ aiResult.stop_profit }}</span>
            <span v-if="aiResult.stop_loss" class="ai-level down">停損 {{ aiResult.stop_loss }}</span>
          </div>
          <div class="ai-text">{{ cleanAnalysis }}</div>
        </div>
      </div>

      <!-- 5分K 圖表 -->
      <div class="quote-card" v-if="quote.candles?.length">
        <h3>5 分 K 走勢</h3>
        <v-chart :option="chartOption" autoresize style="height: 360px" />
      </div>

      <!-- 5分K 表格 -->
      <div class="quote-card" v-if="quote.candles?.length">
        <h3>5 分 K 明細</h3>
        <div class="candle-table-wrap">
          <table class="candle-table">
            <thead>
              <tr>
                <th>時間</th>
                <th>開</th>
                <th>高</th>
                <th>低</th>
                <th>收</th>
                <th>量</th>
                <th>漲跌%</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in quote.candles" :key="c.time">
                <td>{{ c.time }}</td>
                <td>{{ c.open.toFixed(2) }}</td>
                <td>{{ c.high.toFixed(2) }}</td>
                <td>{{ c.low.toFixed(2) }}</td>
                <td :class="c.close > quote.prev_close ? 'up' : c.close < quote.prev_close ? 'down' : ''">
                  {{ c.close.toFixed(2) }}
                </td>
                <td>{{ formatNumber(c.volume) }}</td>
                <td :class="candlePct(c) >= 0 ? 'up' : 'down'">
                  {{ candlePct(c) >= 0 ? '+' : '' }}{{ candlePct(c) }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <el-empty v-else-if="searched && !loading" description="查無資料" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { getQuote, analyzeQuote } from '../api'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { CandlestickChart, BarChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, DataZoomComponent } from 'echarts/components'
import VChart from 'vue-echarts'

use([CanvasRenderer, CandlestickChart, BarChart, GridComponent, TooltipComponent, DataZoomComponent])

const route = useRoute()

const symbolInput = ref('')
const quote = ref(null)
const loading = ref(false)
const searched = ref(false)
const costInput = ref('')
const analyzing = ref(false)
const aiResult = ref(null)

onMounted(() => {
  const sym = route.query.symbol
  if (sym) {
    symbolInput.value = sym
    fetchQuote()
  }
})

async function fetchQuote() {
  const sym = symbolInput.value.trim()
  if (!sym) return
  loading.value = true
  searched.value = true
  try {
    const { data } = await getQuote(sym)
    quote.value = data
  } catch {
    quote.value = null
  } finally {
    loading.value = false
  }
}

async function doAnalyze() {
  const cost = parseFloat(costInput.value)
  if (!cost || cost <= 0) return
  if (!quote.value?.symbol) return
  analyzing.value = true
  try {
    const { data } = await analyzeQuote(quote.value.symbol, cost)
    aiResult.value = data
  } catch {
    aiResult.value = null
  } finally {
    analyzing.value = false
  }
}

const aiActionClass = computed(() => {
  if (!aiResult.value) return ''
  const a = aiResult.value.action
  if (a === '續抱') return 'action-hold'
  if (a === '止損') return 'action-stop'
  return 'action-wait'
})

const cleanAnalysis = computed(() => {
  if (!aiResult.value?.analysis) return ''
  // 移除第一行（建議|xxx）和最後一行（停利/停損）
  return aiResult.value.analysis
    .replace(/^建議\|\S+\n?/, '')
    .replace(/停利[:：]?\s*[\d.]+\s*停損[:：]?\s*[\d.]+\s*$/, '')
    .trim()
})

const priceClass = computed(() => {
  if (!quote.value) return ''
  return quote.value.change_pct > 0 ? 'up' : quote.value.change_pct < 0 ? 'down' : ''
})

const amplitude = computed(() => {
  if (!quote.value || !quote.value.prev_close) return '0.00'
  return ((quote.value.high - quote.value.low) / quote.value.prev_close * 100).toFixed(2)
})

function candlePct(c) {
  if (!quote.value?.prev_close) return 0
  return ((c.close - quote.value.prev_close) / quote.value.prev_close * 100).toFixed(2)
}

function formatNumber(n) {
  return n?.toLocaleString() ?? '0'
}

const chartOption = computed(() => {
  if (!quote.value?.candles?.length) return {}
  const candles = quote.value.candles
  const times = candles.map(c => c.time)
  const ohlc = candles.map(c => [c.open, c.close, c.low, c.high])
  const volumes = candles.map(c => ({
    value: c.volume,
    itemStyle: { color: c.close >= c.open ? '#ef5350' : '#26a69a' },
  }))

  return {
    tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
    grid: [
      { left: 50, right: 16, top: 16, height: '55%' },
      { left: 50, right: 16, top: '73%', height: '20%' },
    ],
    xAxis: [
      { type: 'category', data: times, gridIndex: 0, axisLabel: { show: false } },
      { type: 'category', data: times, gridIndex: 1, axisLabel: { fontSize: 10 } },
    ],
    yAxis: [
      { scale: true, gridIndex: 0, splitLine: { lineStyle: { type: 'dashed' } } },
      { scale: true, gridIndex: 1, splitLine: { show: false }, axisLabel: { show: false } },
    ],
    dataZoom: [{ type: 'inside', xAxisIndex: [0, 1], start: 0, end: 100 }],
    series: [
      {
        type: 'candlestick',
        data: ohlc,
        xAxisIndex: 0,
        yAxisIndex: 0,
        itemStyle: {
          color: '#ef5350',
          color0: '#26a69a',
          borderColor: '#ef5350',
          borderColor0: '#26a69a',
        },
      },
      {
        type: 'bar',
        data: volumes,
        xAxisIndex: 1,
        yAxisIndex: 1,
      },
    ],
  }
})
</script>

<style scoped>
.page { padding: 12px; max-width: 600px; margin: 0 auto; }
.page-title { margin: 0 0 12px; font-size: 18px; }

.search-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.quote-card {
  background: #fff;
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 10px;
  box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.quote-card h3 { margin: 0 0 10px; font-size: 14px; color: #606266; }

/* header */
.quote-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
.quote-title { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.symbol { font-size: 20px; font-weight: 700; }
.name { font-size: 15px; color: #606266; }
.quote-price { text-align: right; }
.current-price { font-size: 24px; font-weight: 700; display: block; }
.change-pct { font-size: 14px; }

/* OHLC grid */
.ohlc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.ohlc-item { display: flex; flex-direction: column; align-items: center; font-size: 13px; }
.ohlc-item .label { color: #909399; font-size: 11px; margin-bottom: 2px; }

/* orderbook */
.orderbook-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.ob-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 13px; }
.ob-label { color: #909399; width: 28px; }
.ob-price { font-weight: 600; flex: 1; text-align: center; }
.ob-size { width: 50px; text-align: right; color: #606266; }

/* candle table */
.candle-table-wrap { overflow-x: auto; max-height: 400px; overflow-y: auto; }
.candle-table { width: 100%; border-collapse: collapse; font-size: 12px; white-space: nowrap; }
.candle-table th { position: sticky; top: 0; background: #f5f7fa; padding: 6px 4px; text-align: right; color: #909399; font-weight: 500; }
.candle-table td { padding: 5px 4px; text-align: right; border-bottom: 1px solid #f0f0f0; }
.candle-table td:first-child, .candle-table th:first-child { text-align: left; }

/* AI section */
.ai-input-row { display: flex; gap: 8px; margin-bottom: 10px; }
.ai-result { background: #fafafa; border-radius: 8px; padding: 12px; }
.ai-action { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.ai-action-label { font-size: 18px; font-weight: 700; padding: 2px 12px; border-radius: 4px; color: #fff; }
.action-hold .ai-action-label { background: #ef5350; }
.action-stop .ai-action-label { background: #26a69a; }
.action-wait .ai-action-label { background: #e6a23c; }
.ai-pnl { font-size: 15px; font-weight: 600; }
.ai-levels { display: flex; gap: 16px; margin-bottom: 8px; }
.ai-level { font-size: 13px; font-weight: 600; padding: 2px 8px; border-radius: 4px; }
.ai-level.up { background: #fef0f0; color: #ef5350; }
.ai-level.down { background: #f0f9eb; color: #26a69a; }
.ai-text { font-size: 13px; line-height: 1.6; color: #303133; white-space: pre-wrap; }

/* colors */
.up { color: #ef5350; }
.down { color: #26a69a; }
.quote-price.up .current-price, .quote-price.up .change-pct { color: #ef5350; }
.quote-price.down .current-price, .quote-price.down .change-pct { color: #26a69a; }
</style>
