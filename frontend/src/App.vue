<template>
  <div class="app-container" :class="{ 'has-shell': showShell }">
    <router-view />
    <button
      v-if="showShell"
      class="mobile-menu-btn"
      :class="{ active: mobileNavOpen }"
      type="button"
      aria-label="開啟導覽"
      :aria-expanded="mobileNavOpen"
      @click="mobileNavOpen = !mobileNavOpen"
    >
      <span></span>
      <span></span>
      <span></span>
    </button>
    <div
      v-if="showShell && mobileNavOpen"
      class="nav-backdrop"
      @click="mobileNavOpen = false"
    ></div>
    <nav v-if="showShell" class="side-nav" :class="{ open: mobileNavOpen }">
      <div class="nav-header">
        <div>
          <strong>選股助手</strong>
          <span>{{ authStore.user?.name || authStore.user?.email || '已登入' }}</span>
        </div>
        <button class="nav-close" type="button" aria-label="關閉導覽" @click="mobileNavOpen = false">×</button>
      </div>

      <!-- viewer + admin -->
      <router-link to="/swing" class="nav-item" active-class="active" @click="closeMobileNav">
        <el-icon><DataLine /></el-icon>
        <span>短線配置</span>
      </router-link>
      <router-link to="/overnight" class="nav-item" active-class="active" @click="closeMobileNav">
        <el-icon><Moon /></el-icon>
        <span>隔日沖候選</span>
      </router-link>
      <router-link to="/candidates" class="nav-item" active-class="active" @click="closeMobileNav">
        <el-icon><TrendCharts /></el-icon>
        <span>當沖候選</span>
      </router-link>
      <router-link to="/quote" class="nav-item" active-class="active" @click="closeMobileNav">
        <el-icon><Search /></el-icon>
        <span>即時報價</span>
      </router-link>
      <router-link to="/news" class="nav-item" active-class="active" @click="closeMobileNav">
        <el-icon><ChatLineSquare /></el-icon>
        <span>新聞整理</span>
      </router-link>
      <router-link to="/investment-theses" class="nav-item" active-class="active" @click="closeMobileNav">
        <el-icon><Connection /></el-icon>
        <span>AI論點</span>
      </router-link>

      <!-- admin only -->
      <template v-if="authStore.isAdmin">
        <div class="nav-section">績效與管理</div>
        <router-link to="/stats" class="nav-item" active-class="active" @click="closeMobileNav">
          <el-icon><DataAnalysis /></el-icon>
          <span>當沖績效</span>
        </router-link>
        <router-link to="/overnight/stats" class="nav-item" active-class="active" @click="closeMobileNav">
          <el-icon><DataLine /></el-icon>
          <span>隔日績效</span>
        </router-link>
        <router-link to="/swing/stats" class="nav-item" active-class="active" @click="closeMobileNav">
          <el-icon><TrendCharts /></el-icon>
          <span>短線績效</span>
        </router-link>
        <router-link to="/settings" class="nav-item" active-class="active" @click="closeMobileNav">
          <el-icon><Setting /></el-icon>
          <span>設定</span>
        </router-link>
        <router-link to="/users" class="nav-item" active-class="active" @click="closeMobileNav">
          <el-icon><UserFilled /></el-icon>
          <span>用戶管理</span>
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
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { useAuthStore } from './stores/auth'

const route     = useRoute()
const router    = useRouter()
const authStore = useAuthStore()
const mobileNavOpen = ref(false)

const showShell = computed(() => authStore.isAuthenticated && route.name !== 'login')

watch(() => route.fullPath, () => {
  mobileNavOpen.value = false
})

function closeMobileNav() {
  mobileNavOpen.value = false
}

async function handleLogout() {
  try {
    await ElMessageBox.confirm('確定要登出？', '登出', {
      confirmButtonText: '登出',
      cancelButtonText: '取消',
      type: 'warning',
    })
    mobileNavOpen.value = false
    await authStore.logout()
    router.push('/login')
  } catch {
    // 取消，不做任何事
  }
}
</script>

<style scoped>
.app-container {
  min-height: 100vh;
  background: #f5f7fa;
}

.app-container.has-shell {
  padding-left: 232px;
}

.side-nav {
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  width: 232px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 4px;
  border-right: 1px solid #e4e7ed;
  z-index: 999;
  padding: 16px 12px calc(16px + env(safe-area-inset-bottom));
  overflow-y: auto;
}

.nav-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 8px 14px;
  margin-bottom: 4px;
  border-bottom: 1px solid #edf0f5;
}

.nav-header strong,
.nav-header span {
  display: block;
}

.nav-header strong {
  color: #303133;
  font-size: 16px;
  line-height: 1.3;
}

.nav-header span {
  margin-top: 3px;
  color: #909399;
  font-size: 12px;
  word-break: break-all;
}

.nav-close {
  display: none;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 8px;
  background: #f5f7fa;
  color: #606266;
  cursor: pointer;
  font-size: 22px;
  line-height: 1;
}

.nav-section {
  margin: 14px 8px 4px;
  color: #a8abb2;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.5px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  color: #909399;
  font-size: 14px;
  font-weight: 600;
  padding: 11px 12px;
  border-radius: 10px;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.nav-item .el-icon {
  flex: 0 0 auto;
  font-size: 20px;
}

.nav-item.active {
  color: #409eff;
  background: #ecf5ff;
}

.nav-item:hover {
  color: #409eff;
  background: #f5f7fa;
}

.logout-btn {
  width: 100%;
  margin-top: auto;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
}

.logout-btn:hover {
  color: #f56c6c;
}

.mobile-menu-btn,
.nav-backdrop {
  display: none;
}

@media (max-width: 760px) {
  .app-container.has-shell {
    padding-left: 0;
  }

  .mobile-menu-btn {
    position: fixed;
    right: 16px;
    bottom: calc(16px + env(safe-area-inset-bottom));
    z-index: 1002;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 48px;
    height: 48px;
    border: 1px solid #dcdfe6;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(31, 45, 61, 0.18);
    cursor: pointer;
  }

  .mobile-menu-btn span {
    width: 20px;
    height: 2px;
    border-radius: 999px;
    background: #303133;
    transition: transform 0.18s ease, opacity 0.18s ease;
  }

  .mobile-menu-btn.active span:nth-child(1) {
    transform: translateY(6px) rotate(45deg);
  }

  .mobile-menu-btn.active span:nth-child(2) {
    opacity: 0;
  }

  .mobile-menu-btn.active span:nth-child(3) {
    transform: translateY(-6px) rotate(-45deg);
  }

  .nav-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: block;
    background: rgba(15, 23, 42, 0.38);
  }

  .side-nav {
    z-index: 1001;
    width: min(82vw, 300px);
    transform: translateX(-100%);
    box-shadow: 12px 0 30px rgba(31, 45, 61, 0.18);
    transition: transform 0.2s ease;
  }

  .side-nav.open {
    transform: translateX(0);
  }

  .nav-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
}
</style>
