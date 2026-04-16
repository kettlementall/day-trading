import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import zhTw from 'element-plus/es/locale/lang/zh-tw'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import router from './router'
import App from './App.vue'
import './styles/global.css'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}

const pinia = createPinia()
app.use(pinia)
app.use(router)
app.use(ElementPlus, { locale: zhTw, size: 'default' })

// 掛載前先從 /auth/me 恢復登入狀態（處理頁面重整）
const authStore = useAuthStore()
authStore.fetchMe().finally(() => app.mount('#app'))
