<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">消息面</h1>
      <div class="header-actions">
        <el-date-picker
          v-model="currentDate"
          type="date"
          format="MM/DD"
          value-format="YYYY-MM-DD"
          :clearable="false"
          size="small"
          style="width: 100px"
          @change="loadDashboard"
        />
        <el-button type="primary" size="small" @click="doFetch" :loading="fetching">
          {{ fetching ? fetchProgress : '抓取分析' }}
        </el-button>
        <span v-if="lastFetchedAt" class="last-fetched">{{ lastFetchedAt }}</span>
      </div>
    </div>

    <!-- 抓取結果 -->
    <div v-if="fetchSteps.length > 0" class="fetch-results stock-card">
      <div v-for="s in fetchSteps" :key="s.step" class="fetch-step" :class="s.success ? 'step-ok' : 'step-fail'">
        <span class="step-icon">{{ s.success ? '✓' : '✗' }}</span>
        <span class="step-name">{{ s.step }}</span>
      </div>
    </div>

    <el-skeleton v-if="loading" :rows="8" animated />

    <template v-else-if="overall">
      <!-- 總體指數 -->
      <div class="index-grid">
        <div class="index-card" v-for="item in overallCards" :key="item.key">
          <div class="index-value" :style="{ color: item.color }">{{ item.value }}</div>
          <div class="index-label">{{ item.label }}</div>
          <div class="index-bar">
            <div class="index-bar-fill" :style="{ width: item.value + '%', background: item.color }" />
          </div>
        </div>
      </div>

      <!-- 產業情緒排行 -->
      <div class="section-title">產業情緒排行</div>
      <div class="stock-card" v-if="industries.length > 0">
        <div v-for="ind in industries" :key="ind.scope_value" class="industry-row">
          <span class="industry-name">{{ ind.scope_value }}</span>
          <div class="industry-bar-wrap">
            <div class="industry-bar" :style="{ width: ind.sentiment + '%', background: sentimentColor(ind.sentiment) }" />
          </div>
          <span class="industry-score" :style="{ color: sentimentColor(ind.sentiment) }">{{ ind.sentiment }}</span>
          <span class="industry-count">{{ ind.article_count }}篇</span>
        </div>
      </div>
      <div v-else class="stock-card">
        <el-empty description="無產業資料" :image-size="60" />
      </div>

      <!-- 今日新聞 -->
      <div class="section-title">今日新聞 ({{ articles.length }})</div>
      <div class="news-list">
        <div v-for="art in articles" :key="art.id" class="stock-card news-card" @click="openUrl(art.url)">
          <div class="news-top">
            <el-tag
              size="small"
              :type="art.sentiment_label === 'positive' ? 'success' : art.sentiment_label === 'negative' ? 'danger' : 'info'"
              effect="dark"
              round
            >
              {{ art.sentiment_score > 0 ? '+' : '' }}{{ art.sentiment_score }}
            </el-tag>
            <el-tag v-if="art.industry" size="small" effect="plain" round>{{ art.industry }}</el-tag>
            <span class="news-source">{{ art.source }}</span>
            <span v-if="art.published_at" class="news-time">{{ formatTime(art.published_at) }}</span>
          </div>
          <div class="news-title">{{ art.title }}</div>
          <div v-if="art.ai_analysis?.summary" class="news-summary">{{ art.ai_analysis.summary }}</div>
        </div>
      </div>

      <el-empty v-if="articles.length === 0" description="今日尚無新聞資料" />
    </template>

    <el-empty v-else description="尚無資料，請按「抓取分析」開始" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { getNewsDashboard, fetchNews, getNewsFetchStatus } from '../api'
import { ElMessage } from 'element-plus'

const currentDate = ref(new Date().toISOString().slice(0, 10))
const loading = ref(false)
const fetching = ref(false)
const fetchProgress = ref('處理中...')
const fetchSteps = ref([])

const overall = ref(null)
const industries = ref([])
const articles = ref([])
const lastFetchedAt = ref('')

let pollTimer = null

const overallCards = computed(() => {
  if (!overall.value) return []
  const o = overall.value
  return [
    { key: 'sentiment', label: '情緒指標', value: Math.round(o.sentiment), color: sentimentColor(o.sentiment) },
    { key: 'heatmap', label: '熱度指標', value: Math.round(o.heatmap), color: heatColor(o.heatmap) },
    { key: 'panic', label: '恐慌指標', value: Math.round(o.panic), color: panicColor(o.panic) },
    { key: 'international', label: '國際風向', value: Math.round(o.international), color: sentimentColor(o.international) },
  ]
})

