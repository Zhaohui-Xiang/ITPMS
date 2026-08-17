<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import StatusTag from '@/components/common/StatusTag.vue'
import UserSelector from '@/components/common/UserSelector.vue'
import { listTasks, createTask, claimTask, transitionTask, holdTask } from '@/api/task'

// ================================================================
// State
// ================================================================
const loading = ref(false)
const activeStatus = ref('')
const filterProject = ref('')
const filterPriority = ref('')
const filterAssignee = ref(null)
const dialogVisible = ref(false)
const submitting = ref(false)

const tasks = ref([])

const queryParams = reactive({
  page: 1,
  pageSize: 10,
  total: 0
})

// ================================================================
// Mock data
// ================================================================
const mockProjects = ['SAP B1', 'VPMS', 'Weaver OA', 'Salesforce', 'Tools']

const mockRequirements = [
  { id: 1, title: '新增财务报表功能' },
  { id: 2, title: '审批流优化' },
  { id: 3, title: '库存盘点功能' },
  { id: 4, title: '登录SSO集成' },
  { id: 5, title: 'CRM客户标签管理' },
  { id: 6, title: '数据导出功能' },
  { id: 7, title: '消息推送服务' }
]

const mockUsers = [
  { id: 1, name: '张三' },
  { id: 2, name: '李四' },
  { id: 3, name: '王五' },
  { id: 4, name: '赵六' },
  { id: 5, name: '孙七' },
  { id: 6, name: '周八' }
]

const mockTasks = [
  {
    id: 1, title: '财务报表模板开发', description: '开发SAP B1财务报表模板，支持导出Excel和PDF',
    requirement: { id: 1, title: '新增财务报表功能' }, project: 'SAP B1',
    assignee: { id: 2, name: '李四' }, priority: '高', status: 'in_progress',
    due_date: '2026-08-05', created_at: '2026-07-20'
  },
  {
    id: 2, title: '采购订单审批流后端开发', description: '实现OA系统采购订单的审批流引擎',
    requirement: { id: 2, title: '审批流优化' }, project: 'Weaver OA',
    assignee: { id: 3, name: '王五' }, priority: '中', status: 'in_progress',
    due_date: '2026-08-10', created_at: '2026-07-22'
  },
  {
    id: 3, title: '库存盘点接口对接', description: '与WMS系统对接库存盘点API',
    requirement: { id: 3, title: '库存盘点功能' }, project: 'SAP B1',
    assignee: { id: 4, name: '赵六' }, priority: '低', status: 'done',
    due_date: '2026-07-28', created_at: '2026-07-10'
  },
  {
    id: 4, title: '数据查询API开发', description: '实现财务报表数据的多维查询API',
    requirement: { id: 1, title: '新增财务报表功能' }, project: 'SAP B1',
    assignee: { id: 3, name: '王五' }, priority: '高', status: 'todo',
    due_date: '2026-08-12', created_at: '2026-07-25'
  },
  {
    id: 5, title: '报表导出功能实现', description: '实现报表数据导出为Excel和CSV格式',
    requirement: { id: 1, title: '新增财务报表功能' }, project: 'SAP B1',
    assignee: { id: 1, name: '张三' }, priority: '中', status: 'todo',
    due_date: '2026-08-15', created_at: '2026-07-26'
  },
  {
    id: 6, title: 'SSO登录集成', description: '将公司SSO系统与OA进行集成',
    requirement: { id: 4, title: '登录SSO集成' }, project: 'Tools',
    assignee: { id: 2, name: '李四' }, priority: '高', status: 'in_progress',
    due_date: '2026-08-08', created_at: '2026-07-18'
  },
  {
    id: 7, title: '客户标签CRUD接口', description: '实现CRM系统中客户标签的增删改查',
    requirement: { id: 5, title: 'CRM客户标签管理' }, project: 'Salesforce',
    assignee: { id: 3, name: '王五' }, priority: '低', status: 'done',
    due_date: '2026-07-25', created_at: '2026-07-05'
  },
  {
    id: 8, title: '消息推送服务端开发', description: '基于WebSocket实现实时消息推送',
    requirement: { id: 7, title: '消息推送服务' }, project: 'Tools',
    assignee: { id: 4, name: '赵六' }, priority: '紧急', status: 'in_progress',
    due_date: '2026-08-03', created_at: '2026-07-28'
  },
  {
    id: 9, title: '数据导出历史记录页面', description: '开发导出历史记录的查询和管理页面',
    requirement: { id: 6, title: '数据导出功能' }, project: 'VPMS',
    assignee: { id: 5, name: '孙七' }, priority: '中', status: 'suspended',
    due_date: '2026-08-20', created_at: '2026-07-15'
  },
  {
    id: 10, title: '审批流节点可视化配置', description: '拖拽式审批节点配置界面',
    requirement: { id: 2, title: '审批流优化' }, project: 'Weaver OA',
    assignee: { id: 6, name: '周八' }, priority: '紧急', status: 'todo',
    due_date: '2026-08-08', created_at: '2026-07-30'
  },
  {
    id: 11, title: '客户数据批量导入接口', description: '支持CSV/Excel批量导入客户数据',
    requirement: { id: 5, title: 'CRM客户标签管理' }, project: 'Salesforce',
    assignee: { id: 2, name: '李四' }, priority: '中', status: 'suspended',
    due_date: '2026-08-18', created_at: '2026-07-12'
  },
  {
    id: 12, title: 'WMS库存同步定时任务', description: '开发定时同步WMS库存数据到IPMS的后台任务',
    requirement: { id: 3, title: '库存盘点功能' }, project: 'SAP B1',
    assignee: { id: 1, name: '张三' }, priority: '低', status: 'done',
    due_date: '2026-07-15', created_at: '2026-06-20'
  }
]

