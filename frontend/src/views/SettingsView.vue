<template>
  <div class="page">
    <h1 class="page-title">篩選設定</h1>

    <el-skeleton v-if="formulaLoading" :rows="6" animated />
    <template v-else>
      <el-tabs v-model="activeTab" stretch>

        <!-- ==================== Tab 1: 策略與評分 ==================== -->
        <el-tab-pane label="策略與評分" name="scoring">

          <!-- 策略分類 -->
          <div v-if="formulas.strategy" class="tab-section">
            <div class="section-title">策略分類</div>

            <div class="stock-card">
              <div class="formula-header">
                <span class="formula-title">跌深反彈</span>
                <el-switch v-model="formulas.strategy.config.bounce.enabled" size="small" />
              </div>
              <div v-if="formulas.strategy.config.bounce.enabled" class="formula-body">
                <div class="param-row">
                  <span>加分</span>
                  <el-input-number v-model="formulas.strategy.config.bounce.score" :min="-15" :max="30" size="small" />
                  <span class="unit">分</span>
                </div>
                <div class="param-row">
                  <span>單日急跌門檻</span>
                  <el-input-number v-model="formulas.strategy.config.bounce.washout_drop_pct" :min="-20" :max="0" :step="1" size="small" />
                  <span class="unit">%</span>
                </div>
                <div class="param-row">
                  <span>連兩日合計跌幅</span>
                  <el-input-number v-model="formulas.strategy.config.bounce.two_day_drop_pct" :min="-30" :max="0" :step="1" size="small" />
                  <span class="unit">%</span>
                </div>
                <div class="param-row">
                  <span>洗盤回溯天數</span>
                  <el-input-number v-model="formulas.strategy.config.bounce.washout_lookback_days" :min="1" :max="10" size="small" />
                  <span class="unit">日</span>
                </div>
                <div class="param-row">
                  <span>反彈確認幅度</span>
                  <el-input-number v-model="formulas.strategy.config.bounce.bounce_from_low_pct" :min="0" :max="20" :step="0.5" :precision="1" size="small" />
                  <span class="unit">%</span>
                </div>
              </div>
            </div>

            <div class="stock-card">
              <div class="formula-header">
                <span class="formula-title">突破追多</span>
                <el-switch v-model="formulas.strategy.config.breakout.enabled" size="small" />
              </div>
              <div v-if="formulas.strategy.config.breakout.enabled" class="formula-body">
                <div class="param-row">
                  <span>加分</span>
                  <el-input-number v-model="formulas.strategy.config.breakout.score" :min="-15" :max="30" size="small" />
                  <span class="unit">分</span>
                </div>
                <div class="param-row">
                  <span>前高回溯天數</span>
                  <el-input-number v-model="formulas.strategy.config.breakout.prev_high_days" :min="1" :max="20" size="small" />
                  <span class="unit">日</span>
                </div>
                <div class="param-row">
                  <span>接近前高比例</span>
                  <el-input-number v-model="formulas.strategy.config.breakout.near_breakout_pct" :min="0.90" :max="1.00" :step="0.01" :precision="2" size="small" />
                  <span class="unit">× 前高</span>
                </div>
              </div>
            </div>

            <el-button type="primary" size="small" @click="saveFormula('strategy')" :loading="formulaSaving === 'strategy'">
              儲存策略設定
            </el-button>
          </div>

          <!-- 評分項目 -->
          <div v-if="formulas.scoring" class="tab-section">
            <div class="section-title">評分項目</div>

            <el-collapse v-model="openGroups">
              <el-collapse-item v-for="group in scoringGroups" :key="group.key" :name="group.key">
                <template #title>
                  <span class="group-title">{{ group.label }}</span>
                  <span class="group-count">{{ group.items.length }} 項</span>
                </template>
                <div v-for="item in group.items" :key="item.key" class="scoring-card">
                  <div class="formula-header">
                    <span class="scoring-name">{{ item.label }}</span>
                    <div class="scoring-right">
                      <el-input-number
                        v-model="formulas.scoring.config[item.key].score"
                        :min="-15" :max="30" size="small"
                        :disabled="!formulas.scoring.config[item.key].enabled"
                        style="width: 80px"
                      />
                      <span class="unit">分</span>
                      <el-switch v-model="formulas.scoring.config[item.key].enabled" size="small" />
                    </div>
                  </div>
                  <div v-if="formulas.scoring.config[item.key].enabled && item.params.length > 0" class="scoring-params">
                    <div v-for="p in item.params" :key="p.field" class="param-row">
                      <span>{{ p.label }}</span>
                      <el-input-number
                        v-model="formulas.scoring.config[item.key][p.field]"
                        :min="p.min" :max="p.max" :step="p.step || 1" :precision="p.precision || 0"
                        size="small"
                      />
                      <span class="unit">{{ p.unit }}</span>
                    </div>
                  </div>
                </div>
              </el-collapse-item>
            </el-collapse>

            <el-button type="primary" size="small" style="margin-top: 12px" @click="saveFormula('scoring')" :loading="formulaSaving === 'scoring'">
              儲存評分設定
            </el-button>
          </div>
        </el-tab-pane>

        <!-- ==================== Tab 2: 價格公式 ==================== -->
        <el-tab-pane label="價格公式" name="formula">
          <div class="tab-section">
            <!-- 建議買入價 -->
            <div class="stock-card">
              <div class="formula-title">建議買入價</div>
              <div v-if="formulas.suggested_buy" class="formula-body">
                <div class="source-group">
                  <div class="source-label">支撐來源</div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.suggested_buy.config.sources.recent_low.enabled">近N日最低價</el-checkbox>
                    <el-input-number v-model="formulas.suggested_buy.config.sources.recent_low.days" :min="1" :max="60" size="small" :disabled="!formulas.suggested_buy.config.sources.recent_low.enabled" />
                    <span class="unit">日</span>
                  </div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.suggested_buy.config.sources.ma.enabled">均線 (MA)</el-checkbox>
                    <el-input-number v-model="formulas.suggested_buy.config.sources.ma.period" :min="1" :max="60" size="small" :disabled="!formulas.suggested_buy.config.sources.ma.enabled" />
                    <span class="unit">日</span>
                  </div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.suggested_buy.config.sources.bollinger_middle.enabled">布林中軌</el-checkbox>
                  </div>
                </div>
                <div class="param-group">
                  <div class="param-item">
                    <span>支撐下限</span>
                    <el-input-number v-model="formulas.suggested_buy.config.filter_lower_pct" :min="0.80" :max="1.00" :step="0.01" :precision="2" size="small" />
                    <span class="unit">× 收盤價</span>
                  </div>
                  <div class="param-item">
                    <span>預設折扣</span>
                    <el-input-number v-model="formulas.suggested_buy.config.fallback_pct" :min="0.90" :max="1.00" :step="0.01" :precision="2" size="small" />
                    <span class="unit">× 收盤價</span>
                  </div>
                </div>
                <el-button type="primary" size="small" @click="saveFormula('suggested_buy')" :loading="formulaSaving === 'suggested_buy'">儲存</el-button>
              </div>
            </div>

            <!-- 目標價 -->
            <div class="stock-card">
              <div class="formula-title">目標價</div>
              <div v-if="formulas.target_price" class="formula-body">
                <div class="source-group">
                  <div class="source-label">壓力來源</div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.target_price.config.sources.recent_high.enabled">近N日最高價</el-checkbox>
                    <el-input-number v-model="formulas.target_price.config.sources.recent_high.days" :min="1" :max="60" size="small" :disabled="!formulas.target_price.config.sources.recent_high.enabled" />
                    <span class="unit">日</span>
                  </div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.target_price.config.sources.atr.enabled">ATR 倍數</el-checkbox>
                    <el-input-number v-model="formulas.target_price.config.sources.atr.multiplier" :min="0.5" :max="5.0" :step="0.1" :precision="1" size="small" :disabled="!formulas.target_price.config.sources.atr.enabled" />
                    <span class="unit">倍</span>
                  </div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.target_price.config.sources.bollinger_upper.enabled">布林上軌</el-checkbox>
                  </div>
                </div>
                <div class="param-group">
                  <div class="param-item">
                    <span>壓力上限</span>
                    <el-input-number v-model="formulas.target_price.config.filter_upper_pct" :min="1.00" :max="1.30" :step="0.01" :precision="2" size="small" />
                    <span class="unit">× 收盤價</span>
                  </div>
                  <div class="param-item">
                    <span>預設漲幅</span>
                    <el-input-number v-model="formulas.target_price.config.fallback_pct" :min="1.00" :max="1.20" :step="0.01" :precision="2" size="small" />
                    <span class="unit">× 收盤價</span>
                  </div>
                </div>
                <el-button type="primary" size="small" @click="saveFormula('target_price')" :loading="formulaSaving === 'target_price'">儲存</el-button>
              </div>
            </div>

            <!-- 停損價 -->
            <div class="stock-card">
              <div class="formula-title">停損價</div>
              <div v-if="formulas.stop_loss" class="formula-body">
                <div class="source-group">
                  <div class="source-label">停損來源</div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.stop_loss.config.sources.atr.enabled">ATR 倍數</el-checkbox>
                    <el-input-number v-model="formulas.stop_loss.config.sources.atr.multiplier" :min="0.5" :max="5.0" :step="0.1" :precision="1" size="small" :disabled="!formulas.stop_loss.config.sources.atr.enabled" />
                    <span class="unit">倍</span>
                  </div>
                  <div class="source-item">
                    <el-checkbox v-model="formulas.stop_loss.config.sources.recent_low.enabled">近N日最低價</el-checkbox>
                    <el-input-number v-model="formulas.stop_loss.config.sources.recent_low.days" :min="1" :max="60" size="small" :disabled="!formulas.stop_loss.config.sources.recent_low.enabled" />
                    <span class="unit">日</span>
                  </div>
                </div>
                <div class="param-group">
                  <div class="param-item">
                    <span>預設停損</span>
                    <el-input-number v-model="formulas.stop_loss.config.fallback_pct" :min="0.90" :max="1.00" :step="0.005" :precision="3" size="small" />
                    <span class="unit">× 收盤價</span>
                  </div>
                </div>
                <el-button type="primary" size="small" @click="saveFormula('stop_loss')" :loading="formulaSaving === 'stop_loss'">儲存</el-button>
              </div>
            </div>
          </div>
        </el-tab-pane>

        <!-- ==================== Tab 3: 自訂規則 ==================== -->
        <el-tab-pane label="自訂規則" name="rules">
          <div class="tab-section">
            <el-button type="primary" size="small" @click="showAdd = true" style="margin-bottom: 12px">
              <el-icon><Plus /></el-icon> 新增規則
            </el-button>

            <el-skeleton v-if="loading" :rows="4" animated />
            <div v-else>
              <div v-for="rule in rules" :key="rule.id" class="stock-card rule-card">
                <div class="rule-header">
                  <span class="rule-name">{{ rule.name }}</span>
                  <div class="rule-actions">
                    <el-switch v-model="rule.is_active" size="small" @change="toggleRule(rule)" />
                    <el-button type="danger" size="small" text @click="removeRule(rule)">
                      <el-icon><Delete /></el-icon>
                    </el-button>
                  </div>
                </div>
                <div class="rule-conditions">
                  <el-tag v-for="(cond, i) in rule.conditions" :key="i" size="small" effect="plain">
                    {{ formatCondition(cond) }}
                  </el-tag>
                </div>
              </div>
              <el-empty v-if="rules.length === 0" description="尚未設定篩選規則" />
            </div>
          </div>
        </el-tab-pane>

        <!-- ==================== Tab 4: 資料同步 ==================== -->
        <el-tab-pane label="資料同步" name="sync">
          <div class="tab-section">
            <div class="stock-card">
              <div class="sync-row">
                <span>同步日期</span>
                <el-date-picker
                  v-model="syncDate"
                  type="date"
                  format="YYYY-MM-DD"
                  value-format="YYYY-MM-DD"
                  :clearable="false"
                  size="small"
                  style="width: 150px"
                />
              </div>
              <div class="sync-tasks">
                <el-checkbox-group v-model="syncTasks">
                  <el-checkbox value="daily">日行情</el-checkbox>
                  <el-checkbox value="institutional">法人買賣</el-checkbox>
                  <el-checkbox value="margin">融資融券</el-checkbox>
                  <el-checkbox value="screen">選股篩選</el-checkbox>
                  <el-checkbox value="results">盤後結果</el-checkbox>
                </el-checkbox-group>
              </div>
              <div class="sync-actions">
                <el-button size="small" @click="syncTasks = ['daily', 'institutional', 'margin']">全選行情</el-button>
                <el-button type="primary" size="small" @click="runSync" :loading="syncing" :disabled="syncTasks.length === 0">
                  開始同步
                </el-button>
              </div>
              <div v-if="syncResults.length > 0" class="sync-results">
                <div v-for="r in syncResults" :key="r.task" class="sync-result-item" :class="r.success ? 'sync-ok' : 'sync-fail'">
                  <span class="sync-icon">{{ r.success ? '✓' : '✗' }}</span>
                  <span class="sync-label">{{ r.label }}</span>
                  <span class="sync-msg">{{ r.message }}</span>
                </div>
              </div>
            </div>
          </div>
        </el-tab-pane>

      </el-tabs>

      <!-- 新增規則 Dialog -->
      <el-dialog v-model="showAdd" title="新增篩選規則" :width="'95%'" style="max-width: 500px;">
        <el-form label-position="top">
          <el-form-item label="規則名稱">
            <el-input v-model="form.name" placeholder="例：量能突破" />
          </el-form-item>
          <div v-for="(cond, i) in form.conditions" :key="i" class="condition-row">
            <el-select v-model="cond.field" size="small" placeholder="指標" style="width: 35%">
              <el-option value="volume" label="成交量(張)" />
              <el-option value="amplitude" label="振幅(%)" />
              <el-option value="change_percent" label="漲跌幅(%)" />
              <el-option value="foreign_net" label="外資淨買(張)" />
              <el-option value="trust_net" label="投信淨買(張)" />
              <el-option value="total_net" label="法人合計(張)" />
              <el-option value="margin_change" label="融資增減(張)" />
            </el-select>
            <el-select v-model="cond.operator" size="small" style="width: 20%">
              <el-option value=">" label=">" />
              <el-option value=">=" label=">=" />
              <el-option value="<" label="<" />
              <el-option value="<=" label="<=" />
            </el-select>
            <el-input-number v-model="cond.value" size="small" style="width: 30%" />
            <el-button size="small" text type="danger" @click="form.conditions.splice(i, 1)">
              <el-icon><Delete /></el-icon>
            </el-button>
          </div>
          <el-button size="small" @click="addCondition" style="margin-top: 8px">
            <el-icon><Plus /></el-icon> 增加條件
          </el-button>
        </el-form>
        <template #footer>
          <el-button @click="showAdd = false">取消</el-button>
          <el-button type="primary" @click="saveRule" :loading="saving">儲存</el-button>
        </template>
      </el-dialog>
    </template>

    <router-link to="/spec" class="spec-link">
      <el-icon><Document /></el-icon>
      <span>系統規格書</span>
      <el-icon class="spec-arrow"><ArrowRight /></el-icon>
    </router-link>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import {
  getScreeningRules, createScreeningRule, updateScreeningRule, deleteScreeningRule,
  getFormulaSettings, updateFormulaSetting,
  triggerDataSync,
} from '../api'
import { ElMessageBox, ElMessage } from 'element-plus'

