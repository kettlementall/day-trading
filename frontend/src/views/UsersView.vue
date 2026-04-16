<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">用戶管理</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">新增用戶</el-button>
    </div>

    <el-skeleton v-if="loading" :rows="5" animated />

    <el-table v-else :data="users" style="width: 100%">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column prop="name" label="姓名" />
      <el-table-column prop="email" label="電子郵件" />
      <el-table-column prop="role" label="角色" width="100">
        <template #default="{ row }">
          <el-tag :type="row.role === 'admin' ? 'danger' : 'info'" size="small">
            {{ row.role === 'admin' ? '管理員' : '觀看者' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="建立日期" width="120">
        <template #default="{ row }">
          {{ dayjs(row.created_at).format('YYYY/MM/DD') }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="openEdit(row)">編輯</el-button>
          <el-button
            size="small"
            type="danger"
            :disabled="row.id === authStore.user?.id"
            @click="confirmDelete(row)"
          >
            刪除
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 新增/編輯 Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEditing ? '編輯用戶' : '新增用戶'"
      width="420px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="90px">
        <el-form-item v-if="isEditing" label="登入 ID">
          <span style="font-weight: 600; color: #409eff">{{ editingId }}</span>
          <span style="font-size: 12px; color: #909399; margin-left: 8px">（登入時填此 ID）</span>
        </el-form-item>
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="用戶姓名" />
        </el-form-item>
        <el-form-item label="電子郵件" prop="email">
          <el-input v-model="form.email" placeholder="選填" />
        </el-form-item>
        <el-form-item label="密碼" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            :placeholder="isEditing ? '留空則不修改密碼' : '至少 8 個字元'"
          />
        </el-form-item>
        <el-form-item label="角色" prop="role">
          <el-select v-model="form.role" style="width: 100%">
            <el-option label="管理員" value="admin" />
            <el-option label="觀看者" value="viewer" />
          </el-select>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">
          {{ isEditing ? '儲存' : '建立' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessageBox, ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { useAuthStore } from '../stores/auth'
import { getUsers, createUser, updateUser, deleteUser } from '../api'

const authStore = useAuthStore()

const users   = ref([])
const loading = ref(false)
const saving  = ref(false)

const dialogVisible = ref(false)
const isEditing     = ref(false)
const editingId     = ref(null)
const formRef       = ref(null)

const form = ref({ name: '', email: '', password: '', role: 'viewer' })

const formRules = {
  name:  [{ required: true, message: '請輸入姓名', trigger: 'blur' }],
  email: [
    {
      validator: (rule, value, callback) => {
        if (!value) {
          callback()
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
          callback(new Error('請輸入有效電子郵件格式'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  password: [
    {
      validator: (rule, value, callback) => {
        if (!isEditing.value && !value) {
          callback(new Error('請輸入密碼'))
        } else if (value && value.length < 8) {
          callback(new Error('密碼至少 8 個字元'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  role: [{ required: true, message: '請選擇角色', trigger: 'change' }],
}

async function fetchUsers() {
  loading.value = true
  try {
    const { data } = await getUsers()
    users.value = data
  } finally {
    loading.value = false
  }
}

function openCreate() {
  isEditing.value = false
  editingId.value = null
  form.value = { name: '', email: '', password: '', role: 'viewer' }
  dialogVisible.value = true
}

function openEdit(user) {
  isEditing.value = true
  editingId.value = user.id
  form.value = { name: user.name, email: user.email, password: '', role: user.role }
  dialogVisible.value = true
}

async function handleSave() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  saving.value = true
  try {
    const payload = { ...form.value }
    if (isEditing.value && !payload.password) delete payload.password
    if (isEditing.value) {
      await updateUser(editingId.value, payload)
      ElMessage.success('用戶已更新')
    } else {
      const { data } = await createUser(payload)
      ElMessage.success(`用戶已建立，登入 ID：${data.id}`)
    }
    dialogVisible.value = false
    await fetchUsers()
  } catch (err) {
    const msg = err.response?.data?.message || '操作失敗'
    ElMessage.error(msg)
  } finally {
    saving.value = false
  }
}

async function confirmDelete(user) {
  try {
    await ElMessageBox.confirm(
      `確定要刪除用戶「${user.name}」嗎？此操作無法復原。`,
      '確認刪除',
      { type: 'warning', confirmButtonText: '刪除', cancelButtonText: '取消' }
    )
    await deleteUser(user.id)
    ElMessage.success('用戶已刪除')
    await fetchUsers()
  } catch (err) {
    if (err !== 'cancel') ElMessage.error('刪除失敗')
  }
}

onMounted(fetchUsers)
</script>

<style scoped>
.page {
  padding: 16px;
  padding-bottom: 80px;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.page-title {
  font-size: 18px;
  font-weight: 700;
  margin: 0;
  color: #303133;
}
</style>