// ================================================================
// Computed
// ================================================================
const statusTabs = computed(() => {
  const all = tasks.value
  return [
    { key: '', label: '全部', count: all.length },
    { key: 'todo', label: '待开始', count: all.filter(t => t.status === 'todo').length },
    { key: 'in_progress', label: '进行中', count: all.filter(t => t.status === 'in_progress').length },
    { key: 'done', label: '已完成', count: all.filter(t => t.status === 'done').length },
    { key: 'suspended', label: '已挂起', count: all.filter(t => t.status === 'suspended').length }
  ]
})

const filteredTasks = computed(() => {
  let list = tasks.value
  if (activeStatus.value) {
    list = list.filter(t => t.status === activeStatus.value)
  }
  if (filterProject.value) {
    list = list.filter(t => t.project === filterProject.value)
  }
  if (filterPriority.value) {
    list = list.filter(t => t.priority === filterPriority.value)
  }
  if (filterAssignee.value) {
    list = list.filter(t => t.assignee && t.assignee.id === filterAssignee.value)
  }
  return list
})

const uniqueProjects = computed(() => {
  const set = new Set(tasks.value.map(t => t.project))
  return Array.from(set).sort()
})

// ================================================================
// Dialog form
// ================================================================
const formRef = ref(null)
const form = reactive({
  title: '',
  description: '',
  requirement_id: null,
  assignee_id: null,
  priority: '中',
  due_date: ''
})

const formRules = {
  title: [{ required: true, message: '请输入任务标题', trigger: 'blur' }],
  requirement_id: [{ required: true, message: '请选择所属需求', trigger: 'change' }],
  priority: [{ required: true, message: '请选择优先级', trigger: 'change' }]
}

function resetForm() {
  form.title = ''
  form.description = ''
  form.requirement_id = null
  form.assignee_id = null
  form.priority = '中'
  form.due_date = ''
  if (formRef.value) {
    formRef.value.resetFields()
  }
}

function openCreateDialog() {
  resetForm()
  dialogVisible.value = true
}