const activeTab = ref('scoring')

// 篩選規則
const rules = ref([])
const loading = ref(false)
const showAdd = ref(false)
const saving = ref(false)

const form = ref({
  name: '',
  conditions: [{ field: 'volume', operator: '>', value: 1000 }],
})

// 公式設定
const formulas = ref({})
const formulaLoading = ref(false)
const formulaSaving = ref(null)

// 資料同步
const syncDate = ref(new Date().toISOString().slice(0, 10))
const syncTasks = ref(['daily', 'institutional', 'margin'])
const syncing = ref(false)
const syncResults = ref([])

// 評分 collapse 預設展開技術面
const openGroups = ref(['technical'])

// 評分項目（分組）
const scoringGroups = [
  {
    key: 'technical',
    label: '技術面',
    items: [
      { key: 'volume_surge', label: '量能放大', params: [{ field: 'ratio', label: '爆量倍數', min: 1, max: 5, step: 0.1, precision: 1, unit: '倍' }] },
      { key: 'ma_bullish', label: '均線多頭排列', params: [] },
      { key: 'above_ma5', label: '站上 5MA', params: [] },
      { key: 'kd_golden_cross', label: 'KD 黃金交叉', params: [] },
      { key: 'rsi_moderate', label: 'RSI 適中', params: [
        { field: 'min', label: '最低', min: 0, max: 100, unit: '' },
        { field: 'max', label: '最高', min: 0, max: 100, unit: '' },
      ] },
      { key: 'amplitude_moderate', label: '振幅適中', params: [
        { field: 'min', label: '最低', min: 0, max: 20, step: 0.5, precision: 1, unit: '%' },
        { field: 'max', label: '最高', min: 0, max: 20, step: 0.5, precision: 1, unit: '%' },
      ] },
      { key: 'break_prev_high', label: '突破前高', params: [] },
      { key: 'bollinger_position', label: '布林中軌上方', params: [] },
    ],
  },
  {
    key: 'institutional',
    label: '籌碼面',
    items: [
      { key: 'foreign_buy', label: '外資買超', params: [] },
      { key: 'consecutive_buy', label: '法人連續買超', params: [{ field: 'min_days', label: '最少天數', min: 1, max: 10, unit: '日' }] },
      { key: 'trust_buy', label: '投信買超', params: [] },
      { key: 'margin_decrease', label: '融資減少', params: [] },
      { key: 'foreign_big_buy', label: '外資大買', params: [{ field: 'volume_ratio', label: '佔成交量比', min: 0.01, max: 0.50, step: 0.01, precision: 2, unit: '' }] },
      { key: 'dealer_big_buy', label: '自營大買', params: [{ field: 'volume_ratio', label: '佔成交量比', min: 0.01, max: 0.50, step: 0.01, precision: 2, unit: '' }] },
    ],
  },
  {
    key: 'momentum',
    label: '動能面',
    items: [
      { key: 'high_volatility', label: '高波動', params: [
        { field: 'lookback_days', label: '回溯天數', min: 1, max: 30, unit: '日' },
        { field: 'min_amplitude', label: '振幅門檻', min: 1, max: 20, step: 0.5, precision: 1, unit: '%' },
      ] },
      { key: 'strong_trend', label: '近月強勢', params: [
        { field: 'lookback_days', label: '回溯天數', min: 5, max: 60, unit: '日' },
        { field: 'min_gain_pct', label: '漲幅門檻', min: 1, max: 100, step: 1, unit: '%' },
      ] },
      { key: 'high_volume', label: '萬張量能', params: [{ field: 'min_lots', label: '最低張數', min: 1000, max: 100000, step: 1000, unit: '張' }] },
    ],
  },
  {
    key: 'penalty',
    label: '負面因子（扣分）',
    items: [
      { key: 'volume_shrink', label: '量能萎縮', params: [] },
      { key: 'extended_rally', label: '連漲過度延伸', params: [{ field: 'min_days', label: '連漲天數', min: 3, max: 10, unit: '日' }] },
      { key: 'low_rr', label: '風報比偏低', params: [{ field: 'threshold', label: 'RR 門檻', min: 1.0, max: 2.0, step: 0.1, precision: 1, unit: '' }] },
    ],
  },
]

