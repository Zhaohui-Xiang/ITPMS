<script setup>
import Sidebar from './Sidebar.vue'
import HeaderBar from './HeaderBar.vue'
import BreadcrumbNav from './BreadcrumbNav.vue'
import { useSidebarStore } from '@/stores/sidebar'

const sidebarStore = useSidebarStore()
</script>

<template>
  <div class="app-layout">
    <!-- 侧边栏 -->
    <Sidebar />

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
        <div class="content-main">
          <router-view />
        </div>
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
  min-width: 1024px;
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
</style>
