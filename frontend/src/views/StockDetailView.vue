<template>
  <div class="page">
    <div class="back-row" @click="goBack">
      <el-icon><ArrowLeft /></el-icon>
      <span>返回</span>
    </div>

    <el-skeleton v-if="loading" :rows="8" animated />

    <template v-else-if="stock">
      <div class="stock-card">
        <div class="detail-header">
          <div>
            <span class="stock-symbol">{{ stock.symbol }}</span>
            <span class="stock-name">{{ stock.name }}</span>
          </div>
          <el-tag v-if="stock.industry" size="small">{{ stock.industry }}</el-tag>
        </div>

        <!-- 最近行情 -->
        <div class="recent-quotes" v-if="stock.daily_quotes?.length">
          <div class="quote-row" v-for="q in stock.daily_quotes" :key="q.id">
            <span class="q-date">{{ q.date?.slice(0, 10) }}</span>
            <span>開 {{ q.open }}</span>
            <span>高 <b class="price-up">{{ q.high }}</b></span>
            <span>低 <b class="price-down">{{ q.low }}</b></span>
            <span>收 {{ q.close }}</span>
            <span :class="q.change >= 0 ? 'price-up' : 'price-down'">
              {{ q.change >= 0 ? '+' : '' }}{{ q.change_percent }}%
            </span>
          </div>
        </div>
      </div>

      <!-- K 線圖 -->
      <div class="stock-card">
        <h3 style="margin-bottom: 8px">K 線圖</h3>
        <v-chart :option="klineOption" autoresize style="height: 300px" />
      </div>

      <!-- 法人買賣 -->
      <div class="stock-card" v-if="stock.institutional_trades?.length">
        <h3 style="margin-bottom: 8px">三大法人（近 {{ stock.institutional_trades.length }} 日）</h3>
        <div class="inst-row" v-for="t in stock.institutional_trades" :key="t.id">
          <span class="q-date">{{ t.date?.slice(0, 10) }}</span>
          <span :class="t.foreign_net >= 0 ? 'price-up' : 'price-down'">
            外資 {{ t.foreign_net > 0 ? '+' : '' }}{{ t.foreign_net }}
          </span>
          <span :class="t.trust_net >= 0 ? 'price-up' : 'price-down'">
            投信 {{ t.trust_net > 0 ? '+' : '' }}{{ t.trust_net }}
          </span>
          <span :class="t.total_net >= 0 ? 'price-up' : 'price-down'">
            合計 {{ t.total_net > 0 ? '+' : '' }}{{ t.total_net }}
          </span>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getStockDetail, getStockKline } from '../api'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { CandlestickChart, BarChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, DataZoomComponent } from 'echarts/components'
import VChart from 'vue-echarts'

use([CanvasRenderer, CandlestickChart, BarChart, GridComponent, TooltipComponent, DataZoomComponent])

const route = useRoute()
const router = useRouter()

function goBack() {
  if (window.history.length <= 1) {
    window.close()
  } else {
    router.back()
  }
}

const stock = ref(null)
const klineData = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const [detailRes, klineRes] = await Promise.all([
      getStockDetail(route.params.id),
      getStockKline(route.params.id, 60),
    ])
    stock.value = detailRes.data
    klineData.value = klineRes.data
  } finally {
    loading.value = false
  }
})

const klineOption = computed(() => {
  const data = klineData.value
  if (!data.length) return {}

  const dates = data.map(d => d.date?.slice(5, 10))
  const values = data.map(d => [d.open, d.close, d.low, d.high])
  const volumes = data.map(d => d.volume)

  return {
    grid: [
      { left: 40, right: 16, top: 10, height: '55%' },
      { left: 40, right: 16, top: '72%', height: '20%' },
    ],
    xAxis: [
      { type: 'category', data: dates, gridIndex: 0, axisLabel: { fontSize: 10 } },
      { type: 'category', data: dates, gridIndex: 1, axisLabel: { show: false } },
    ],
    yAxis: [
      { scale: true, gridIndex: 0, axisLabel: { fontSize: 10 } },
      { scale: true, gridIndex: 1, axisLabel: { fontSize: 10 } },
    ],
    series: [
      {
        type: 'candlestick',
        data: values,
        xAxisIndex: 0,
        yAxisIndex: 0,
        itemStyle: {
          color: '#f56c6c',
          color0: '#67c23a',
          borderColor: '#f56c6c',
          borderColor0: '#67c23a',
        },
      },
      {
        type: 'bar',
        data: volumes,
        xAxisIndex: 1,
        yAxisIndex: 1,
        itemStyle: { color: '#c0c4cc' },
      },
    ],
    tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
    dataZoom: [{ type: 'inside', xAxisIndex: [0, 1], start: 60, end: 100 }],
  }
})
</script>

<style scoped>
.back-row {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 8px 0;
  color: #409eff;
  cursor: pointer;
  font-size: 14px;
}

.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.stock-symbol {
  font-size: 20px;
  font-weight: 700;
  margin-right: 6px;
}

.stock-name {
  font-size: 14px;
  color: #606266;
}

.recent-quotes, .inst-row {
  font-size: 12px;
}

.quote-row, .inst-row {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  border-bottom: 1px solid #f2f6fc;
  flex-wrap: wrap;
  gap: 2px;
}

.q-date {
  color: #909399;
  min-width: 70px;
}
</style>
