<template>
  <div class="page">
    <h2 class="page-title">即時報價</h2>

    <!-- 搜尋列 -->
    <div class="search-bar">
      <el-autocomplete
        v-model="symbolInput"
        :fetch-suggestions="handleSuggest"
        placeholder="輸入股票代號或名稱，例如 2330 或 台積電"
        clearable
        @select="handleSelect"
        @keyup.enter="fetchQuote"
        style="flex: 1"
        :debounce="300"
        value-key="label"
      >
        <template #prefix>
          <el-icon><Search /></el-icon>
        </template>
        <template #default="{ item }">
          <span class="suggest-symbol">{{ item.symbol }}</span>
          <span class="suggest-name">{{ item.name }}</span>
        </template>
      </el-autocomplete>
      <el-button type="primary" :loading="loading" @click="fetchQuote">查詢</el-button>
    </div>

    <!-- 查詢紀錄 -->
    <div class="history-tags" v-if="searchHistory.length">
      <el-tag
        v-for="item in searchHistory"
        :key="item.symbol"
        size="small"
        class="history-tag"
        closable
        @click="quickSearch(item.symbol)"
        @close="removeHistory(item.symbol)"
      >
        {{ item.symbol }} {{ item.name }}
      </el-tag>
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

      <!-- AI 線上問診 -->
      <div class="quote-card ai-section">
        <h3>AI 線上問診</h3>
        <div class="ai-input-row">
          <el-input
            v-model="costInput"
            placeholder="成本價"
            type="number"
            style="flex: 1"
          >
            <template #prefix>$</template>
          </el-input>
          <el-input
            v-model="sharesInput"
            placeholder="張數（選填）"
            type="number"
            style="flex: 0.7"
          />
          <el-select v-model="directionInput" style="width: 90px">
            <el-option label="做多" value="long" />
            <el-option label="做空" value="short" />
          </el-select>
          <el-button type="warning" :loading="analyzing" @click="doAnalyze">AI 分析</el-button>
        </div>
        <div v-if="aiResult" class="ai-result">
          <div class="ai-pnl-row">
            <span class="ai-pnl" :class="aiResult.pnl_pct >= 0 ? 'up' : 'down'">
              帳面 {{ aiResult.pnl_pct >= 0 ? '+' : '' }}{{ aiResult.pnl_pct }}%
            </span>
          </div>
          <div class="ai-dual">
            <div class="ai-dual-block">
              <div class="ai-action" :class="actionClass(aiResult.short?.action)">
                <span class="ai-action-label">短線 {{ aiResult.short?.action }}</span>
              </div>
              <div class="ai-levels" v-if="aiResult.short?.stop_profit || aiResult.short?.stop_loss || aiResult.short?.add_price">
                <span v-if="aiResult.short?.stop_profit" class="ai-level up">停利 {{ aiResult.short.stop_profit }}</span>
                <span v-if="aiResult.short?.stop_loss" class="ai-level down">停損 {{ aiResult.short.stop_loss }}</span>
                <span v-if="aiResult.short?.add_price" class="ai-level add">加碼 {{ aiResult.short.add_price }}</span>
              </div>
              <div class="ai-note">* {{ ['續抱','加碼','止損','觀望'].includes(aiResult.short?.action) ? '今日收盤前結束' : '下一交易日操作建議' }}</div>
              <div class="ai-text">{{ aiResult.short?.analysis }}</div>
            </div>
            <div class="ai-dual-block">
              <div class="ai-action" :class="actionClass(aiResult.long?.action)">
                <span class="ai-action-label">波段 {{ aiResult.long?.action }}</span>
              </div>
              <div class="ai-levels" v-if="aiResult.long?.stop_profit || aiResult.long?.stop_loss || aiResult.long?.add_price">
                <span v-if="aiResult.long?.stop_profit" class="ai-level up">停利 {{ aiResult.long.stop_profit }}</span>
                <span v-if="aiResult.long?.stop_loss" class="ai-level down">停損 {{ aiResult.long.stop_loss }}</span>
                <span v-if="aiResult.long?.add_price" class="ai-level add">加碼 {{ aiResult.long.add_price }}</span>
              </div>
              <div class="ai-note">* 可持有數天到數週</div>
              <div class="ai-text">{{ aiResult.long?.analysis }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- K 線圖表 -->
      <div class="quote-card" v-if="quote.candles?.length || quote.daily_candles?.length">
        <div class="chart-header">
          <h3>{{ chartMode === 'intraday' ? '5 分 K 走勢' : '日 K 走勢' }}</h3>
          <el-radio-group v-model="chartMode" size="small">
            <el-radio-button value="intraday">5分K</el-radio-button>
            <el-radio-button value="daily" :disabled="!quote.daily_candles?.length">日K</el-radio-button>
          </el-radio-group>
        </div>
        <v-chart :option="chartOption" autoresize style="height: 360px" />
      </div>

      <!-- K 線表格 -->
      <div class="quote-card" v-if="activeCandles?.length">
        <h3>{{ chartMode === 'intraday' ? '5 分 K 明細' : '日 K 明細' }}</h3>
        <div class="candle-table-wrap">
          <table class="candle-table">
            <thead>
              <tr>
                <th>{{ chartMode === 'intraday' ? '時間' : '日期' }}</th>
                <th>開</th>
                <th>高</th>
                <th>低</th>
                <th>收</th>
                <th>量</th>
                <th>漲跌%</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in activeCandles" :key="c.time || c.date">
                <td>{{ c.time || c.date }}</td>
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

    <el-alert v-else-if="searched && !loading && rateLimited" type="warning" :closable="false" show-icon style="margin-top: 12px">
      API 請求過於頻繁（429），請稍後再試
    </el-alert>
    <el-empty v-else-if="searched && !loading" description="查無資料" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getQuote, analyzeQuote, searchQuote } from '../api'
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
const sharesInput = ref('')
const directionInput = ref('long')
const analyzing = ref(false)
const aiResult = ref(null)
const chartMode = ref('intraday')

