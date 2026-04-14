<template>
  <div class="page">
    <h1 class="page-title">篩選設定</h1>

    <el-skeleton v-if="formulaLoading" :rows="6" animated />
    <template v-else>
      <el-tabs v-model="activeTab" stretch>

        <!-- ==================== Tab 1: 價格公式 ==================== -->
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

        <!-- ==================== Tab 3: 訊號標籤 ==================== -->
        <el-tab-pane label="訊號標籤" name="labels">
          <div v-if="formulas.signal_labels" class="tab-section">

            <!-- 量放大 -->
            <div class="stock-card">
              <div class="label-header">
                <div>
                  <div class="label-title">{{ formulas.signal_labels.config.volume_surge.label }}</div>
                  <div class="label-desc">前日量 > N日均量 × 倍數</div>
                </div>
                <el-switch v-model="formulas.signal_labels.config.volume_surge.enabled" size="small" />
              </div>
              <div v-if="formulas.signal_labels.config.volume_surge.enabled" class="label-params">
                <div class="param-item">
                  <span>均量天數</span>
                  <el-input-number v-model="formulas.signal_labels.config.volume_surge.days" :min="1" :max="20" size="small" />
                  <span class="unit">日</span>
                </div>
                <div class="param-item">
                  <span>放量倍數</span>
                  <el-input-number v-model="formulas.signal_labels.config.volume_surge.multiplier" :min="1.0" :max="5.0" :step="0.1" :precision="1" size="small" />
                  <span class="unit">倍</span>
                </div>
              </div>
            </div>

            <!-- 外資買超 -->
            <div class="stock-card">
              <div class="label-header">
                <div>
                  <div class="label-title">{{ formulas.signal_labels.config.foreign_buy.label }}</div>
                  <div class="label-desc">最近1日外資淨買超過閾值</div>
                </div>
                <el-switch v-model="formulas.signal_labels.config.foreign_buy.enabled" size="small" />
              </div>
              <div v-if="formulas.signal_labels.config.foreign_buy.enabled" class="label-params">
                <div class="param-item">
                  <span>最小淨買</span>
                  <el-input-number v-model="formulas.signal_labels.config.foreign_buy.min_net" :min="-99999" :step="100" size="small" />
                  <span class="unit">張</span>
                </div>
              </div>
            </div>

            <!-- 投信買超 -->
            <div class="stock-card">
              <div class="label-header">
                <div>
                  <div class="label-title">{{ formulas.signal_labels.config.trust_buy.label }}</div>
                  <div class="label-desc">最近1日投信淨買超過閾值</div>
                </div>
                <el-switch v-model="formulas.signal_labels.config.trust_buy.enabled" size="small" />
              </div>
              <div v-if="formulas.signal_labels.config.trust_buy.enabled" class="label-params">
                <div class="param-item">
                  <span>最小淨買</span>
                  <el-input-number v-model="formulas.signal_labels.config.trust_buy.min_net" :min="-99999" :step="100" size="small" />
                  <span class="unit">張</span>
                </div>
              </div>
            </div>

            <!-- 突破前高 -->
            <div class="stock-card">
              <div class="label-header">
                <div>
                  <div class="label-title">{{ formulas.signal_labels.config.breakout_high.label }}</div>
                  <div class="label-desc">前日收盤突破近N日最高價</div>
                </div>
                <el-switch v-model="formulas.signal_labels.config.breakout_high.enabled" size="small" />
              </div>
              <div v-if="formulas.signal_labels.config.breakout_high.enabled" class="label-params">
                <div class="param-item">
                  <span>回溯天數</span>
                  <el-input-number v-model="formulas.signal_labels.config.breakout_high.days" :min="1" :max="20" size="small" />
                  <span class="unit">日</span>
                </div>
              </div>
            </div>

            <!-- 融資減 -->
            <div class="stock-card">
              <div class="label-header">
                <div>
                  <div class="label-title">{{ formulas.signal_labels.config.margin_decrease.label }}</div>
                  <div class="label-desc">最近1日融資增減低於閾值</div>
                </div>
                <el-switch v-model="formulas.signal_labels.config.margin_decrease.enabled" size="small" />
              </div>
              <div v-if="formulas.signal_labels.config.margin_decrease.enabled" class="label-params">
                <div class="param-item">
                  <span>最大增減</span>
                  <el-input-number v-model="formulas.signal_labels.config.margin_decrease.max_change" :max="0" :step="100" size="small" />
                  <span class="unit">張</span>
                </div>
              </div>
            </div>

            <el-button type="primary" size="small" @click="saveFormula('signal_labels')" :loading="formulaSaving === 'signal_labels'">
              儲存標籤設定
            </el-button>
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
  getFormulaSettings, updateFormulaSetting,
  triggerDataSync,
} from '../api'
import { ElMessage } from 'element-plus'

const activeTab = ref('formula')

// 公式設定
const formulas = ref({})
const formulaLoading = ref(false)
const formulaSaving = ref(null)

// 資料同步
const syncDate = ref(new Date().toISOString().slice(0, 10))
const syncTasks = ref(['daily', 'institutional', 'margin'])
const syncing = ref(false)
const syncResults = ref([])

onMounted(async () => {
  formulaLoading.value = true
  try {
    const { data } = await getFormulaSettings()
    formulas.value = data
  } finally {
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

</script>

<style scoped>
.tab-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* 公式卡片 */
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

/* 訊號標籤 */
.label-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
}

.label-title {
  font-weight: 600;
  font-size: 15px;
}

.label-desc {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-top: 2px;
}

.label-params {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--el-border-color-lighter);
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