onMounted(async () => {
  loading.value = true
  formulaLoading.value = true
  try {
    const [rulesRes, formulaRes] = await Promise.all([
      getScreeningRules(),
      getFormulaSettings(),
    ])
    rules.value = rulesRes.data
    formulas.value = formulaRes.data
  } finally {
    loading.value = false
    formulaLoading.value = false
  }
})

async function saveFormula(type) {
  formulaSaving.value = type
  try {
    await updateFormulaSetting(type, formulas.value[type].config)
    ElMessage.success('已儲存')
  } catch {
    ElMessage.error('儲存失敗')
  } finally {
    formulaSaving.value = null
  }
}

async function runSync() {
  syncing.value = true
  syncResults.value = []
  try {
    const { data } = await triggerDataSync(syncDate.value, syncTasks.value)
    syncResults.value = data.results
    const ok = data.results.filter(r => r.success).length
    const fail = data.results.length - ok
    if (fail === 0) {
      ElMessage.success(`同步完成 (${ok} 項)`)
    } else {
      ElMessage.warning(`${ok} 項成功，${fail} 項失敗`)
    }
  } catch {
    ElMessage.error('同步請求失敗')
  } finally {
    syncing.value = false
  }
}

function addCondition() {
  form.value.conditions.push({ field: 'volume', operator: '>', value: 0 })
}