const activeCandles = computed(() => {
  if (!quote.value) return []
  return chartMode.value === 'daily' ? (quote.value.daily_candles || []) : (quote.value.candles || [])
})
const rateLimited = ref(false)

const HISTORY_KEY = 'quote_search_history'
const MAX_HISTORY = 10
const searchHistory = ref(JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'))

function saveHistory(symbol, name) {
  const list = searchHistory.value.filter(h => h.symbol !== symbol)
  list.unshift({ symbol, name: name || '' })
  if (list.length > MAX_HISTORY) list.length = MAX_HISTORY
  searchHistory.value = list
  localStorage.setItem(HISTORY_KEY, JSON.stringify(list))
}

function removeHistory(symbol) {
  searchHistory.value = searchHistory.value.filter(h => h.symbol !== symbol)
  localStorage.setItem(HISTORY_KEY, JSON.stringify(searchHistory.value))
}

function quickSearch(symbol) {
  symbolInput.value = symbol
  fetchQuote()
}

async function handleSuggest(query, cb) {
  if (!query || query.trim().length < 1) return cb([])
  // 純數字直接不查（直接 enter 查詢即可）
  if (/^\d+$/.test(query.trim())) return cb([])
  try {
    const { data } = await searchQuote(query.trim())
    cb(data.map(s => ({ ...s, label: `${s.symbol} ${s.name}`, value: s.symbol })))
  } catch {
    cb([])
  }
}

function handleSelect(item) {
  symbolInput.value = item.symbol
  fetchQuote()
}

onMounted(() => {
  const sym = route.query.symbol
  if (sym) {
    symbolInput.value = sym
    fetchQuote()
  }
})

async function fetchQuote() {
  let sym = symbolInput.value.trim()
  if (!sym) return
  loading.value = true
  searched.value = true
  rateLimited.value = false
  try {
    // 非純數字 → 先搜尋名稱，取第一筆結果的代號
    if (!/^\d{4,6}$/.test(sym)) {
      const { data: results } = await searchQuote(sym)
      if (results.length === 0) {
        quote.value = null
        loading.value = false
        return
      }
      sym = results[0].symbol
      symbolInput.value = `${results[0].symbol} ${results[0].name}`
    }
    const { data } = await getQuote(sym)
    quote.value = data
    if (data?.symbol) saveHistory(data.symbol, data.name)
  } catch (e) {
    quote.value = null
    if (e.response?.status === 429) rateLimited.value = true
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
    const shares = parseInt(sharesInput.value) || 0
    const { data } = await analyzeQuote(quote.value.symbol, cost, shares, directionInput.value)
    aiResult.value = data
  } catch (e) {
    aiResult.value = null
    const msg = e.code === 'ECONNABORTED' ? 'AI 分析逾時，請稍後再試' : 'AI 分析失敗，請稍後再試'
    ElMessage.error(msg)
  } finally {
    analyzing.value = false
  }
}

function actionClass(action) {
  if (!action) return ''
  if (['續抱', '加碼', '觀察續抱', '掛價加碼'].includes(action)) return 'action-hold'
  if (['止損', '掛價停損'].includes(action)) return 'action-stop'
  if (action.includes('出場')) return 'action-stop'
  if (action === '減碼') return 'action-reduce'
  return 'action-wait'
}

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
  const candles = activeCandles.value
  if (!candles?.length) return {}
  const times = candles.map(c => c.time || c.date)
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
.history-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.history-tag { cursor: pointer; }
.chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.chart-header h3 { margin: 0; }

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
.ai-pnl-row { margin-bottom: 10px; }
.ai-dual { display: flex; gap: 12px; }
.ai-dual-block { flex: 1; background: #fff; border-radius: 6px; padding: 10px; border: 1px solid #eee; }
.ai-note { font-size: 11px; color: #909399; margin-top: 6px; }
.ai-action { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.ai-action-label { font-size: 18px; font-weight: 700; padding: 2px 12px; border-radius: 4px; color: #fff; }
.action-hold .ai-action-label { background: #ef5350; }
.action-stop .ai-action-label { background: #26a69a; }
.action-reduce .ai-action-label { background: #ff9800; }
.action-wait .ai-action-label { background: #e6a23c; }
.ai-pnl { font-size: 15px; font-weight: 600; }
.ai-levels { display: flex; gap: 16px; margin-bottom: 8px; }
.ai-level { font-size: 13px; font-weight: 600; padding: 2px 8px; border-radius: 4px; }
.ai-level.up { background: #fef0f0; color: #ef5350; }
.ai-level.down { background: #f0f9eb; color: #26a69a; }
.ai-level.add { background: #fdf6ec; color: #e6a23c; }
.ai-text { font-size: 13px; line-height: 1.6; color: #303133; white-space: pre-wrap; }

/* autocomplete suggestions */
.suggest-symbol { font-weight: 600; margin-right: 8px; }
.suggest-name { color: #909399; font-size: 13px; }

/* colors */
.up { color: #ef5350; }
.down { color: #26a69a; }
.quote-price.up .current-price, .quote-price.up .change-pct { color: #ef5350; }
.quote-price.down .current-price, .quote-price.down .change-pct { color: #26a69a; }
</style>
