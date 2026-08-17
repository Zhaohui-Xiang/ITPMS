<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import StatusTag from '@/components/common/StatusTag.vue'
import { getProject } from '@/api/project'
import { listRequirements } from '@/api/requirement'
import { listDefects } from '@/api/defect'

const route = useRoute()
const router = useRouter()

// ===================================================================
// Project ID from route
// ===================================================================
const projectId = computed(() => route.params.id)

// ===================================================================
// Loading states
// ===================================================================
const projectLoading = ref(false)
const reqLoading = ref(false)
const defectLoading = ref(false)

// ===================================================================
// Active requirement status filter (toggle via stat cards)
// ===================================================================
const activeReqFilter = ref('')

// ===================================================================
// Mock project detail
// ===================================================================
const project = ref({
  id: projectId.value,
  name: 'SAP B1',
  system_type: '外部采购',
  status: '活跃',
  manager: '张三',
  supplier: 'SAP 实施团队',
  description: 'SAP B1 ERP 系统的实施与运维管理，涵盖财务、采购、库存等核心模块。',
  created_at: '2026-06-01'
})

// ===================================================================
// Mock requirement statistics (7 statuses)
// ===================================================================
const reqStats = ref([
  { status: 'pending_review', label: '待审核', count: 3 },
  { status: 'assigned',      label: '已分配', count: 2 },
  { status: 'developing',    label: '开发中', count: 5 },
  { status: 'testing',       label: '测试中', count: 2 },
  { status: 'pending_online',label: '待上线', count: 1 },
  { status: 'online',        label: '已上线', count: 8 },
  { status: 'accepted',      label: '已验收', count: 14 }
])

// ===================================================================
// Mock recent requirements (at least 5)
// ===================================================================
const recentRequirements = ref([
  {
    id: 1,
    title: '新增财务报表功能',
    status: 'developing',
    priority: '紧急',
    assignee: '王小明',
    due_date: '2026-08-15',
    updated_at: '2026-08-02'
  },
  {
    id: 2,
    title: '采购订单审批流优化',
    status: 'pending_review',
    priority: '高',
    assignee: null,
    due_date: '2026-08-20',
    updated_at: '2026-08-01'
  },
  {
    id: 3,
    title: '库存盘点接口对接',
    status: 'online',
    priority: '高',
    assignee: '李大力',
    due_date: '2026-07-30',
    updated_at: '2026-07-28'
  },
  {
    id: 4,
    title: '供应商主数据管理页面',
    status: 'testing',
    priority: '中',
    assignee: '赵晓峰',
    due_date: '2026-08-10',
    updated_at: '2026-08-02'
  },
  {
    id: 5,
    title: '数据导出权限控制',
    status: 'assigned',
    priority: '中',
    assignee: '陈建国',
    due_date: '2026-08-25',
    updated_at: '2026-07-30'
  },
  {
    id: 6,
    title: '登录 SSO 集成',
    status: 'pending_online',
    priority: '高',
    assignee: '王小明',
    due_date: '2026-08-08',
    updated_at: '2026-08-03'
  },
  {
    id: 7,
    title: '移动端适配优化',
    status: 'accepted',
    priority: '低',
    assignee: '李大力',
    due_date: '2026-07-15',
    updated_at: '2026-07-20'
  },
  {
    id: 8,
    title: '审计日志查询界面',
    status: 'developing',
    priority: '中',
    assignee: '赵晓峰',
    due_date: '2026-08-18',
    updated_at: '2026-08-01'
  }
])

// ===================================================================
// Mock recent defects (at least 3)
// ===================================================================
const recentDefects = ref([
  {
    id: 1,
    title: '登录后页面间歇性空白',
    severity: '致命',
    status: 'fixing',
    found_at: '2026-08-02'
  },
  {
    id: 2,
    title: '报表导出 Excel 格式错乱',
    severity: '一般',
    status: 'confirmed',
    found_at: '2026-08-01'
  },
  {
    id: 3,
    title: '库存数据同步延迟超过 5 分钟',
    severity: '严重',
    status: 'pending',
    found_at: '2026-08-03'
  },
  {
    id: 4,
    title: '用户头像上传后显示异常',
    severity: '轻微',
    status: 'closed',
    found_at: '2026-07-28'
  },
  {
    id: 5,
    title: '审批流程节点跳转卡顿',
    severity: '一般',
    status: 'retesting',
    found_at: '2026-07-30'
  }
])

// ===================================================================
// Computed
// ===================================================================

/** Filter requirements by selected status from stat card click */
const filteredRequirements = computed(() => {
  if (!activeReqFilter.value) return recentRequirements.value
  return recentRequirements.value.filter(r => r.status === activeReqFilter.value)
})

// ===================================================================
// Tag type helpers
// ===================================================================

