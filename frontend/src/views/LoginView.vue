<template>
  <div class="login-page">
    <div class="login-card">
      <h1 class="login-title">日交易系統</h1>
      <p class="login-subtitle">請登入以繼續</p>

      <el-form ref="formRef" :model="form" :rules="rules" @submit.prevent="handleLogin">
        <el-form-item prop="identifier">
          <el-input
            v-model="form.identifier"
            placeholder="帳號 ID 或電子郵件"
            size="large"
            :prefix-icon="User"
            autocomplete="username"
          />
        </el-form-item>

        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="密碼"
            size="large"
            :prefix-icon="Lock"
            show-password
            autocomplete="current-password"
            @keyup.enter="handleLogin"
          />
        </el-form-item>

        <el-alert
          v-if="errorMsg"
          :title="errorMsg"
          type="error"
          :closable="false"
          style="margin-bottom: 16px"
        />

        <el-button
          type="primary"
          size="large"
          :loading="authStore.loading"
          style="width: 100%"
          @click="handleLogin"
        >
          登入
        </el-button>
      </el-form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { User, Lock } from '@element-plus/icons-vue'
import { useAuthStore } from '../stores/auth'

const router    = useRouter()
const route     = useRoute()
const authStore = useAuthStore()

const formRef  = ref(null)
const errorMsg = ref('')

const form = ref({ identifier: '', password: '' })

const rules = {
  identifier: [{ required: true, message: '請輸入帳號 ID 或電子郵件', trigger: 'blur' }],
  password:   [{ required: true, message: '請輸入密碼', trigger: 'blur' }],
}

async function handleLogin() {
  errorMsg.value = ''
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  try {
    await authStore.login(form.value.identifier, form.value.password)
    const redirect = route.query.redirect || '/'
    router.push(redirect)
  } catch (err) {
    const messages = err.response?.data?.errors?.identifier
    errorMsg.value = messages?.[0] ?? '登入失敗，請稍後再試'
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f7fa;
  padding: 20px;
}

.login-card {
  width: 100%;
  max-width: 380px;
  background: #fff;
  border-radius: 12px;
  padding: 40px 32px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
}

.login-title {
  font-size: 22px;
  font-weight: 700;
  text-align: center;
  margin: 0 0 4px;
  color: #303133;
}

.login-subtitle {
  font-size: 13px;
  color: #909399;
  text-align: center;
  margin: 0 0 28px;
}

.el-form-item {
  margin-bottom: 16px;
}
</style>