async function saveRule() {
  if (!form.value.name) {
    ElMessage.warning('請輸入規則名稱')
    return
  }
  saving.value = true
  try {
    const { data } = await createScreeningRule(form.value)
    rules.value.push(data)
    showAdd.value = false
    form.value = { name: '', conditions: [{ field: 'volume', operator: '>', value: 1000 }] }
    ElMessage.success('已新增')
  } finally {
    saving.value = false
  }
}

async function toggleRule(rule) {
  await updateScreeningRule(rule.id, { is_active: rule.is_active })
}

async function removeRule(rule) {
  await ElMessageBox.confirm('確定刪除此規則？', '確認')
  await deleteScreeningRule(rule.id)
  rules.value = rules.value.filter(r => r.id !== rule.id)
  ElMessage.success('已刪除')
}

function formatCondition(cond) {
  const labels = {
    volume: '成交量', amplitude: '振幅', change_percent: '漲跌幅',
    foreign_net: '外資淨買', trust_net: '投信淨買',
    total_net: '法人合計', margin_change: '融資增減',
  }
  return `${labels[cond.field] || cond.field} ${cond.operator} ${cond.value}`
}
</script>

<style scoped>
.tab-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.section-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  margin-bottom: 4px;
}

.tab-section .section-title:not(:first-child) {
  margin-top: 16px;
}