async function handleCreateTask() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const payload = {
      title: form.title,
      description: form.description,
      requirement_id: form.requirement_id,
      assignee_id: form.assignee_id,
      priority: form.priority,
      due_date: form.due_date
    }
    await createTask(payload)
    // Simulate adding to local mock list since API is mocked
    const req = mockRequirements.find(r => r.id === form.requirement_id)
    const user = mockUsers.find(u => u.id === form.assignee_id)
    const newTask = {
      id: Math.max(...tasks.value.map(t => t.id), 0) + 1,
      title: form.title,
      description: form.description || '',
      requirement: req ? { id: req.id, title: req.title } : { id: null, title: '' },
      project: 'SAP B1',
      assignee: user ? { id: user.id, name: user.name } : null,
      priority: form.priority,
      status: 'todo',
      due_date: form.due_date || new Date().toISOString().slice(0, 10),
      created_at: new Date().toISOString().slice(0, 10)
    }
    tasks.value.unshift(newTask)
    ElMessage.success('任务创建成功')
    dialogVisible.value = false
  } catch {
    ElMessage.error('创建任务失败')
  } finally {
    submitting.value = false
  }
}

// ================================================================
// Due date helpers
// ================================================================
function getDueInfo(dueDate) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const due = new Date(dueDate)
  due.setHours(0, 0, 0, 0)
  const diff = Math.ceil((due - today) / (1000 * 60 * 60 * 24))

  if (diff < 0) {
    return { text: `已逾期 ${Math.abs(diff)} 天`, cls: 'due-overdue' }
  }
  if (diff === 0) {
    return { text: '今天到期', cls: 'due-soon' }
  }
  if (diff <= 3) {
    return { text: `剩余 ${diff} 天`, cls: 'due-soon' }
  }
  return { text: `剩余 ${diff} 天`, cls: 'due-normal' }
}

// ================================================================
// Priority tag type
// ================================================================
function getPriorityTagType(priority) {
  const map = { '紧急': 'danger', '高': 'warning', '中': 'info', '低': '' }
  return map[priority] || 'info'
}

// ================================================================
// Actions
// ================================================================
async function handleClaim(row) {
  try {
    await claimTask(row.id)
    row.status = 'in_progress'
    ElMessage.success('任务认领成功')
  } catch {
    ElMessage.error('认领失败')
  }
}

async function handleStart(row) {
  try {
    await transitionTask(row.id, { action: 'start' })
    row.status = 'in_progress'
    ElMessage.success('任务已开始')
  } catch {
    ElMessage.error('操作失败')
  }
}

async function handleComplete(row) {
  await ElMessageBox.confirm('确认标记该任务为已完成？', '确认完成', {
    confirmButtonText: '确认',
    cancelButtonText: '取消',
    type: 'success'
  })
  try {
    await transitionTask(row.id, { action: 'complete' })
    row.status = 'done'
    ElMessage.success('任务已完成')
  } catch {
    ElMessage.error('操作失败')
  }
}

async function handleSuspend(row) {
  await ElMessageBox.prompt('请输入挂起原因', '挂起任务', {
    confirmButtonText: '确认',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '挂起原因...'
  })
    .then(async ({ value }) => {
      try {
        await holdTask(row.id, { reason: value })
        row.status = 'suspended'
        ElMessage.success('任务已挂起')
      } catch {
        ElMessage.error('操作失败')
      }
    })
    .catch(() => { /* user cancelled */ })
}

async function handleResume(row) {
  try {
    await transitionTask(row.id, { action: 'resume' })
    row.status = 'in_progress'
    ElMessage.success('任务已恢复')
  } catch {
    ElMessage.error('操作失败')
  }
}

// ================================================================
// Pagination
// ================================================================
function handlePageChange(page) {
  queryParams.page = page
  fetchTasks()
}

function handlePageSizeChange(size) {
  queryParams.pageSize = size
  queryParams.page = 1
  fetchTasks()
}

