<template>
  <div class="app-container">
    <router-view />
    <nav v-if="authStore.isAuthenticated && route.name !== 'login'" class="bottom-nav">
      <!-- viewer + admin -->
      <router-link to="/" class="nav-item" active-class="active">
        <el-icon><TrendCharts /></el-icon>
        <span>候選標的</span>
      </router-link>
      <router-link to="/overnight" class="nav-item" active-class="active">
        <el-icon><Moon /></el-icon>
        <span>隔日沖</span>
      </router-link>

      <!-- admin only -->
      <template v-if="authStore.isAdmin">
        <router-link to="/stats" class="nav-item" active-class="active">
          <el-icon><DataAnalysis /></el-icon>
          <span>績效統計</span>
        </router-link>
        <router-link to="/overnight/stats" class="nav-item" active-class="active">
          <el-icon><DataLine /></el-icon>
          <span>隔日績效</span>
        </router-link>
        <router-link to="/news" class="nav-item" active-class="active">
          <el-icon><ChatLineSquare /></el-icon>
          <span>消息面</span>
        </router-link>
        <router-link to="/settings" class="nav-item" active-class="active">
          <el-icon><Setting /></el-icon>
          <span>設定</span>
        </router-link>
        <router-link to="/users" class="nav-item" active-class="active">
          <el-icon><UserFilled /></el-icon>
          <span>用戶</span>
        </router-link>
      </template>

      <!-- 登出（所有人） -->
      <button class="nav-item logout-btn" @click="handleLogout">
        <el-icon><SwitchButton /></el-icon>
        <span>登出</span>
      </button>
    </nav>
  </div>
</template>

<script setup>
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const route     = useRoute()
const router    = useRouter()
const authStore = useAuthStore()

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.app-container {
  min-height: 100vh;
  padding-bottom: 60px;
  background: #f5f7fa;
}

.bottom-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  height: 56px;
  background: #fff;
  display: flex;
  justify-content: space-around;
  align-items: center;
  border-top: 1px solid #e4e7ed;
  z-index: 999;
  padding-bottom: env(safe-area-inset-bottom);
}

.nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  text-decoration: none;
  color: #909399;
  font-size: 11px;
  padding: 4px 12px;
}

.nav-item .el-icon {
  font-size: 22px;
}

.nav-item.active {
  color: #409eff;
}

.logout-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
}

.logout-btn:hover {
  color: #f56c6c;
}
</style>