/* 策略 & 公式卡片 */
.formula-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.formula-title {
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 10px;
}

.formula-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.param-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

/* 評分 collapse */
.group-title {
  font-weight: 600;
  font-size: 14px;
}

.group-count {
  margin-left: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.scoring-card {
  padding: 10px 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.scoring-card:last-child {
  border-bottom: none;
}

.scoring-card .formula-header {
  margin-bottom: 0;
}

.scoring-name {
  font-size: 14px;
}

.scoring-right {
  display: flex;
  align-items: center;
  gap: 6px;
}

.scoring-params {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed var(--el-border-color-lighter);
}

/* 價格公式 */
.source-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.source-label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.source-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-left: 4px;
}

.param-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 4px;
  border-top: 1px solid var(--el-border-color-lighter);
}

.param-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.unit {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/* 自訂規則 */
.rule-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.rule-name {
  font-weight: 600;
  font-size: 15px;
}

.rule-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rule-conditions {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.condition-row {
  display: flex;
  gap: 4px;
  align-items: center;
  margin-top: 8px;
}

/* 資料同步 */
.sync-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.sync-tasks {
  margin: 10px 0;
}

.sync-actions {
  display: flex;
  gap: 8px;
}

.sync-results {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-top: 10px;
  border-top: 1px solid var(--el-border-color-lighter);
}

.sync-result-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  line-height: 1.6;
}

.sync-icon {
  font-weight: 700;
  width: 14px;
  flex-shrink: 0;
}

.sync-ok .sync-icon { color: #67c23a; }
.sync-fail .sync-icon { color: #f56c6c; }

.sync-label {
  font-weight: 600;
  white-space: nowrap;
}

.sync-msg {
  color: #606266;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.spec-link {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 20px;
  padding: 14px 16px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  text-decoration: none;
  color: #303133;
  font-size: 14px;
  font-weight: 500;
}

.spec-arrow {
  margin-left: auto;
  color: #c0c4cc;
}
</style>