// ================================================================
// Data fetching
// ================================================================
async function fetchTasks() {
  loading.value = true
  try {
    // Try real API first; fall back to mock
    const params = {
      page: queryParams.page,
      pageSize: queryParams.pageSize,
      status: activeStatus.value || undefined,
      project: filterProject.value || undefined,
      priority: filterPriority.value || undefined,
      assignee_id: filterAssignee.value || undefined
    }
    const res = await listTasks(params)
    if (res && res.data) {
      tasks.value = res.data.list || res.data.data || res.data
      queryParams.total = res.data.total || tasks.value.length
    }
  } catch {
    // Use mock data on API failure
    tasks.value = [...mockTasks]
    queryParams.total = mockTasks.length
  } finally {
    loading.value = false
  }
}

function disabledDate(date) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return date.getTime() < today.getTime()
}

// ================================================================
// Lifecycle
// ================================================================
onMounted(() => {
  fetchTasks()
})
</script>

<template>
  <div class="page-container">
    <!-- Header -->
    <div class="page-header">
      <div class="flex-between">
        <div>
          <h2 class="page-title">任务管理</h2>
          <p class="page-description">管理项目任务，跟踪任务进度</p>
        </div>
        <el-button type="primary" @click="openCreateDialog">
          <el-icon style="margin-right: 4px"><Plus /></el-icon>
          新建任务
        </el-button>
      </div>
    </div>

    <!-- Status filter tabs -->
    <div class="filter-bar">
      <el-radio-group v-model="activeStatus" size="small">
        <el-radio-button v-for="tab in statusTabs" :key="tab.key" :value="tab.key">
          {{ tab.label }}
          <span class="tab-count" v-if="tab.count > 0">&nbsp;{{ tab.count }}</span>
        </el-radio-button>
      </el-radio-group>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <el-select
        v-model="filterProject"
        placeholder="全部项目"
        clearable
        style="width: 180px"
      >
        <el-option
          v-for="proj in uniqueProjects"
          :key="proj"
          :label="proj"
          :value="proj"
        />
      </el-select>
      <el-select
        v-model="filterPriority"
        placeholder="全部优先级"
        clearable
        style="width: 160px"
      >
        <el-option label="紧急" value="紧急" />
        <el-option label="高" value="高" />
        <el-option label="中" value="中" />
        <el-option label="低" value="低" />
      </el-select>
      <UserSelector
        v-model="filterAssignee"
        placeholder="全部负责人"
        style="width: 200px"
      />
      <el-button text type="primary" @click="fetchTasks" :icon="'Refresh'">
        刷新
      </el-button>
    </div>

    <!-- Table -->
    <div class="content-card">
      <el-table
        v-loading="loading"
        :data="filteredTasks"
        stripe
        style="width: 100%"
        empty-text="暂无任务数据"
      >
        <el-table-column
          prop="title"
          label="任务标题"
          min-width="200"
          show-overflow-tooltip
        />
        <el-table-column label="负责人" width="100">
          <template #default="{ row }">
            <span v-if="row.assignee && row.assignee.name">{{ row.assignee.name }}</span>
            <span v-else class="text-muted">--</span>
          </template>
        </el-table-column>
        <el-table-column label="优先级" width="90">
          <template #default="{ row }">
            <el-tag :type="getPriorityTagType(row.priority)" size="small">
              {{ row.priority }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag type="task" :status="row.status" />
          </template>
        </el-table-column>
        <el-table-column label="截止日期" width="150">
          <template #default="{ row }">
            <div class="due-cell" v-if="row.due_date">
              <span class="due-date-text">{{ row.due_date }}</span>
              <span class="due-badge" :class="getDueInfo(row.due_date).cls">
                {{ getDueInfo(row.due_date).text }}
              </span>
            </div>
            <span v-else class="text-muted">--</span>
          </template>
        </el-table-column>
        <el-table-column label="所属需求" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <router-link
              v-if="row.requirement && row.requirement.id"
              :to="`/requirements/${row.requirement.id}`"
              class="link-text"
            >
              {{ row.requirement.title }}
            </router-link>
            <span v-else class="text-muted">--</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <div class="table-actions">
              <!-- todo -->
              <template v-if="row.status === 'todo'">
                <el-button text type="primary" size="small" @click="handleClaim(row)">
                  认领
                </el-button>
                <el-button text type="primary" size="small" @click="handleStart(row)">
                  开始
                </el-button>
              </template>
              <!-- in_progress -->
              <template v-else-if="row.status === 'in_progress'">
                <el-button text type="success" size="small" @click="handleComplete(row)">
                  完成
                </el-button>
                <el-button text type="warning" size="small" @click="handleSuspend(row)">
                  挂起
                </el-button>
              </template>
              <!-- suspended -->
              <template v-else-if="row.status === 'suspended'">
                <el-button text type="primary" size="small" @click="handleResume(row)">
                  恢复
                </el-button>
              </template>
              <!-- done: no actions -->
              <template v-else-if="row.status === 'done'">
                <span class="text-muted" style="font-size: 12px">--</span>
              </template>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- Pagination -->
      <div class="table-footer">
        <span class="text-muted">共 {{ filteredTasks.length }} 条任务</span>
        <el-pagination
          v-if="queryParams.total > queryParams.pageSize"
          v-model:current-page="queryParams.page"
          v-model:page-size="queryParams.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="queryParams.total"
          layout="prev, pager, next, sizes, total"
          background
          @current-change="handlePageChange"
          @size-change="handlePageSizeChange"
        />
      </div>
    </div>

    <!-- Create dialog -->
    <el-dialog
      v-model="dialogVisible"
      title="新建任务"
      width="560px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-width="100px"
        label-position="right"
      >
        <el-form-item label="任务标题" prop="title">
          <el-input
            v-model="form.title"
            placeholder="请输入任务标题"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="任务描述" prop="description">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="3"
            placeholder="请输入任务描述"
            maxlength="1000"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="所属需求" prop="requirement_id">
          <el-select
            v-model="form.requirement_id"
            placeholder="请选择所属需求"
            filterable
            clearable
            style="width: 100%"
          >
            <el-option
              v-for="req in mockRequirements"
              :key="req.id"
              :label="req.title"
              :value="req.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="负责人" prop="assignee_id">
          <UserSelector
            v-model="form.assignee_id"
            placeholder="请选择负责人"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="优先级" prop="priority">
          <el-select v-model="form.priority" style="width: 100%">
            <el-option label="紧急" value="紧急">
              <el-tag type="danger" size="small">紧急</el-tag>
            </el-option>
            <el-option label="高" value="高">
              <el-tag type="warning" size="small">高</el-tag>
            </el-option>
            <el-option label="中" value="中">
              <el-tag type="info" size="small">中</el-tag>
            </el-option>
            <el-option label="低" value="低">
              <el-tag size="small">低</el-tag>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="截止日期" prop="due_date">
          <el-date-picker
            v-model="form.due_date"
            type="date"
            placeholder="请选择截止日期"
            style="width: 100%"
            value-format="YYYY-MM-DD"
            :disabled-date="disabledDate"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleCreateTask">
          创建
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.tab-count {
  font-weight: 600;
}

.text-muted {
  color: $gray-500;
  font-size: $font-size-caption;
}

.link-text {
  color: $color-primary;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
    color: $color-primary-dark;
  }
}

// ----------------------------------------------------------------
// Due date cell
// ----------------------------------------------------------------
.due-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;

  .due-date-text {
    font-size: $font-size-small;
    color: $gray-900;
  }

  .due-badge {
    font-size: $font-size-caption;
    padding: 0 4px;
    border-radius: 3px;
    width: fit-content;
    white-space: nowrap;

    &.due-overdue {
      color: $color-danger;
      background: #fef0f0;
    }
    &.due-soon {
      color: $color-warning;
      background: #fdf6ec;
    }
    &.due-normal {
      color: $color-success;
    }
  }
}

// ----------------------------------------------------------------
// Table footer
// ----------------------------------------------------------------
.table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
}
</style>