onMounted(() => loadDashboard())
onUnmounted(() => stopPolling())

async function loadDashboard() {
  loading.value = true
  try {
    const { data } = await getNewsDashboard(currentDate.value)
    overall.value = data.overall
    industries.value = data.industries || []
    articles.value = data.articles || []
    lastFetchedAt.value = data.last_fetched_at
      ? formatTime(data.last_fetched_at)
      : ''
  } catch {
    // no data yet
  } finally {
    loading.value = false
  }
}

async function doFetch() {
  fetching.value = true
  fetchSteps.value = []
  fetchProgress.value = '排隊中...'
  try {
    await fetchNews(currentDate.value)
    startPolling()
  } catch {
    ElMessage.error('請求失敗')
    fetching.value = false
  }
}

function startPolling() {
  pollTimer = setInterval(async () => {
    try {
      const { data } = await getNewsFetchStatus(currentDate.value)
      if (data.progress) {
        fetchProgress.value = data.progress
      }
      if (data.status === 'done') {
        stopPolling()
        fetching.value = false
        fetchSteps.value = data.steps || []
        if (data.success) {
          ElMessage.success('分析完成')
        } else {
          ElMessage.warning('部分步驟失敗')
        }
        await loadDashboard()
      }
    } catch {
      // ignore polling errors
    }
  }, 3000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function sentimentColor(v) {
  if (v >= 65) return '#67c23a'
  if (v >= 45) return '#e6a23c'
  return '#f56c6c'
}

function heatColor(v) {
  if (v >= 70) return '#f56c6c'
  if (v >= 40) return '#e6a23c'
  return '#909399'
}

function panicColor(v) {
  if (v >= 60) return '#f56c6c'
  if (v >= 30) return '#e6a23c'
  return '#67c23a'
}

function formatTime(dt) {
  const d = new Date(dt)
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return `${d.getMonth() + 1}/${d.getDate()} ${hh}:${mm}`
}

function openUrl(url) {
  if (url) window.open(url, '_blank')
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

.last-fetched {
  font-size: 11px;
  color: #909399;
  white-space: nowrap;
}

.section-title {
  font-size: 15px;
  font-weight: 600;
  margin: 16px 0 8px;
}

/* 抓取結果 */
.fetch-results {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
}

.fetch-step {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
}

.step-icon { font-weight: 700; }
.step-ok .step-icon { color: #67c23a; }
.step-fail .step-icon { color: #f56c6c; }

/* 總體指數 */
.index-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}

.index-card {
  background: #fff;
  border-radius: 10px;
  padding: 14px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.index-value {
  font-size: 32px;
  font-weight: 700;
  line-height: 1.2;
}

.index-label {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}

.index-bar {
  height: 4px;
  background: #f0f2f5;
  border-radius: 2px;
  margin-top: 8px;
  overflow: hidden;
}

.index-bar-fill {
  height: 100%;
  border-radius: 2px;
  transition: width 0.5s ease;
}

/* 產業排行 */
.industry-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 0;
  border-bottom: 1px solid #f5f7fa;
}

.industry-row:last-child {
  border-bottom: none;
}

.industry-name {
  width: 75px;
  font-size: 13px;
  font-weight: 500;
  flex-shrink: 0;
}

.industry-bar-wrap {
  flex: 1;
  height: 8px;
  background: #f0f2f5;
  border-radius: 4px;
  overflow: hidden;
}

.industry-bar {
  height: 100%;
  border-radius: 4px;
  transition: width 0.5s ease;
}

.industry-score {
  width: 30px;
  text-align: right;
  font-size: 13px;
  font-weight: 600;
  flex-shrink: 0;
}

.industry-count {
  width: 35px;
  text-align: right;
  font-size: 11px;
  color: #909399;
  flex-shrink: 0;
}

/* 新聞列表 */
.news-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.news-card {
  cursor: pointer;
}

.news-top {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}

.news-source {
  font-size: 11px;
  color: #c0c4cc;
  margin-left: auto;
}

.news-time {
  font-size: 11px;
  color: #c0c4cc;
  flex-shrink: 0;
}

.news-title {
  font-size: 14px;
  font-weight: 500;
  line-height: 1.5;
}

.news-summary {
  font-size: 12px;
  color: #909399;
  margin-top: 4px;
  line-height: 1.4;
}
</style>
