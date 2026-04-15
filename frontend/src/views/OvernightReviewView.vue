<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">隔日沖 AI 檢討</h1>
    </div>

    <div class="overnight-tabs">
      <router-link to="/overnight" class="o-tab" :class="{ active: route.path === '/overnight' }">候選標的</router-link>
      <router-link to="/overnight/review" class="o-tab" active-class="active">AI 檢討</router-link>
      <router-link to="/overnight/tip" class="o-tab" active-class="active">明牌分析</router-link>
    </div>

    <div class="stock-card">
      <div class="review-header">
        <div class="review-controls">
          <el-date-picker
            v-model="reviewDate"
            type="date"
            format="MM/DD"
            value-format="YYYY-MM-DD"
            :clearable="false"
            size="small"
            style="width: 120px"
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

      <div v-else class="empty-hint">選擇日期後點「產出報告」，或直接載入既有報告</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useOvernightStore } from '../stores/overnight'
import { Loading } from '@element-plus/icons-vue'
import dayjs from 'dayjs'

const store = useOvernightStore()
const route = useRoute()

const reviewDate = ref(dayjs().format('YYYY-MM-DD'))
const reviewLoading = ref(false)

onMounted(async () => {
  await store.fetchReviewDates()
  await loadReview()
})

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

.overnight-tabs {
  display: flex;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 12px;
  border: 1px solid #e4e7ed;
}

.o-tab {
  flex: 1;
  text-align: center;
  padding: 8px 0;
  font-size: 13px;
  color: #909399;
  text-decoration: none;
  border-right: 1px solid #e4e7ed;
  transition: all 0.15s;
}

.o-tab:last-child {
  border-right: none;
}

.o-tab.active {
  color: #409eff;
  background: #ecf5ff;
  font-weight: 600;
}

.stock-card {
  background: #fff;
  border-radius: 10px;
  padding: 12px 14px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
}

.review-header {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 10px;
}

.review-controls {
  display: flex;
  gap: 6px;
  align-items: center;
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

.empty-hint {
  color: #c0c4cc;
  font-size: 13px;
  padding: 20px 0;
  text-align: center;
}
</style>