function getPriorityTagType(priority) {
  const map = { '紧急': 'danger', '高': 'warning', '中': 'primary', '低': 'info' }
  return map[priority] || 'info'
}

function getSeverityTagType(severity) {
  const map = { '致命': 'danger', '严重': 'warning', '一般': '', '轻微': 'info' }
  return map[severity] || ''
}

function getSystemTypeTagType(type) {
  const map = { '内部自研': 'success', '外部采购': 'primary' }
  return map[type] || 'info'
}

function getProjectStatusTagType(status) {
  const map = { '活跃': 'success', '维护中': 'warning', '归档': 'info' }
  return map[status] || 'info'
}

// ===================================================================
// Route / navigation helpers
// ===================================================================

function filterByStatus(statusCode) {
  activeReqFilter.value = activeReqFilter.value === statusCode ? '' : statusCode
}

function goToRequirement(id) {
  router.push(`/requirements/${id}`)
}

function goToRequirementList() {
  router.push('/requirements')
}

function goToDefect(id) {
  router.push(`/defects/${id}`)
}

function goToDefectList() {
  router.push('/defects')
}

function handleEditProject() {
  router.push(`/projects/${projectId.value}/edit`)
}

// ===================================================================
// Data fetching (placeholders — use mock data for now)
// ===================================================================

async function fetchProjectDetail() {
  projectLoading.value = true
  try {
    // const res = await getProject(projectId.value)
    // project.value = res.data
  } catch (error) {
    ElMessage.error('获取项目详情失败')
  } finally {
    projectLoading.value = false
  }
}

async function fetchRequirements() {
  reqLoading.value = true
  try {
    // const res = await listRequirements({ project_id: projectId.value, pageSize: 10 })
    // recentRequirements.value = res.data
  } catch (error) {
    ElMessage.error('获取需求列表失败')
  } finally {
    reqLoading.value = false
  }
}

async function fetchDefects() {
  defectLoading.value = true
  try {
    // const res = await listDefects({ project_id: projectId.value, pageSize: 10 })
    // recentDefects.value = res.data
  } catch (error) {
    ElMessage.error('获取缺陷列表失败')
  } finally {
    defectLoading.value = false
  }
}

onMounted(() => {
  // Uncomment when backend is ready
  // fetchProjectDetail()
  // fetchRequirements()
  // fetchDefects()
})
</script>

