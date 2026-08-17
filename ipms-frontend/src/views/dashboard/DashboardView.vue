<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import StatusTag from '@/components/common/StatusTag.vue'

const authStore = useAuthStore()

const welcomeText = computed(() => {
  const hour = new Date().getHours()
  if (hour < 6) return '夜深了'
  if (hour < 9) return '早上好'
  if (hour < 12) return '上午好'
  if (hour < 14) return '中午好'
  if (hour < 18) return '下午好'
  return '晚上好'
})

// Mock 统计数据
const statsCards = ref([
  { title: '我的需求', value: 12, color: '#409EFF', icon: 'Document' },
  { title: '待处理任务', value: 5, color: '#E6A23C', icon: 'List' },
  { title: '待确认缺陷', value: 2, color: '#F56C6C', icon: 'Warning' },
  { title: '即将到期', value: 3, color: '#FF6D00', icon: 'Clock' }
])

// Mock 近期需求
const recentRequirements = ref([
  { id: 1, title: '新增财务报表功能', project: 'SAP B1', status: 'developing', priority: '高', date: '2026-08-02' },
  { id: 2, title: '采购订单审批流优化', project: 'Weaver OA', status: 'pending_review', priority: '中', date: '2026-08-01' },
  { id: 3, title: '订单同步接口对接', project: 'VPMS', status: 'assigned', priority: '紧急', date: '2026-07-30' },
  { id: 4, title: 'CRM客户标签管理', project: 'Salesforce', status: 'online', priority: '低', date: '2026-07-28' },
  { id: 5, title: '库存盘点功能开发', project: 'SAP B1', status: 'testing', priority: '高', date: '2026-07-25' }
])

// Mock 近期任务
const recentTasks = ref([
  { id: 1, title: '财务报表模板开发', project: 'SAP B1', status: 'in_progress', priority: '高', dueDate: '2026-08-05' },
  { id: 2, title: '审批流后端开发', project: 'Weaver OA', status: 'in_progress', priority: '中', dueDate: '2026-08-10' },
  { id: 3, title: '接口文档编写', project: 'VPMS', status: 'done', priority: '低', dueDate: '2026-07-28' },
  { id: 4, title: '数据查询接口', project: 'SAP B1', status: 'todo', priority: '高', dueDate: '2026-08-12' },
  { id: 5, title: '报表导出功能', project: 'SAP B1', status: 'todo', priority: '中', dueDate: '2026-08-15' }
])

// 优先级标签类型
function getPriorityTag(priority) {
  const map = { '紧急': 'danger', '高': 'warning', '中': '', '低': 'info' }
  return map[priority] || 'info'
}
</script>

<template>
  <div class="page-container">
    <!-- 欢迎区 -->
    <div class="welcome-section">
      <div class="welcome-text">
        <h2 class="page-title">{{ welcomeText }}，{{ authStore.userName || '用户' }}</h2>
        <p class="page-description">
          当前角色：<el-tag size="small" type="primary">{{ authStore.currentRole === 'super_admin' ? '超级管理员' : authStore.currentRole }}</el-tag>
          &nbsp;|&nbsp; 用户类型：<el-tag size="small">{{ authStore.userType || '内部 IT' }}</el-tag>
        </p>
      </div>
    </div>

    <!-- 统计卡片 -->
    <el-row :gutter="16" class="stats-row">
      <el-col :span="6" v-for="card in statsCards" :key="card.title">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-content">
            <div class="stat-card-info">
              <div class="stat-card-value">{{ card.value }}</div>
              <div class="stat-card-title">{{ card.title }}</div>
            </div>
            <div
              class="stat-card-icon"
              :style="{ backgroundColor: card.color + '1a', color: card.color }"
            >
              <el-icon :size="28"><component :is="card.icon" /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 近期数据表格 -->
    <el-row :gutter="16" class="tables-row">
      <!-- 近期需求 -->
      <el-col :span="12">
        <el-card shadow="hover" class="content-card">
          <template #header>
            <div class="card-header">
              <span>近期需求</span>
              <el-button text type="primary" @click="$router.push('/requirements')">查看全部</el-button>
            </div>
          </template>
          <el-table :data="recentRequirements" size="small" stripe>
            <el-table-column prop="title" label="标题" min-width="160" show-overflow-tooltip />
            <el-table-column prop="project" label="项目" width="100" />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <StatusTag type="requirement" :status="row.status" />
              </template>
            </el-table-column>
            <el-table-column label="优先级" width="70">
              <template #default="{ row }">
                <el-tag :type="getPriorityTag(row.priority)" size="small">{{ row.priority }}</el-tag>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

      <!-- 近期任务 -->
      <el-col :span="12">
        <el-card shadow="hover" class="content-card">
          <template #header>
            <div class="card-header">
              <span>近期任务</span>
              <el-button text type="primary" @click="$router.push('/tasks')">查看全部</el-button>
            </div>
          </template>
          <el-table :data="recentTasks" size="small" stripe>
            <el-table-column prop="title" label="任务" min-width="150" show-overflow-tooltip />
            <el-table-column prop="project" label="项目" width="90" />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <StatusTag type="task" :status="row.status" />
              </template>
            </el-table-column>
            <el-table-column prop="dueDate" label="截止" width="100" />
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped lang="scss">
.welcome-section {
  margin-bottom: 24px;
}

.stats-row {
  margin-bottom: 16px;
}

.stat-card {
  .stat-card-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .stat-card-info {
    .stat-card-value {
      font-size: 28px;
      font-weight: 700;
      color: $gray-900;
      line-height: 1.2;
    }

    .stat-card-title {
      font-size: $font-size-small;
      color: $gray-500;
      margin-top: 4px;
    }
  }

  .stat-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.tables-row {
  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: $font-size-h3;
    font-weight: 500;
  }
}
</style>
