<template>
  <div class="page swing-page">
    <header class="page-header">
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
        <el-button size="small" type="primary" plain :loading="loading" @click="fetchAll">
          刷新
        </el-button>
      </div>
    </header>

    <div v-if="swingMeta" class="market-meta">
      <span>訊號交易日 {{ swingMeta.trade_date || swingMeta.date }}</span>
      <span v-if="swingMeta.is_holiday">查詢日 {{ swingMeta.requested_date }} 休市，顯示最近交易日</span>
      <span v-if="swingMeta.thesis_updated_at">論點更新 {{ formatDateTime(swingMeta.thesis_updated_at) }}</span>
    </div>

    <section class="section">
      <h2 class="section-title">我的短線持倉</h2>

      <div v-if="exposure" class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-label">持倉檔數</div>
          <div class="kpi-value">{{ exposure.active_positions ?? 0 }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">總市值</div>
          <div class="kpi-value">{{ money(exposure.market_value) }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">風險預算</div>
          <div class="kpi-value">{{ money(exposure.risk_amount) }}</div>
        </div>
      </div>

      <div v-if="exposure?.by_thesis?.length" class="thesis-pills">
        <span v-for="item in exposure.by_thesis" :key="item.thesis" class="thesis-pill">
          <span class="thesis-pill-name">{{ item.thesis }}</span>
          <span class="thesis-pill-meta">{{ item.positions }} 檔 · {{ money(item.market_value) }}</span>
        </span>
      </div>

      <el-skeleton v-if="loading && !positions.length" :rows="3" animated class="skeleton-block" />
      <el-empty
        v-else-if="!positions.length"
        description="尚無短線持倉"
        :image-size="90"
        class="empty-pad"
      />
      <div v-else class="position-list">
        <article v-for="p in positions" :key="p.id" class="data-card position-row">
          <div class="row-main">
            <div class="row-id">
              <span class="symbol">{{ p.stock.symbol }}</span>
              <span class="stock-name">{{ p.stock.name }}</span>
              <el-tag size="small" :type="positionTag(p.status)" effect="light" round>
                {{ statusLabel(p.status) }}
              </el-tag>
              <button class="quote-btn" title="即時報價" @click.stop="goQuote(p)">💹</button>
            </div>
            <div class="pnl" :class="p.unrealized_profit_percent >= 0 ? 'price-up' : 'price-down'">
              {{ signed(p.unrealized_profit_percent) }}%
            </div>
          </div>

          <div class="row-divider"></div>

          <div v-if="p.candidate?.swing_thesis?.source === 'related_stock'" class="thesis-role position-thesis-role">
            <span>{{ benefitLabel(p.candidate.swing_thesis.benefit_level) }}</span>
            <strong>{{ p.candidate.swing_thesis.role }}</strong>
            <em>{{ p.candidate.swing_thesis.related_reasoning }}</em>
          </div>

          <div class="row-stats">
            <div class="stat">
              <div class="stat-label">成本</div>
              <div class="stat-value">{{ p.entry_price }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">現價</div>
              <div class="stat-value">{{ p.current_price || '—' }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">股數</div>
              <div class="stat-value">{{ p.shares }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">停損</div>
              <div class="stat-value">{{ p.current_stop || '—' }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">目標</div>
              <div class="stat-value">{{ p.current_target || '—' }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">市值</div>
              <div class="stat-value">{{ money(p.market_value) }}</div>
            </div>
          </div>

          <div v-if="p.latest_advice" class="advice-callout">
            <span class="ai-badge">AI</span>
            <div class="advice-body">
              <div class="advice-action">
                {{ adviceActionLabel(p.latest_advice.action) }}
                <span v-if="p.tracking_status?.latest_snapshot_at" class="advice-time">
                  {{ formatDateTime(p.tracking_status.latest_snapshot_at) }}
                </span>
              </div>
              <div v-if="p.latest_advice.stop_changed || p.latest_advice.target_changed" class="adjust-line">
                <span v-if="p.latest_advice.stop_changed">
                  停損 {{ p.latest_advice.previous_stop }} → {{ p.latest_advice.current_stop }}
                </span>
                <span v-if="p.latest_advice.target_changed">
                  目標 {{ p.latest_advice.previous_target }} → {{ p.latest_advice.current_target }}
                </span>
              </div>
              <div class="health-grid">
                <span>論點 {{ healthLabel(p.latest_advice.thesis_health) }}</span>
                <span>技術 {{ healthLabel(p.latest_advice.technical_health) }}</span>
                <span>籌碼 {{ healthLabel(p.latest_advice.chip_health) }}</span>
                <span>風險 {{ riskLabel(p.latest_advice.risk_pressure) }}</span>
              </div>
              <div class="time-grid">
                <span>預估持有 {{ p.latest_advice.expected_holding_days || '—' }}</span>
                <span>目標 ETA {{ etaLabel(p.latest_advice.target_eta_days) }}</span>
                <span>時間 {{ timePressureLabel(p.latest_advice.time_pressure) }}</span>
              </div>
              <div v-if="p.latest_advice.target_price_reasoning || p.latest_advice.eta_reasoning" class="number-reasons">
                <div v-if="p.latest_advice.target_price_reasoning">
                  <span>目標理由</span>{{ p.latest_advice.target_price_reasoning }}
                </div>
                <div v-if="p.latest_advice.eta_reasoning">
                  <span>ETA 理由</span>{{ p.latest_advice.eta_reasoning }}
                </div>
              </div>
              <div v-if="p.latest_advice.volume_price_signal" class="advice-note">
                {{ p.latest_advice.volume_price_signal }}
              </div>
              <div class="advice-text">{{ p.latest_advice.reasoning }}</div>
            </div>
          </div>

          <details v-if="p.snapshots?.length" class="snapshot-details">
            <summary>每日追蹤紀錄</summary>
            <div v-for="s in [...p.snapshots].slice(-5).reverse()" :key="s.id" class="snapshot-row">
              <span>{{ s.date }}</span>
              <span>{{ adviceActionLabel(s.advice?.action) }}</span>
              <span>停損 {{ s.current_stop || '—' }}</span>
              <span>目標 {{ s.current_target || '—' }}</span>
              <span>{{ s.advice?.reasoning || '' }}</span>
            </div>
          </details>

          <div class="row-actions">
            <el-button
              class="cancel-btn"
              size="small"
              text
              type="danger"
              :loading="positionActionId === p.id"
              @click="cancelPosition(p)"
            >
              取消
            </el-button>
            <span class="row-actions-spacer"></span>
            <el-button
              size="small"
              :disabled="!isActiveStatus(p.status)"
              @click="openAdjust(p)"
            >
              調整
            </el-button>
            <el-button
              size="small"
              :loading="positionActionId === p.id"
              :disabled="!isActiveStatus(p.status)"
              @click="markClosed(p, 'closed')"
            >
              平倉
            </el-button>
            <el-button
              size="small"
              type="danger"
              plain
              :loading="positionActionId === p.id"
              :disabled="!isActiveStatus(p.status)"
              @click="markClosed(p, 'stopped')"
            >
              停損結束
            </el-button>
          </div>
        </article>
      </div>
    </section>

    <section class="section">
      <h2 class="section-title">短線候選</h2>

      <el-skeleton v-if="loading && !candidates.length" :rows="3" animated class="skeleton-block" />
      <el-empty
        v-else-if="!candidates.length"
        description="今日尚無短線候選"
        :image-size="90"
        class="empty-pad"
      />
      <div v-else class="candidate-list">
        <article
          v-for="c in candidates"
          :key="c.id"
          class="data-card candidate-row"
          :class="{ rejected: !c.ai_selected }"
        >
          <span v-if="!c.ai_selected" class="reject-corner">未選入</span>

          <div class="row-main">
            <div class="row-id">
              <span class="symbol">{{ c.stock.symbol }}</span>
              <span class="stock-name">{{ c.stock.name }}</span>
              <span v-if="c.stock.industry" class="industry-tag">{{ c.stock.industry }}</span>
              <button class="quote-btn" title="即時報價" @click.stop="goQuote(c)">💹</button>
            </div>
            <div class="score-badge" :class="{ 'is-dim': !c.ai_selected }">
              <div class="score-num">{{ c.score }}</div>
              <div class="score-label">分數</div>
            </div>
          </div>

          <div class="thesis-line">
            <el-icon class="thesis-icon"><Connection /></el-icon>
            <span>{{ c.swing_thesis?.title || '未連結論點' }}</span>
          </div>
          <div v-if="c.swing_thesis?.source === 'related_stock'" class="thesis-role">
            <span>{{ benefitLabel(c.swing_thesis.benefit_level) }}</span>
            <strong>{{ c.swing_thesis.role }}</strong>
            <em>{{ c.swing_thesis.related_reasoning }}</em>
          </div>
          <div class="reasoning">{{ c.swing_reasoning || c.ai_reasoning }}</div>

          <div class="row-stats compact">
            <div class="stat">
              <div class="stat-label">建議買進</div>
              <div class="stat-value">{{ c.suggested_buy }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">目標價</div>
              <div class="stat-value">{{ c.target_price }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">停損價</div>
              <div class="stat-value">{{ c.stop_loss }}</div>
            </div>
            <div class="stat">
              <div class="stat-label">目標 ETA</div>
              <div class="stat-value">{{ etaLabel(c.swing_entry_plan?.target_eta_days || c.swing_time_horizon_days) }}</div>
            </div>
          </div>
          <div v-if="c.swing_entry_plan?.target_price_reasoning || c.swing_entry_plan?.eta_reasoning" class="number-reasons candidate-reasons">
            <div v-if="c.swing_entry_plan?.target_price_reasoning">
              <span>目標理由</span>{{ c.swing_entry_plan.target_price_reasoning }}
            </div>
            <div v-if="c.swing_entry_plan?.eta_reasoning">
              <span>ETA 理由</span>{{ c.swing_entry_plan.eta_reasoning }}
            </div>
          </div>

          <div class="row-actions">
            <el-button size="small" @click="openSizing(c)">倉位試算</el-button>
            <el-button size="small" type="primary" :disabled="!c.ai_selected" @click="openBuy(c)">
              確認買入
            </el-button>
          </div>
        </article>
      </div>
    </section>

    <el-dialog v-model="buyDialog" title="確認買入" width="380px">
      <el-form label-width="88px">
        <el-form-item label="股票">
          <span class="dialog-symbol">{{ selected?.stock?.symbol }} {{ selected?.stock?.name }}</span>
        </el-form-item>
        <el-form-item label="成本">
          <el-input-number v-model="buyForm.entry_price" :min="0" :step="0.05" style="width: 200px" />
        </el-form-item>
        <el-form-item label="股數">
          <el-input-number v-model="buyForm.shares" :min="1" :step="1000" style="width: 200px" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="buyDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitBuy">建立持倉</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="adjustDialog" title="調整停損 / 目標" width="400px">
      <el-form label-width="88px">
        <el-form-item label="股票">
          <span class="dialog-symbol">
            {{ adjustingPosition?.stock?.symbol }} {{ adjustingPosition?.stock?.name }}
          </span>
        </el-form-item>
        <el-form-item label="成本 / 現價">
          <span class="dialog-symbol">
            {{ adjustingPosition?.entry_price }} / {{ adjustingPosition?.current_price ?? '—' }}
          </span>
        </el-form-item>
        <el-form-item label="停損價">
          <el-input-number
            v-model="adjustForm.current_stop"
            :min="0"
            :step="0.05"
            :precision="2"
            :controls="true"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="目標價">
          <el-input-number
            v-model="adjustForm.current_target"
            :min="0"
            :step="0.05"
            :precision="2"
            :controls="true"
            style="width: 220px"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="adjustDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitAdjust">儲存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="sizingDialog" title="ATR 風險試算" width="400px">
      <el-form label-width="88px">
        <el-form-item label="總資金">
          <el-input-number v-model="sizingForm.capital" :min="1" :step="10000" style="width: 220px" />
        </el-form-item>
        <el-form-item label="風險 %">
          <el-input-number v-model="sizingForm.risk_percent" :min="0.1" :max="100" :step="0.1" style="width: 220px" />
        </el-form-item>
      </el-form>
      <div v-if="sizingResult" class="sizing-result">
        <div class="sizing-item">
          <div class="sizing-label">風險預算</div>
          <div class="sizing-value">{{ money(sizingResult.risk_budget) }}</div>
        </div>
        <div class="sizing-item">
          <div class="sizing-label">每股風險</div>
          <div class="sizing-value">{{ sizingResult.risk_per_share }}</div>
        </div>
        <div class="sizing-item highlight">
          <div class="sizing-label">建議股數</div>
          <div class="sizing-value">
            {{ sizingResult.suggested_shares }}
            <span class="sizing-sub">（{{ sizingResult.suggested_lots }} 張）</span>
          </div>
        </div>
        <div class="sizing-item">
          <div class="sizing-label">所需資金</div>
          <div class="sizing-value">{{ money(sizingResult.capital_required) }}</div>
        </div>
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
import { useRouter } from 'vue-router'
import dayjs from 'dayjs'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  calculateSwingSizing,
  createSwingPosition,
  deleteSwingPosition,
  getSwingCandidates,
  getSwingPositions,
  updateSwingPosition,
} from '../api'

function defaultSwingDate() {
  // 短線選股 19:00 排程跑，18:50 前還是看前一個交易日；之後才切到當日。
  // 週六/日不論時間都是上週五（或更前一個工作日）。
  const now = dayjs()
  const afterCutoff = now.hour() > 18 || (now.hour() === 18 && now.minute() >= 50)
  let d = now
  if (d.day() === 0 || d.day() === 6 || !afterCutoff) {
    d = d.subtract(1, 'day')
    while (d.day() === 0 || d.day() === 6) {
      d = d.subtract(1, 'day')
    }
  }
  return d.format('YYYY-MM-DD')
}
const currentDate = ref(defaultSwingDate())
const router = useRouter()

function goQuote(item) {
  if (!item?.stock?.symbol) return
  router.push({ path: '/quote', query: { symbol: item.stock.symbol } })
}
const loading = ref(false)
const saving = ref(false)
const candidates = ref([])
const positions = ref([])
const exposure = ref(null)
const swingMeta = ref(null)
const buyDialog = ref(false)
const sizingDialog = ref(false)
const adjustDialog = ref(false)
const selected = ref(null)
const adjustingPosition = ref(null)
const positionActionId = ref(null)
const sizingResult = ref(null)
const buyForm = reactive({ entry_price: 0, shares: 1000 })
const sizingForm = reactive({ capital: 1000000, risk_percent: 1 })
const adjustForm = reactive({ current_stop: null, current_target: null })

onMounted(fetchAll)

async function fetchAll() {
  loading.value = true
  try {
    const [cRes, pRes] = await Promise.all([
      getSwingCandidates(currentDate.value),
      getSwingPositions(),
    ])
    candidates.value = cRes.data.data || []
    swingMeta.value = cRes.data || null
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

function openAdjust(position) {
  adjustingPosition.value = position
  adjustForm.current_stop = position.current_stop ? Number(position.current_stop) : null
  adjustForm.current_target = position.current_target ? Number(position.current_target) : null
  adjustDialog.value = true
}

async function submitAdjust() {
  if (!adjustingPosition.value) return
  saving.value = true
  try {
    await updateSwingPosition(adjustingPosition.value.id, {
      current_stop: adjustForm.current_stop,
      current_target: adjustForm.current_target,
    })
    ElMessage.success('已更新停損與目標價')
    adjustDialog.value = false
    await fetchAll()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '更新失敗，請稍後再試')
  } finally {
    saving.value = false
  }
}

async function cancelPosition(position) {
  try {
    await ElMessageBox.confirm(
      `將徹底刪除「${position.stock?.symbol} ${position.stock?.name || ''}」這筆持倉與所有快照紀錄，視同沒持倉過。此操作無法復原。`,
      '取消持倉（刪除紀錄）',
      {
        type: 'error',
        confirmButtonText: '確認刪除',
        cancelButtonText: '不刪除',
        confirmButtonClass: 'el-button--danger',
      },
    )
  } catch {
    return
  }
  positionActionId.value = position.id
  try {
    await deleteSwingPosition(position.id)
    ElMessage.success('已刪除這筆持倉紀錄')
    await fetchAll()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '刪除失敗，請稍後再試')
  } finally {
    positionActionId.value = null
  }
}

async function markClosed(position, status) {
  const isClose = status === 'closed'
  try {
    await ElMessageBox.confirm(
      isClose ? '確定要平倉這筆短線持倉？' : '確定要將這筆持倉標記為停損結束？',
      isClose ? '平倉確認' : '停損結束確認',
      { type: 'warning', confirmButtonText: '確定', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  positionActionId.value = position.id
  try {
    await updateSwingPosition(position.id, {
      status,
      exit_price: position.current_price || position.entry_price,
    })
    ElMessage.success(isClose ? '已平倉' : '已標記停損結束')
    await fetchAll()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失敗，請稍後再試')
  } finally {
    positionActionId.value = null
  }
}

function money(v) {
  if (v === null || v === undefined) return '-'
  return Number(v).toLocaleString('zh-TW', { maximumFractionDigits: 0 })
}

function signed(v) {
  if (v === null || v === undefined) return '-'
  return `${v >= 0 ? '+' : ''}${v}`
}

function formatDateTime(v) {
  if (!v) return '-'
  return dayjs(v).format('MM/DD HH:mm')
}

function adviceActionLabel(v) {
  return {
    hold: '續抱',
    adjust: '調整',
    trim: '減碼',
    exit: '出場',
  }[v] || v || '-'
}

function healthLabel(v) {
  return {
    healthy: '良好',
    neutral: '中性',
    weak: '轉弱',
    broken: '破線',
    invalidated: '失效',
    unknown: '未知',
  }[v] || v || '-'
}

function riskLabel(v) {
  return {
    low: '低',
    medium: '中',
    high: '高',
  }[v] || v || '-'
}

function timePressureLabel(v) {
  return {
    normal: '正常',
    delayed: '落後',
    expired: '過期',
  }[v] || v || '-'
}

function benefitLabel(level) {
  return { core: '核心受益', secondary: '次級受益', watch: '觀察' }[level] || '觀察'
}

function etaLabel(v) {
  if (v === null || v === undefined || v === '') return '—'
  return `約 ${v} 日`
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

function isActiveStatus(status) {
  return status === 'watching' || status === 'holding' || status === 'exit_suggested'
}
</script>

<style scoped>
.swing-page {
  --c-primary: #1d4ed8;
  --c-primary-strong: #1e40af;
  --c-primary-soft: #eef2ff;
  --c-primary-line: #c7d2fe;
  --c-surface: #ffffff;
  --c-border: #e2e8f0;
  --c-border-strong: #cbd5e1;
  --c-text: #0f172a;
  --c-text-sub: #475569;
  --c-text-muted: #94a3b8;
  --c-up: #f56c6c;
  --c-down: #67c23a;
  --r-card: 6px;
  --r-pill: 999px;
  --shadow-card: 0 1px 2px rgba(15, 23, 42, 0.04), 0 1px 3px rgba(15, 23, 42, 0.06);
  --shadow-card-hover: 0 2px 4px rgba(15, 23, 42, 0.06), 0 4px 10px rgba(15, 23, 42, 0.08);

  color: var(--c-text);
  font-feature-settings: 'tnum';
}

.page-header {
  position: sticky;
  top: 0;
  z-index: 10;
  margin: -12px -12px 12px;
  padding: 10px 12px;
  background: var(--c-surface);
  border-bottom: 1px solid var(--c-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.page-title {
  font-size: 22px;
  font-weight: 700;
  margin: 0;
  letter-spacing: 0.3px;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.section {
  margin-top: 18px;
}

.section-title {
  display: flex;
  align-items: center;
  font-size: 15px;
  font-weight: 700;
  margin: 0 0 12px;
  color: var(--c-text);
}

.section-title::before {
  content: '';
  display: inline-block;
  width: 4px;
  height: 14px;
  border-radius: 2px;
  background: var(--c-primary);
  margin-right: 8px;
}

.market-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 12px;
  font-size: 12px;
  color: var(--c-text-sub);
}

.market-meta span {
  padding: 4px 8px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-pill);
  background: #f8fafc;
}

/* KPI cards */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}

.kpi-card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-card);
  padding: 10px 12px;
  box-shadow: var(--shadow-card);
}

.kpi-label {
  font-size: 11px;
  color: var(--c-text-muted);
  letter-spacing: 0.5px;
  margin-bottom: 4px;
}

.kpi-value {
  font-size: 20px;
  font-weight: 700;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}

/* Thesis pills */
.thesis-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}

.thesis-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  background: var(--c-primary-soft);
  border: 1px solid var(--c-primary-line);
  border-radius: var(--r-pill);
  font-size: 12px;
  color: var(--c-primary-strong);
}

.thesis-pill-name {
  font-weight: 600;
}

.thesis-pill-meta {
  color: var(--c-text-sub);
  font-variant-numeric: tabular-nums;
}

/* Loading & empty */
.skeleton-block {
  padding: 8px 4px;
}

.empty-pad {
  padding: 16px 0 8px;
}

/* Lists */
.position-list,
.candidate-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Generic data card */
.data-card {
  position: relative;
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-card);
  padding: 12px 14px;
  box-shadow: var(--shadow-card);
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}

.data-card:hover {
  border-color: var(--c-border-strong);
  box-shadow: var(--shadow-card-hover);
}

.row-main {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.row-id {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 0;
}

.symbol {
  font-size: 18px;
  font-weight: 700;
  color: var(--c-text);
  letter-spacing: 0.3px;
  font-variant-numeric: tabular-nums;
}

.stock-name {
  font-size: 13px;
  color: var(--c-text-sub);
}

.industry-tag {
  font-size: 11px;
  color: var(--c-text-muted);
  padding: 1px 6px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-pill);
}

.quote-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px 4px;
  font-size: 16px;
  line-height: 1;
  opacity: 0.7;
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.quote-btn:hover {
  opacity: 1;
  transform: scale(1.1);
}

/* PnL */
.pnl {
  font-size: 22px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  line-height: 1;
  white-space: nowrap;
}

.price-up {
  color: var(--c-up);
}

.price-down {
  color: var(--c-down);
}

/* Divider inside card */
.row-divider {
  height: 1px;
  background: linear-gradient(to right, transparent, var(--c-border) 12%, var(--c-border) 88%, transparent);
  margin: 10px 0;
}

/* Stats grid (label/value) */
.row-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 8px;
}

.row-stats.compact {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-top: 10px;
}

.stat {
  min-width: 0;
}

.stat-label {
  font-size: 11px;
  color: var(--c-text-muted);
  letter-spacing: 0.4px;
  margin-bottom: 2px;
}

.stat-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Position-specific */
.position-row .row-actions {
  margin-top: 12px;
}

/* AI advice callout */
.advice-callout {
  display: flex;
  gap: 10px;
  margin-top: 12px;
  padding: 10px 12px;
  background: var(--c-primary-soft);
  border-left: 3px solid var(--c-primary);
  border-radius: var(--r-card);
}

.ai-badge {
  flex-shrink: 0;
  width: 26px;
  height: 18px;
  border-radius: 4px;
  background: var(--c-primary);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.5px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-top: 2px;
}

.advice-body {
  flex: 1;
  min-width: 0;
}

.advice-action {
  font-size: 13px;
  font-weight: 700;
  color: var(--c-primary-strong);
  margin-bottom: 2px;
}

.advice-time {
  margin-left: 6px;
  font-size: 11px;
  font-weight: 500;
  color: var(--c-text-muted);
}

.adjust-line,
.health-grid,
.time-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
  font-size: 12px;
}

.adjust-line span,
.health-grid span,
.time-grid span {
  padding: 2px 7px;
  border-radius: var(--r-pill);
  background: #fff;
  border: 1px solid var(--c-primary-line);
  color: var(--c-text-sub);
}

.advice-note {
  margin-top: 6px;
  font-size: 12px;
  color: var(--c-text-sub);
}

.number-reasons {
  display: grid;
  gap: 4px;
  margin-top: 8px;
  font-size: 12px;
  color: var(--c-text-sub);
  line-height: 1.45;
}

.number-reasons div {
  padding: 6px 8px;
  border: 1px solid var(--c-line);
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.72);
}

.number-reasons span {
  display: inline-block;
  margin-right: 6px;
  font-weight: 700;
  color: var(--c-text);
}

.candidate-reasons {
  margin-top: 8px;
}

.advice-text {
  margin-top: 6px;
  font-size: 13px;
  color: var(--c-text-sub);
  line-height: 1.5;
}

.snapshot-details {
  margin-top: 10px;
  font-size: 12px;
  color: var(--c-text-sub);
}

.snapshot-details summary {
  cursor: pointer;
  color: var(--c-primary-strong);
  font-weight: 600;
}

.snapshot-row {
  display: grid;
  grid-template-columns: 78px 48px 86px 86px minmax(0, 1fr);
  gap: 8px;
  padding: 6px 0;
  border-bottom: 1px solid var(--c-border);
}

.snapshot-row span:last-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Row actions */
.row-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.row-actions-spacer {
  flex: 1 1 auto;
  min-width: 4px;
}

.cancel-btn {
  padding-left: 0;
  padding-right: 0;
}

.candidate-row .row-actions {
  justify-content: flex-end;
}

/* Candidate-specific */
.candidate-row.rejected {
  filter: grayscale(0.6);
  opacity: 0.6;
}

.reject-corner {
  position: absolute;
  top: 0;
  right: 12px;
  background: var(--c-text-muted);
  color: #fff;
  font-size: 10px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 0 0 4px 4px;
  letter-spacing: 0.5px;
}

.score-badge {
  flex-shrink: 0;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--c-primary) 0%, var(--c-primary-strong) 100%);
  color: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 6px rgba(29, 78, 216, 0.25);
}

.score-badge.is-dim {
  background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
  box-shadow: none;
}

.score-num {
  font-size: 18px;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.score-label {
  font-size: 9px;
  letter-spacing: 0.5px;
  margin-top: 2px;
  opacity: 0.85;
}

.thesis-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--c-text);
}

.thesis-icon {
  color: var(--c-primary);
  font-size: 14px;
}

.thesis-role {
  display: grid;
  gap: 3px;
  margin-top: 6px;
  padding: 7px 8px;
  border: 1px solid var(--c-primary-line);
  border-radius: 6px;
  background: var(--c-primary-soft);
  font-size: 12px;
  line-height: 1.45;
  color: var(--c-text-sub);
}

.position-thesis-role {
  margin-bottom: 10px;
}

.thesis-role span {
  width: fit-content;
  padding: 1px 6px;
  border-radius: var(--r-pill);
  background: #fff;
  color: var(--c-primary-strong);
  font-weight: 700;
}

.thesis-role strong {
  color: var(--c-text);
}

.thesis-role em {
  font-style: normal;
}

.reasoning {
  margin-top: 6px;
  font-size: 13px;
  color: var(--c-text-sub);
  line-height: 1.55;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Sizing dialog result */
.dialog-symbol {
  font-size: 14px;
  font-weight: 600;
  color: var(--c-text);
}

.sizing-result {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-top: 4px;
  padding: 12px;
  background: #f8fafc;
  border: 1px solid var(--c-border);
  border-radius: var(--r-card);
}

.sizing-item {
  min-width: 0;
}

.sizing-item.highlight {
  grid-column: span 2;
  padding: 8px 10px;
  background: var(--c-primary-soft);
  border-radius: var(--r-card);
  border-left: 3px solid var(--c-primary);
}

.sizing-label {
  font-size: 11px;
  color: var(--c-text-muted);
  letter-spacing: 0.4px;
  margin-bottom: 3px;
}

.sizing-value {
  font-size: 16px;
  font-weight: 700;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}

.sizing-item.highlight .sizing-value {
  color: var(--c-primary-strong);
  font-size: 18px;
}

.sizing-sub {
  font-size: 12px;
  font-weight: 500;
  color: var(--c-text-sub);
  margin-left: 4px;
}

/* Narrow phones: KPI 3-col → 2+1 wrap, candidate stats already 2-col */
@media (max-width: 360px) {
  .kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .kpi-grid .kpi-card:nth-child(3) {
    grid-column: span 2;
  }
  .row-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
