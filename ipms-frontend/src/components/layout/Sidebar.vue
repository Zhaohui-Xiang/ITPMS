<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSidebarStore } from '@/stores/sidebar'
import { usePermission } from '@/composables/usePermission'

const route = useRoute()
const router = useRouter()
const sidebarStore = useSidebarStore()
const { visibleMenuItems } = usePermission()

const activeMenu = computed(() => {
  const { path } = route
  // 匹配当前路径对应的菜单
  if (path.startsWith('/projects')) return '/projects'
  if (path.startsWith('/requirements')) return '/requirements'
  if (path.startsWith('/tasks')) return '/tasks'
  if (path.startsWith('/defects')) return '/defects'
  if (path.startsWith('/documents')) return '/documents'
  if (path.startsWith('/organizations')) return '/organizations'
  if (path.startsWith('/audit-logs')) return '/audit-logs'
  if (path.startsWith('/settings')) return '/settings'
  return path
})

function handleSelect(index) {
  sidebarStore.setActiveMenu(index)
  router.push(index)
}
</script>

<template>
  <div
    class="sidebar"
    :class="{ collapsed: sidebarStore.collapsed }"
  >
    <!-- Logo 区域 -->
    <div class="sidebar-logo">
      <div class="logo-icon">
        <el-icon :size="24"><component :is="'Platform'" /></el-icon>
      </div>
      <transition name="fade">
        <span v-show="!sidebarStore.collapsed" class="logo-text">IPMS</span>
      </transition>
    </div>

    <!-- 菜单 -->
    <el-menu
      :default-active="activeMenu"
      :collapse="sidebarStore.collapsed"
      :collapse-transition="false"
      background-color="#304156"
      text-color="#bfcbd9"
      active-text-color="#409EFF"
      router
      class="sidebar-menu"
      @select="handleSelect"
    >
      <el-menu-item
        v-for="item in visibleMenuItems"
        :key="item.index"
        :index="item.index"
      >
        <el-icon><component :is="item.icon" /></el-icon>
        <template #title>{{ item.title }}</template>
      </el-menu-item>
    </el-menu>

    <!-- 折叠按钮 -->
    <div class="sidebar-collapse-btn" @click="sidebarStore.toggleCollapse()">
      <el-icon :size="18">
        <component :is="sidebarStore.collapsed ? 'Expand' : 'Fold'" />
      </el-icon>
    </div>
  </div>
</template>

<style scoped lang="scss">
.sidebar {
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  width: $sidebar-width;
  background-color: #304156;
  display: flex;
  flex-direction: column;
  transition: width 0.3s ease;
  z-index: 1001;
  overflow: hidden;

  &.collapsed {
    width: $sidebar-collapsed-width;
  }
}

.sidebar-logo {
  height: $header-height;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  flex-shrink: 0;

  .logo-icon {
    width: 32px;
    height: 32px;
    background: $color-primary;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
  }

  .logo-text {
    font-size: 18px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    letter-spacing: 2px;
  }
}

.sidebar-menu {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  border-right: none;

  &:not(.el-menu--collapse) {
    width: 100%;
  }

  // 菜单项左侧指示条
  .el-menu-item {
    border-left: 3px solid transparent;

    &.is-active {
      border-left-color: $color-primary;
    }
  }
}

.sidebar-collapse-btn {
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #bfcbd9;
  cursor: pointer;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  flex-shrink: 0;
  transition: color 0.2s;

  &:hover {
    color: #fff;
    background-color: rgba(255, 255, 255, 0.05);
  }
}

// 过渡动画
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
