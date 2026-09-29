<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Bell } from '@element-plus/icons-vue'
import {
  getUnreadCount,
  listInbox,
  markAllNotificationsRead,
  markNotificationRead,
} from '@/api/notification'

const router = useRouter()
const unread = ref(0)
const items = ref([])
const loading = ref(false)
let timer = null

async function refreshUnread() {
  try {
    const response = await getUnreadCount()
    unread.value = response?.data?.data?.unread_count ?? 0
  } catch {
    // 未读数失败静默（不打断导航）
  }
}

async function loadInbox() {
  loading.value = true
  try {
    const response = await listInbox({ page: 1, page_size: 10 })
    items.value = response?.data?.data?.items ?? []
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '通知加载失败')
  } finally {
    loading.value = false
  }
}

async function openNotification(item) {
  try {
    await markNotificationRead(item.id)
    unread.value = Math.max(0, unread.value - 1)
  } catch {
    // 已读失败不阻断跳转
  }
  if (item.target_url) {
    router.push(item.target_url)
  }
}

async function readAll() {
  try {
    await markAllNotificationsRead()
    unread.value = 0
    items.value = items.value.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() }))
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '操作失败')
  }
}

function formatTime(value) {
  return value ? String(value).slice(5, 16).replace('T', ' ') : ''
}

onMounted(() => {
  refreshUnread()
  timer = setInterval(refreshUnread, 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <el-dropdown trigger="click" @visible-change="(visible) => visible && loadInbox()">
    <el-badge
      :value="unread"
      :hidden="unread === 0"
      :max="99"
      class="notification-badge"
      data-testid="notification-bell"
    >
      <el-button text class="header-icon-btn" aria-label="站内通知">
        <el-icon :size="20"><Bell /></el-icon>
      </el-button>
    </el-badge>

    <template #dropdown>
      <div class="inbox-panel">
        <header class="inbox-header">
          <strong>站内通知</strong>
          <el-button text size="small" :disabled="unread === 0" data-testid="read-all" @click="readAll">
            全部已读
          </el-button>
        </header>
        <div v-if="loading" class="inbox-empty">加载中…</div>
        <div v-else-if="items.length === 0" class="inbox-empty">暂无通知</div>
        <ul v-else class="inbox-list">
          <li
            v-for="item in items"
            :key="item.id"
            class="inbox-item"
            :class="{ 'is-unread': !item.read_at }"
            :data-testid="`notification-${item.id}`"
          >
            <button type="button" class="inbox-link" @click="openNotification(item)">
              <strong>{{ item.title }}</strong>
              <span class="inbox-body">{{ item.body }}</span>
              <span class="inbox-time">{{ formatTime(item.created_at) }}</span>
            </button>
          </li>
        </ul>
      </div>
    </template>
  </el-dropdown>
</template>

<style scoped lang="scss">
.notification-badge {
  display: flex;
  align-items: center;
}

.inbox-panel {
  width: 340px;
  max-height: 420px;
  display: flex;
  flex-direction: column;
}

.inbox-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 1px solid $color-border;
}

.inbox-empty {
  padding: 32px 16px;
  color: $color-muted;
  text-align: center;
  font-size: $font-size-small;
}

.inbox-list {
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.inbox-item {
  border-bottom: 1px solid $color-border;

  &:last-child {
    border-bottom: 0;
  }
}

.inbox-link {
  display: flex;
  width: 100%;
  flex-direction: column;
  gap: 3px;
  padding: 10px 14px;
  border: 0;
  background: none;
  cursor: pointer;
  font: inherit;
  text-align: left;

  strong {
    color: $color-ink;
    font-weight: 600;
  }

  &:hover {
    background: $color-primary-soft;
  }

  .is-unread & strong {
    color: $color-primary;
  }
}

.inbox-body {
  overflow: hidden;
  color: $color-muted;
  font-size: $font-size-caption;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.inbox-time {
  color: $color-muted;
  font-size: $font-size-caption;
}
</style>
