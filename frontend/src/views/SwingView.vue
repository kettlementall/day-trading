<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">短線配置</h1>
      <div class="header-actions">
        <el-date-picker
          v-model="currentDate"
          type="date"
          format="MM/DD"
          value-format="YYYY-MM-DD"
          :clearable="false"
          size="small"
          style="width: 130px"
          @change="fetchAll"
        />
        <el-button size="small" :loading="loading" @click="fetchAll">刷新</el-button>
      </div>
    </div>

    <section class="section">
      <div class="section-title">我的短線持倉</div>
      <div v-if="exposure" class="exposure-bar">
        <span>持倉 {{ exposure.active_positions }}</span>
        <span>市值 {{ money(exposure.market_value) }}</span>
        <span>風險 {{ money(exposure.risk_amount) }}</span>
      </div>
      <div v-if="exposure?.by_thesis?.length" class="thesis-exposure">
        <span v-for="item in exposure.by_thesis" :key="item.thesis" class="exposure-chip">
          {{ item.thesis }} {{ item.positions }} 檔 / {{ money(item.market_value) }}
        </span>
      </div>

      <el-empty v-if="!positions.length && !loading" description="尚無短線持倉" :image-size="90" />
      <div v-else class="position-list">
        <div v-for="p in positions" :key="p.id" class="position-row">
          <div class="row-main">
            <div>
              <strong>{{ p.stock.symbol }}</strong>
              <span class="muted">{{ p.stock.name }}</span>
              <el-tag size="small" :type="positionTag(p.status)">{{ statusLabel(p.status) }}</el-tag>
            </div>
            <div class="pnl" :class="p.unrealized_profit_percent >= 0 ? 'price-up' : 'price-down'">
              {{ signed(p.unrealized_profit_percent) }}%
            </div>
          </div>
          <div class="row-grid">
            <span>成本 {{ p.entry_price }}</span>
            <span>現價 {{ p.current_price || '-' }}</span>
            <span>股數 {{ p.shares }}</span>
            <span>停損 {{ p.current_stop || '-' }}</span>
            <span>目標 {{ p.current_target || '-' }}</span>
            <span>市值 {{ money(p.market_value) }}</span>
          </div>
          <div v-if="p.latest_advice" class="advice">
            {{ p.latest_advice.action }}：{{ p.latest_advice.reasoning }}
          </div>
          <div class="row-actions">
            <el-button size="small" @click="markClosed(p, 'closed')">平倉</el-button>
            <el-button size="small" type="danger" plain @click="markClosed(p, 'stopped')">停損結束</el-button>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section-title">短線候選</div>
      <el-empty v-if="!candidates.length && !loading" description="今日尚無短線候選" :image-size="90" />
      <div class="candidate-list">
        <div v-for="c in candidates" :key="c.id" class="candidate-row" :class="{ rejected: !c.ai_selected }">
          <div class="row-main">
            <div>
              <strong>{{ c.stock.symbol }}</strong>
              <span class="muted">{{ c.stock.name }}</span>
              <span class="muted">{{ c.stock.industry || '-' }}</span>
            </div>
            <el-tag size="small" :type="c.ai_selected ? 'success' : 'info'">分數 {{ c.score }}</el-tag>
          </div>
          <div class="thesis-line">{{ c.swing_thesis?.title || '未連結論點' }}</div>
          <div class="reasoning">{{ c.swing_reasoning || c.ai_reasoning }}</div>
          <div class="row-grid">
            <span>買 {{ c.suggested_buy }}</span>
            <span>目標 {{ c.target_price }}</span>
            <span>停損 {{ c.stop_loss }}</span>
            <span>持有 {{ c.swing_time_horizon_days || 20 }} 日</span>
          </div>
          <div class="row-actions">
            <el-button size="small" @click="openSizing(c)">倉位試算</el-button>
            <el-button size="small" type="primary" :disabled="!c.ai_selected" @click="openBuy(c)">確認買入</el-button>
          </div>
        </div>
      </div>
    </section>

    <el-dialog v-model="buyDialog" title="確認買入" width="360px">
      <el-form label-width="80px">
        <el-form-item label="股票">{{ selected?.stock?.symbol }} {{ selected?.stock?.name }}</el-form-item>
        <el-form-item label="成本">
          <el-input-number v-model="buyForm.entry_price" :min="0" :step="0.05" style="width: 180px" />
        </el-form-item>
        <el-form-item label="股數">
          <el-input-number v-model="buyForm.shares" :min="1" :step="1000" style="width: 180px" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="buyDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitBuy">建立持倉</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="sizingDialog" title="ATR 風險試算" width="380px">
      <el-form label-width="90px">
        <el-form-item label="總資金">
          <el-input-number v-model="sizingForm.capital" :min="1" :step="10000" style="width: 200px" />
        </el-form-item>
        <el-form-item label="風險%">
          <el-input-number v-model="sizingForm.risk_percent" :min="0.1" :max="100" :step="0.1" style="width: 200px" />
        </el-form-item>
      </el-form>
      <div v-if="sizingResult" class="sizing-result">
        <div>風險預算 {{ money(sizingResult.risk_budget) }}</div>
        <div>每股風險 {{ sizingResult.risk_per_share }}</div>
        <div>建議 {{ sizingResult.suggested_shares }} 股（{{ sizingResult.suggested_lots }} 張）</div>
        <div>所需資金 {{ money(sizingResult.capital_required) }}</div>
      </div>
      <template #footer>
        <el-button @click="sizingDialog = false">關閉</el-button>
        <el-button type="primary" @click="submitSizing">計算</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import dayjs from 'dayjs'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  calculateSwingSizing,
  createSwingPosition,
  getSwingCandidates,
  getSwingPositions,
  updateSwingPosition,
} from '../api'

