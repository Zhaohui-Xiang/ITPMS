<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSidebarStore } from '@/stores/sidebar'

const router = useRouter()
const authStore = useAuthStore()
const sidebarStore = useSidebarStore()

function handleLogout() {
  authStore.logout()
}

function handleProfile() {
  router.push('/profile')
}
</script>

<template>
  <div class="header-bar">
    <div class="header-left">
      <!-- 折叠按钮 -->
      <div class="collapse-btn" @click="sidebarStore.toggleCollapse()">
        <el-icon :size="20">
          <component :is="sidebarStore.collapsed ? 'Expand' : 'Fold'" />
        </el-icon>
      </div>
    </div>

    <div class="header-right">
      <!-- 通知铃铛 -->
      <el-badge :value="3" :max="99" class="notification-badge">
        <el-button link class="header-icon-btn">
          <el-icon :size="20"><Bell /></el-icon>
        </el-button>
      </el-badge>

      <!-- 用户下拉 -->
      <el-dropdown trigger="click" @command="(cmd) => { if (cmd === 'logout') handleLogout(); if (cmd === 'profile') handleProfile(); }">
        <div class="user-info">
          <el-avatar :size="32" icon="UserFilled" />
          <span class="user-name">{{ authStore.userName || '未登录' }}</span>
          <el-icon class="dropdown-icon"><ArrowDown /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="profile">
              <el-icon><User /></el-icon>
              个人信息
            </el-dropdown-item>
            <el-dropdown-item command="logout" divided>
              <el-icon><SwitchButton /></el-icon>
              退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </div>
</template>

<style scoped lang="scss">
.header-bar {
  position: fixed;
  top: 0;
  right: 0;
  left: $sidebar-width;
  height: $header-height;
  background: #fff;
  border-bottom: 1px solid $gray-200;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  z-index: 1000;
  transition: left 0.3s ease;

  .collapsed & {
    left: $sidebar-collapsed-width;
  }
}

.header-left {
  display: flex;
  align-items: center;
}

.collapse-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  cursor: pointer;
  color: $gray-700;
  transition: all 0.2s;

  &:hover {
    background-color: $gray-100;
    color: $color-primary;
  }
}

.header-right {
  display: flex;
  align-items: center;
  gap: 4px;
}

.notification-badge {
  margin-right: 8px;
}

.header-icon-btn {
  width: 36px;
  height: 36px;
  color: $gray-700;
  border-radius: 4px;

  &:hover {
    background-color: $gray-100;
    color: $color-primary;
  }
}

.user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border-radius: 6px;
  cursor: pointer;
  transition: background-color 0.2s;

  &:hover {
    background-color: $gray-100;
  }

  .user-name {
    font-size: $font-size-body;
    color: $gray-700;
    max-width: 100px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .dropdown-icon {
    font-size: $font-size-caption;
    color: $gray-500;
  }
}
</style>