<template>
  <div class="page-container">

    <!-- ================================================================ -->
    <!-- Page header -->
    <!-- ================================================================ -->
    <div class="page-header flex-between">
      <div>
        <h2 class="page-title">项目详情 - {{ project.name }}</h2>
        <p class="page-description">查看项目信息、需求概况与近期缺陷</p>
      </div>
      <el-button type="primary" :icon="Edit" @click="handleEditProject">
        编辑项目
      </el-button>
    </div>

    <!-- ================================================================ -->
    <!-- Project info -->
    <!-- ================================================================ -->
    <el-card v-loading="projectLoading" class="content-card mb-16">
      <template #header>
        <span class="card-title">项目信息</span>
      </template>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="项目名称">
          {{ project.name }}
        </el-descriptions-item>
        <el-descriptions-item label="系统类型">
          <el-tag :type="getSystemTypeTagType(project.system_type)" size="small">
            {{ project.system_type }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="getProjectStatusTagType(project.status)" size="small">
            {{ project.status }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="负责人">
          {{ project.manager }}
        </el-descriptions-item>
        <el-descriptions-item label="供应商">
          {{ project.supplier }}
        </el-descriptions-item>
        <el-descriptions-item label="创建时间">
          {{ project.created_at }}
        </el-descriptions-item>
        <el-descriptions-item label="描述" :span="2">
          {{ project.description }}
        </el-descriptions-item>
      </el-descriptions>
    </el-card>

    <!-- ================================================================ -->
    <!-- Requirement statistics -->
    <!-- ================================================================ -->
    <el-card class="content-card mb-16">
      <template #header>
        <span class="card-title">需求统计</span>
      </template>
      <div class="stat-cards">
        <div
          v-for="stat in reqStats"
          :key="stat.status"
          class="stat-card"
          :class="{ 'stat-card--active': activeReqFilter === stat.status }"
          @click="filterByStatus(stat.status)"
        >
          <div class="stat-count">{{ stat.count }}</div>
          <div class="stat-label">{{ stat.label }}</div>
        </div>
      </div>
    </el-card>

    <!-- ================================================================ -->
    <!-- Requirements -->
    <!-- ================================================================ -->
    <el-card class="content-card mb-16">
      <template #header>
        <div class="card-header">
          <span class="card-title">需求列表</span>
          <el-button text type="primary" @click="goToRequirementList">
            查看全部 &rarr;
          </el-button>
        </div>
      </template>

      <!-- Status filter tabs -->
      <div class="filter-tabs">
        <button
          class="filter-tab"
          :class="{ 'filter-tab--active': activeReqFilter === '' }"
          @click="activeReqFilter = ''"
        >
          全部
        </button>
        <button
          v-for="stat in reqStats"
          :key="stat.status"
          class="filter-tab"
          :class="{ 'filter-tab--active': activeReqFilter === stat.status }"
          @click="filterByStatus(stat.status)"
        >
          {{ stat.label }}
          <span v-if="stat.count > 0" class="filter-tab-badge">{{ stat.count }}</span>
        </button>
      </div>

      <!-- Requirement table -->
      <el-table
        :data="filteredRequirements"
        v-loading="reqLoading"
        size="medium"
        empty-text="暂无需求数据"
      >
        <el-table-column label="标题" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <el-link type="primary" :underline="false" @click="goToRequirement(row.id)">
              {{ row.title }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="优先级" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="getPriorityTagType(row.priority)" size="small">
              {{ row.priority }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag type="requirement" :status="row.status" />
          </template>
        </el-table-column>
        <el-table-column label="负责人" width="100" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.assignee">{{ row.assignee }}</span>
            <span v-else class="text-placeholder">--</span>
          </template>
        </el-table-column>
        <el-table-column label="截止日期" width="120" align="center">
          <template #default="{ row }">
            {{ row.due_date || '--' }}
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- ================================================================ -->
    <!-- Recent defects -->
    <!-- ================================================================ -->
    <el-card class="content-card mb-16">
      <template #header>
        <div class="card-header">
          <span class="card-title">近期缺陷</span>
          <el-button text type="primary" @click="goToDefectList">
            查看全部 &rarr;
          </el-button>
        </div>
      </template>

      <el-table
        :data="recentDefects"
        v-loading="defectLoading"
        size="medium"
        empty-text="暂无缺陷数据"
      >
        <el-table-column label="标题" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <el-link type="primary" :underline="false" @click="goToDefect(row.id)">
              {{ row.title }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="严重程度" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="getSeverityTagType(row.severity)" size="small">
              {{ row.severity }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag type="defect" :status="row.status" />
          </template>
        </el-table-column>
        <el-table-column label="发现日期" width="120" align="center">
          <template #default="{ row }">
            {{ row.found_at }}
          </template>
        </el-table-column>
      </el-table>
    </el-card>

  </div>
</template>

<style scoped lang="scss">
// ======================================================================
// Card header
// ======================================================================
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-size: $font-size-h3;
  font-weight: 600;
  color: $gray-900;
}

// ======================================================================
// Stat cards
// ======================================================================
.stat-cards {
  display: flex;
  gap: 12px;
}

.stat-card {
  flex: 1;
  text-align: center;
  padding: 16px 8px;
  background: $gray-50;
  border-radius: $border-radius-md;
  cursor: pointer;
  transition: background-color 0.2s, box-shadow 0.2s, transform 0.15s;
  border: 2px solid transparent;

  &:hover {
    background: #ecf5ff;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(64, 158, 255, 0.15);
  }

  &--active {
    background: #ecf5ff;
    border-color: $color-primary;
  }

  .stat-count {
    font-size: 28px;
    font-weight: 700;
    color: $color-primary;
    line-height: 1.2;
  }

  .stat-label {
    font-size: $font-size-caption;
    color: $gray-500;
    margin-top: 6px;
  }
}

// ======================================================================
// Filter tabs
// ======================================================================
.filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}

.filter-tab {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 14px;
  font-size: $font-size-small;
  color: $gray-700;
  background: $gray-50;
  border: 1px solid $gray-200;
  border-radius: $border-radius-round;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
  line-height: 1.4;

  &:hover {
    color: $color-primary;
    border-color: $color-primary-light;
    background: #ecf5ff;
  }

  &--active {
    color: #fff;
    background: $color-primary;
    border-color: $color-primary;

    &:hover {
      color: #fff;
      background: $color-primary-dark;
      border-color: $color-primary-dark;
    }
  }
}

.filter-tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
  color: inherit;
  background: rgba(255, 255, 255, 0.25);
  border-radius: 9px;

  .filter-tab:not(.filter-tab--active) & {
    color: $color-primary;
    background: rgba(64, 158, 255, 0.1);
  }
}

// ======================================================================
// Empty / placeholder text
// ======================================================================
.text-placeholder {
  color: $gray-300;
}

// ======================================================================
// Responsive: stack stat cards on narrow screens
// ======================================================================
@media (max-width: 900px) {
  .stat-cards {
    flex-wrap: wrap;
  }

  .stat-card {
    flex: 0 0 calc(25% - 9px);
    min-width: 80px;
  }
}

@media (max-width: 600px) {
  .stat-card {
    flex: 0 0 calc(33.333% - 8px);
  }
}
</style>