const currentDate = ref(dayjs().format('YYYY-MM-DD'))
const loading = ref(false)
const saving = ref(false)
const candidates = ref([])
const positions = ref([])
const exposure = ref(null)
const buyDialog = ref(false)
const sizingDialog = ref(false)
const selected = ref(null)
const sizingResult = ref(null)
const buyForm = reactive({ entry_price: 0, shares: 1000 })
const sizingForm = reactive({ capital: 1000000, risk_percent: 1 })

onMounted(fetchAll)

async function fetchAll() {
  loading.value = true
  try {
    const [cRes, pRes] = await Promise.all([
      getSwingCandidates(currentDate.value),
      getSwingPositions(),
    ])
    candidates.value = cRes.data.data || []
    positions.value = pRes.data.data || []
    exposure.value = pRes.data.total_risk_exposure || null
  } finally {
    loading.value = false
  }
}

function openBuy(candidate) {
  selected.value = candidate
  buyForm.entry_price = Number(candidate.suggested_buy || 0)
  buyForm.shares = 1000
  buyDialog.value = true
}

async function submitBuy() {
  saving.value = true
  try {
    await createSwingPosition({
      candidate_id: selected.value.id,
      entry_price: buyForm.entry_price,
      shares: buyForm.shares,
    })
    ElMessage.success('已建立短線持倉')
    buyDialog.value = false
    await fetchAll()
  } finally {
    saving.value = false
  }
}

function openSizing(candidate) {
  selected.value = candidate
  sizingResult.value = null
  sizingDialog.value = true
}

async function submitSizing() {
  const { data } = await calculateSwingSizing({
    ...sizingForm,
    entry_price: selected.value.suggested_buy,
    stop_loss: selected.value.stop_loss,
  })
  sizingResult.value = data
}

async function markClosed(position, status) {
  await ElMessageBox.confirm('確定要結束這筆短線持倉？', '結束持倉', { type: 'warning' })
  await updateSwingPosition(position.id, {
    status,
    exit_price: position.current_price || position.entry_price,
  })
  await fetchAll()
}

function money(v) {
  if (v === null || v === undefined) return '-'
  return Number(v).toLocaleString('zh-TW', { maximumFractionDigits: 0 })
}

function signed(v) {
  if (v === null || v === undefined) return '-'
  return `${v >= 0 ? '+' : ''}${v}`
}

function statusLabel(status) {
  return {
    watching: '觀察',
    holding: '持有',
    exit_suggested: '建議出場',
    closed: '已平倉',
    stopped: '停損結束',
  }[status] || status
}

function positionTag(status) {
  return {
    holding: 'success',
    exit_suggested: 'warning',
    closed: 'info',
    stopped: 'danger',
  }[status] || 'info'
}
</script>

<style scoped>
.page {
  padding: 12px;
}

.page-header,
.row-main,
.row-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.page-title {
  font-size: 20px;
  margin: 0;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.section {
  margin-top: 12px;
}

.section-title {
  font-weight: 700;
  margin-bottom: 8px;
}

.exposure-bar,
.thesis-exposure {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
  font-size: 13px;
}

.exposure-chip {
  padding: 3px 8px;
  border-radius: 6px;
  background: #eef2f7;
}

.position-list,
.candidate-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.position-row,
.candidate-row {
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  padding: 10px;
}

.candidate-row.rejected {
  opacity: 0.68;
}

.muted {
  color: #909399;
  margin-left: 6px;
  font-size: 13px;
}

.row-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 4px 8px;
  margin-top: 8px;
  font-size: 13px;
  color: #606266;
}

.thesis-line {
  margin-top: 8px;
  font-weight: 600;
  color: #303133;
}

.reasoning,
.advice,
.sizing-result {
  margin-top: 8px;
  color: #606266;
  font-size: 13px;
  line-height: 1.45;
}

.pnl {
  font-weight: 700;
}

.price-up {
  color: #f56c6c;
}

.price-down {
  color: #67c23a;
}
</style>
