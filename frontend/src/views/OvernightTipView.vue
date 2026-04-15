<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">隔日沖明牌分析</h1>
    </div>

    <div class="overnight-tabs">
      <router-link to="/overnight" class="o-tab" :class="{ active: route.path === '/overnight' }">候選標的</router-link>
      <router-link to="/overnight/review" class="o-tab" active-class="active">AI 檢討</router-link>
      <router-link to="/overnight/tip" class="o-tab" active-class="active">明牌分析</router-link>
    </div>

    <div class="stock-card">
      <p class="tip-hint">今晚買了哪支隔日沖賺到了？AI 從數值找理由存成高優先教訓</p>

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
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { useOvernightStore } from '../stores/overnight'
import { Loading } from '@element-plus/icons-vue'
import dayjs from 'dayjs'

const store = useOvernightStore()
const route = useRoute()

const tipSymbol = ref('')
const tipDate = ref(dayjs().format('YYYY-MM-DD'))
const tipNotes = ref('')

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

.tip-hint {
  font-size: 12px;
  color: #909399;
  margin: 0 0 10px;
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
