<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSidebarStore } from '@/stores/sidebar'
import { usePermission } from '@/composables/usePermission'
import BrandMark from './BrandMark.vue'

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
    <div class="sidebar-logo"><BrandMark :compact="sidebarStore.collapsed" /></div>

    <!-- 菜单 -->
    <el-menu
      :default-active="activeMenu"
      :collapse="sidebarStore.collapsed"
      :collapse-transition="false"
      background-color="#ffffff"
      text-color="#667482"
      active-text-color="#00467f"
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
    <el-tooltip :content="sidebarStore.collapsed ? '展开导航' : '收起导航'" placement="right">
      <button
        type="button"
        class="sidebar-collapse-btn"
        :aria-label="sidebarStore.collapsed ? '展开导航' : '收起导航'"
        @click="sidebarStore.toggleCollapse()"
      >
        <el-icon :size="18">
          <component :is="sidebarStore.collapsed ? 'Expand' : 'Fold'" />
        </el-icon>
      </button>
    </el-tooltip>
  </div>
</template>

<style scoped lang="scss">
.sidebar {
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  width: $sidebar-width;
  background-color: #fff;
  border-right: 1px solid $color-border;
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
  border-bottom: 1px solid $color-border;
  flex-shrink: 0;

  .collapsed & {
    padding: 0;
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

    &:hover {
      background: $color-primary-soft;
    }

    &.is-active {
      border-left-color: $color-primary;
      background: $color-primary-soft;
    }
  }
}

.sidebar-collapse-btn {
  width: 100%;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 0;
  color: $color-muted;
  background: #fff;
  cursor: pointer;
  border-top: 1px solid $color-border;
  flex-shrink: 0;
  transition: color 0.2s, background-color 0.2s;

  &:hover {
    color: $color-primary;
    background-color: $color-primary-soft;
  }
}

:deep(.el-menu--collapse) {
  width: 100%;
}

:deep(.el-menu-item) {
  height: 44px;
  line-height: 44px;
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
@media (max-width: 767px) {
  .sidebar { transition: transform 0.2s ease; }
  .sidebar.collapsed { transform: translateX(-100%); }
}
</style>
