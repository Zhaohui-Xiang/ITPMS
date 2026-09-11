<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSidebarStore } from '@/stores/sidebar'
import GlobalSearch from './GlobalSearch.vue'
import BrandMark from './BrandMark.vue'
import ProfileDialog from '@/components/common/ProfileDialog.vue'

const authStore = useAuthStore()
const sidebarStore = useSidebarStore()
const profileDialogVisible = ref(false)

function handleLogout() {
  authStore.logout()
}

function handleCommand(command) {
  if (command === 'profile') {
    profileDialogVisible.value = true
  }
  if (command === 'logout') {
    handleLogout()
  }
}

function handleDialogVisibility(value) {
  if (!authStore.mustChangePassword) {
    profileDialogVisible.value = value
  }
}
</script>

<template>
  <div class="header-bar">
    <div class="header-left">
      <el-tooltip :content="sidebarStore.collapsed ? '展开导航' : '收起导航'">
        <el-button
          text
          class="collapse-btn"
          :aria-label="sidebarStore.collapsed ? '展开导航' : '收起导航'"
          @click="sidebarStore.toggleCollapse()"
        >
          <el-icon :size="20">
            <component :is="sidebarStore.collapsed ? 'Expand' : 'Fold'" />
          </el-icon>
        </el-button>
      </el-tooltip>
      <BrandMark compact class="mobile-brand" />
      <GlobalSearch />
    </div>

    <div class="header-right">
      <el-tooltip content="站内通知尚未上线">
        <el-button text disabled class="header-icon-btn" aria-label="站内通知尚未上线">
          <el-icon :size="20"><Bell /></el-icon>
        </el-button>
      </el-tooltip>

      <el-dropdown trigger="click" @command="handleCommand">
        <div class="user-info">
          <el-avatar :size="32" icon="UserFilled" />
          <span class="user-copy">
            <span class="user-name">{{ authStore.userName || '未登录' }}</span>
            <span class="user-role">{{ authStore.currentRole }}</span>
          </span>
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

    <ProfileDialog
      :model-value="profileDialogVisible || authStore.mustChangePassword"
      :force-password-change="authStore.mustChangePassword"
      @update:model-value="handleDialogVisibility"
    />
  </div>
</template>

<style scoped lang="scss">
.header-bar {
  position: sticky;
  top: 0;
  width: 100%;
  height: $header-height;
  background: #fff;
  border-bottom: 1px solid $color-border;
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  z-index: 1000;
  gap: 20px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.collapse-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
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
    background-color: $color-primary-soft;
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
    background-color: $color-primary-soft;
  }

  .user-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
  }

  .user-name {
    color: $color-ink;
    font-size: $font-size-small;
    font-weight: 600;
    max-width: 100px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .user-role {
    color: $color-muted;
    font-size: 11px;
    line-height: 14px;
  }

  .dropdown-icon {
    font-size: $font-size-caption;
    color: $gray-500;
  }
}
.mobile-brand { display: none; }
@media (max-width: 767px) {
  .header-bar { padding: 0 8px; gap: 8px; }
  .header-left { min-width: 0; flex: 1; gap: 4px; }
  .header-right { flex: 0 0 auto; }
  .user-info { padding: 4px; }
  .user-info .user-copy, .user-info .dropdown-icon { display: none; }
  .mobile-brand { display: flex; flex: 0 0 24px; }
}
</style>
