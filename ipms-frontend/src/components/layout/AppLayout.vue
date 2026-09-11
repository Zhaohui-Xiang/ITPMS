<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Sidebar from './Sidebar.vue'
import HeaderBar from './HeaderBar.vue'
import BreadcrumbNav from './BreadcrumbNav.vue'
import { useSidebarStore } from '@/stores/sidebar'

const sidebarStore = useSidebarStore()
const route = useRoute()
const mobile = ref(false)
let mediaQuery
function updateViewport() {
  mobile.value = mediaQuery.matches
  if (mobile.value) sidebarStore.collapsed = true
}
onMounted(() => {
  mediaQuery = window.matchMedia('(max-width: 767px)')
  updateViewport()
  mediaQuery.addEventListener('change', updateViewport)
})
onBeforeUnmount(() => mediaQuery?.removeEventListener('change', updateViewport))
watch(() => route.fullPath, () => {
  if (mobile.value) sidebarStore.collapsed = true
})
</script>

<template>
  <div class="app-layout">
    <!-- 侧边栏 -->
    <Sidebar />
    <button
      v-if="mobile && !sidebarStore.collapsed"
      class="navigation-backdrop"
      aria-label="关闭导航"
      @click="sidebarStore.collapsed = true"
    />

    <div
      class="main-area"
      :class="{ collapsed: sidebarStore.collapsed }"
    >
      <!-- 顶部栏 -->
      <HeaderBar />

      <!-- 内容区域 -->
      <div class="content-wrapper">
        <!-- 面包屑 -->
        <BreadcrumbNav />

        <!-- 页面内容 -->
        <main class="content-main">
          <router-view />
        </main>
      </div>

      <!-- 底部状态栏 -->
      <div class="footer-bar">
        <span>Voltage IPMS</span>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.app-layout {
  display: flex;
  height: 100vh;
  min-width: 0;
  width: 100%;
  overflow: hidden;
  background: $color-canvas;
}

.main-area {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  margin-left: $sidebar-width;
  transition: margin-left 0.3s ease;
  overflow: hidden;
  background: $color-canvas;

  &.collapsed {
    margin-left: $sidebar-collapsed-width;
  }
}

.content-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.content-main {
  flex: 1;
  overflow-y: auto;
  padding: 0 0 16px 0;
  background: $color-canvas;
}

.footer-bar {
  height: $footer-height;
  line-height: $footer-height;
  text-align: center;
  font-size: $font-size-caption;
  color: $color-muted;
  background: #fff;
  border-top: 1px solid $color-border;
  flex-shrink: 0;
}
.navigation-backdrop {
  position: fixed;
  inset: 0;
  border: 0;
  background: rgba(23, 33, 43, 0.25);
  z-index: 1000;
}
@media (max-width: 767px) {
  .main-area, .main-area.collapsed { margin-left: 0; }
}
</style>
