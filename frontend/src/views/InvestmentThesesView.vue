<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">AI 產業論點</h1>
      <el-button size="small" :loading="loading" @click="fetchTheses">刷新</el-button>
    </div>

    <div class="thesis-list">
      <div v-for="t in theses" :key="t.id" class="thesis-row">
        <div class="row-main">
          <div>
            <strong>{{ t.title }}</strong>
            <el-tag size="small" :type="tagType(t.status)">{{ t.status }}</el-tag>
            <el-tag size="small" type="info">信心 {{ t.confidence_score }}</el-tag>
          </div>
          <div class="actions">
            <el-button size="small" @click="edit(t)">編輯</el-button>
            <el-button v-if="t.status !== 'disabled'" size="small" type="danger" plain @click="disable(t)">停用</el-button>
            <el-button v-else size="small" type="success" plain @click="enable(t)">啟用</el-button>
          </div>
        </div>
        <p>{{ t.description }}</p>
        <div class="meta">
          <span>受益產業：{{ (t.beneficiary_industries || []).join('、') || '-' }}</span>
          <span>背離：{{ t.sentiment_divergence || 'none' }}</span>
          <span>更新：{{ t.last_evaluated_at ? t.last_evaluated_at.substring(0, 16) : '-' }}</span>
        </div>
        <div v-if="(t.related_stocks || []).length" class="related-stocks">
          <div
            v-for="level in ['core', 'secondary', 'watch']"
            :key="level"
            v-show="relatedByLevel(t, level).length"
            class="related-group"
          >
            <div class="related-title">{{ benefitLabel(level) }}</div>
            <div class="related-list">
              <div v-for="s in relatedByLevel(t, level)" :key="s.symbol" class="related-item">
                <div class="related-head">
                  <strong>{{ s.symbol }} {{ s.name }}</strong>
                  <span>信心 {{ s.confidence ?? '—' }}</span>
                </div>
                <div class="related-role">{{ s.role }}</div>
                <div class="related-reason">{{ s.reasoning }}</div>
              </div>
            </div>
          </div>
        </div>
        <div class="evidence">{{ t.evidence_summary }}</div>
      </div>
    </div>

    <el-dialog v-model="dialog" title="編輯論點" width="520px">
      <el-form label-width="90px">
        <el-form-item label="標題">
          <el-input v-model="form.title" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="4" />
        </el-form-item>
        <el-form-item label="信心">
          <el-input-number v-model="form.confidence_score" :min="0" :max="100" />
        </el-form-item>
        <el-form-item label="狀態">
          <el-select v-model="form.status">
            <el-option label="active" value="active" />
            <el-option label="inactive" value="inactive" />
            <el-option label="disabled" value="disabled" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="save">儲存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { disableInvestmentThesis, enableInvestmentThesis, getInvestmentTheses, updateInvestmentThesis } from '../api'

const loading = ref(false)
const theses = ref([])
const dialog = ref(false)
const editingId = ref(null)
const form = reactive({ title: '', description: '', confidence_score: 50, status: 'active' })

onMounted(fetchTheses)

async function fetchTheses() {
  loading.value = true
  try {
    const { data } = await getInvestmentTheses()
    theses.value = data
  } finally {
    loading.value = false
  }
}

function edit(thesis) {
  editingId.value = thesis.id
  form.title = thesis.title
  form.description = thesis.description
  form.confidence_score = thesis.confidence_score
  form.status = thesis.status
  dialog.value = true
}

async function save() {
  await updateInvestmentThesis(editingId.value, { ...form })
  ElMessage.success('已更新')
  dialog.value = false
  await fetchTheses()
}

async function disable(thesis) {
  await disableInvestmentThesis(thesis.id)
  await fetchTheses()
}

async function enable(thesis) {
  await enableInvestmentThesis(thesis.id)
  await fetchTheses()
}

function tagType(status) {
  return { active: 'success', inactive: 'warning', disabled: 'danger' }[status] || 'info'
}

function benefitLabel(level) {
  return { core: '核心受益', secondary: '次級受益', watch: '觀察' }[level] || '觀察'
}

function relatedByLevel(thesis, level) {
  return (thesis.related_stocks || []).filter(s => s.benefit_level === level)
}
</script>

<style scoped>
.page {
  padding: 12px;
}

.page-header,
.row-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.page-title {
  font-size: 20px;
  margin: 0;
}

.thesis-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 12px;
}

.thesis-row {
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  padding: 10px;
}

.thesis-row p {
  margin: 8px 0;
  color: #606266;
  line-height: 1.45;
}

.actions {
  display: flex;
  gap: 6px;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  color: #909399;
  font-size: 12px;
}

.evidence {
  margin-top: 8px;
  color: #606266;
  font-size: 13px;
}

.related-stocks {
  display: grid;
  gap: 8px;
  margin-top: 10px;
}

.related-title {
  font-size: 12px;
  font-weight: 700;
  color: #303133;
  margin-bottom: 4px;
}

.related-list {
  display: grid;
  gap: 6px;
}

.related-item {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 7px 8px;
  background: #fafafa;
}

.related-head {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
}

.related-head span,
.related-role,
.related-reason {
  color: #606266;
  font-size: 12px;
}

.related-role {
  margin-top: 3px;
  font-weight: 600;
}

.related-reason {
  margin-top: 3px;
  line-height: 1.45;
}
</style>
