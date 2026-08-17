<script setup>
import { ref, computed, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft, Document, Plus, Edit } from '@element-plus/icons-vue'
import StatusTag from '@/components/common/StatusTag.vue'
import {
  getRequirement,
  transitionRequirement,
  reviewRequirement,
  getRequirementVersions
} from '@/api/requirement'
import { createTask } from '@/api/task'
import {
  getRequirementStatusActions,
  requirementTransitionPayload
} from '@/workflows/actionPayloads'

const route = useRoute()
const router = useRouter()

// ============================================================
// Mock Data
// ============================================================

const requirement = ref({
  id: route.params.id,
  title: '新增财务报表功能',
  version: 'v3',
  description: '在 SAP B1 中新增月度/季度/年度财务报表功能，支持导出 Excel 格式。',
  projects: ['SAP B1', 'VPMS'],
  priority: '高',
  type: '新功能',
  submitter: '王业务',
  submitDate: '2026-07-15',
  expectedDate: '2026-09-30',
  reviewer: '张三',
  developer: '李四',
  status: 'developing',
  attachments: [
    { name: '财务报表需求说明.docx', size: '256KB' },
    { name: '财务模块接口文档.pdf', size: '1.2MB' }
  ]
})

const subtasks = ref([
  {
    id: 1,
    title: '设计报表数据库表结构',
    description: '设计并创建报表相关的数据库表',
    assignee: { id: 1, name: '李四' },
    priority: '高',
    status: 'done',
    due_date: '2026-08-05'
  },
  {
    id: 2,
    title: '实现报表数据查询接口',
    description: '开发报表数据查询API',
    assignee: { id: 1, name: '李四' },
    priority: '高',
    status: 'in_progress',
    due_date: '2026-08-15'
  },
  {
    id: 3,
    title: '开发前端报表展示页面',
    description: '开发Vue前端报表页面',
    assignee: { id: 2, name: '王五' },
    priority: '中',
    status: 'todo',
    due_date: '2026-08-25'
  },
  {
    id: 4,
    title: '编写测试用例',
    description: '编写报表功能的单元测试和集成测试',
    assignee: { id: 3, name: '赵六' },
    priority: '中',
    status: 'todo',
    due_date: '2026-08-28'
  }
])

const changeLogs = ref([
  {
    version: 'v3',
    date: '2026-08-02',
    user: '张三',
    action: '修改了优先级和期望日期',
    diffs: [
      { field: '优先级', old_value: '中', new_value: '高' },
      { field: '期望完成', old_value: '2026-10-15', new_value: '2026-09-30' }
    ]
  },
  {
    version: 'v2',
    date: '2026-07-28',
    user: '李四',
    action: '修改了描述内容',
    diffs: [
      {
        field: '描述',
        old_value: '新增财务报表功能',
        new_value: '在 SAP B1 中新增月度/季度/年度财务报表功能，支持导出 Excel 格式。'
      }
    ]
  },
  {
    version: 'v1',
    date: '2026-07-15',
    user: '王业务',
    action: '创建了需求',
    diffs: []
  }
])

const defects = ref([
  {
    id: 1,
    title: '报表导出Excel格式错误',
    severity: 'major',
    status: 'fixing',
    finder: '测试员A',
    found_at: '2026-08-03'
  },
  {
    id: 2,
    title: '年度报表数据汇总不正确',
    severity: 'critical',
    status: 'confirmed',
    finder: '王业务',
    found_at: '2026-08-02'
  },
  {
    id: 3,
    title: '报表页面加载缓慢',
    severity: 'minor',
    status: 'pending',
    finder: '李四',
    found_at: '2026-08-01'
  }
])

const users = ref([
  { id: 1, name: '李四' },
  { id: 2, name: '王五' },
  { id: 3, name: '赵六' },
  { id: 4, name: '张三' }
])

const priorityOptions = [
  { value: '紧急', label: '紧急' },
  { value: '高', label: '高' },
  { value: '中', label: '中' },
  { value: '低', label: '低' }
]

// ============================================================
// Status Configuration
// ============================================================

const requirementStatusMap = {
  'pending_review': { color: '#4285F4', label: '待审核' },
  'assigned': { color: '#FF9800', label: '已分配' },
  'developing': { color: '#3F51B5', label: '开发中' },
  'testing': { color: '#9C27B0', label: '测试中' },
  'pending_online': { color: '#00BCD4', label: '待上线' },
  'online': { color: '#67C23A', label: '已上线' },
  'accepted': { color: '#1B5E20', label: '已验收' }
}

const statusConfig = computed(() => {
  return (
    requirementStatusMap[requirement.value.status] || {
      color: '#909399',
      label: requirement.value.status
    }
  )
})

const statusActions = computed(() => getRequirementStatusActions(requirement.value.status))

// ============================================================
// Status Transition
// ============================================================

const actionDialogVisible = ref(false)
const actionComment = ref('')
const currentAction = ref(null)
const actionLoading = ref(false)

const actionDialogTitle = computed(() => {
  if (!currentAction.value) return ''
  return `确认${currentAction.value.label}`
})

function openActionDialog(action) {
  currentAction.value = action
  actionComment.value = ''
  actionDialogVisible.value = true
}

function updateLocalStatus(actionKey) {
  const statusMap = {
    approve: 'assigned',
    start_dev: 'developing',
    complete_dev: 'testing',
    pass_test: 'pending_online',
    fail_test: 'developing',
    confirm_online: 'online',
    confirm_accept: 'accepted'
  }
  if (statusMap[actionKey]) {
    requirement.value.status = statusMap[actionKey]
  }
}

async function confirmAction() {
  if (!currentAction.value) return

  const action = currentAction.value
  actionLoading.value = true

  try {
    if (action.key === 'approve' || action.key === 'reject') {
      await reviewRequirement(requirement.value.id, {
        action: action.key,
        comment: actionComment.value || undefined
      })
    } else {
      await transitionRequirement(requirement.value.id, requirementTransitionPayload(action.key))
    }

    ElMessage.success(`${action.label}操作成功`)
    actionDialogVisible.value = false
    updateLocalStatus(action.key)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || error?.message || '操作失败，请重试')
  } finally {
    actionLoading.value = false
  }
}

// ============================================================
// Priority & Severity Helpers
// ============================================================

function getPriorityTagType(priority) {
  const map = { '紧急': 'danger', '高': 'warning', '中': 'info', '低': '' }
  return map[priority] || 'info'
}

function getSeverityTagType(severity) {
  const map = { critical: 'danger', major: 'warning', minor: 'info', trivial: 'success' }
  return map[severity] || 'info'
}

function getSeverityLabel(severity) {
  const map = { critical: '致命', major: '严重', minor: '一般', trivial: '轻微' }
  return map[severity] || severity
}

// ============================================================
// New Subtask Dialog
// ============================================================

const taskDialogVisible = ref(false)
const taskFormRef = ref(null)
const taskCreating = ref(false)

const taskForm = reactive({
  title: '',
  description: '',
  assignee_id: null,
  priority: '中',
  due_date: ''
})

const taskRules = {
  title: [{ required: true, message: '请输入子任务标题', trigger: 'blur' }],
  assignee_id: [{ required: true, message: '请选择负责人', trigger: 'change' }],
  priority: [{ required: true, message: '请选择优先级', trigger: 'change' }]
}

function openCreateTaskDialog() {
  taskForm.title = ''
  taskForm.description = ''
  taskForm.assignee_id = null
  taskForm.priority = '中'
  taskForm.due_date = ''
  taskDialogVisible.value = true
}

async function submitCreateTask() {
  const valid = await taskFormRef.value?.validate().catch(() => false)
  if (!valid) return

  taskCreating.value = true
  try {
    await createTask({
      title: taskForm.title,
      description: taskForm.description,
      requirement_id: requirement.value.id,
      priority: taskForm.priority,
      assignee_id: taskForm.assignee_id,
      due_date: taskForm.due_date || undefined
    })

    // Mock: add the new task to the local list
    const assignee = users.value.find((u) => u.id === taskForm.assignee_id)
    subtasks.value.push({
      id: Date.now(),
      title: taskForm.title,
      description: taskForm.description,
      assignee: assignee || { id: taskForm.assignee_id, name: '未知' },
      priority: taskForm.priority,
      status: 'todo',
      due_date: taskForm.due_date || '-'
    })

    ElMessage.success('子任务创建成功')
    taskDialogVisible.value = false
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || error?.message || '创建失败，请重试')
  } finally {
    taskCreating.value = false
  }
}

// ============================================================
// Diff Viewer
// ============================================================

const diffDialogVisible = ref(false)
const diffData = ref([])

function hasDiff(log) {
  return log.diffs && log.diffs.length > 0
}

function openDiffDialog(log) {
  diffData.value = log.diffs
  diffDialogVisible.value = true
}
</script>

<template>
  <div class="page-container">
    <!-- Page Header -->
    <div class="page-header flex-between">
      <h2 class="page-title">需求详情 - {{ requirement.title }}</h2>
      <el-button :icon="ArrowLeft" @click="router.back()">返回列表</el-button>
    </div>

    <!-- Status Flow Action Bar -->
    <el-card class="status-flow-card mb-16">
      <div class="status-flow">
        <div class="current-status">
          <span
            class="status-dot"
            :style="{ backgroundColor: statusConfig.color }"
          ></span>
          <span class="status-label-text">{{ statusConfig.label }}</span>
        </div>
        <el-divider direction="vertical" />
        <div class="available-actions">
          <span class="action-hint">可用操作：</span>
          <template v-if="statusActions.length > 0">
            <el-button
              v-for="action in statusActions"
              :key="action.key"
              :type="action.type || 'primary'"
              :plain="action.plain || false"
              size="small"
              @click="openActionDialog(action)"
            >
              {{ action.label }}
            </el-button>
          </template>
          <span v-else class="no-action-text">暂无可用操作</span>
        </div>
      </div>
    </el-card>

    <!-- Requirement Info -->
    <el-card class="content-card mb-16">
      <template #header>
        <div class="card-header">
          <span class="card-title-text">需求信息</span>
          <el-button type="primary" size="small" :icon="Edit">编辑需求</el-button>
        </div>
      </template>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="需求标题">{{ requirement.title }}</el-descriptions-item>
        <el-descriptions-item label="版本">{{ requirement.version }}</el-descriptions-item>
        <el-descriptions-item label="需求描述" :span="2">{{ requirement.description }}</el-descriptions-item>
        <el-descriptions-item label="关联项目">
          <el-tag
            v-for="p in requirement.projects"
            :key="p"
            size="small"
            class="project-tag"
          >
            {{ p }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="优先级">
          <el-tag :type="getPriorityTagType(requirement.priority)" size="small">
            {{ requirement.priority }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="需求类型">{{ requirement.type }}</el-descriptions-item>
        <el-descriptions-item label="提出人">{{ requirement.submitter }}</el-descriptions-item>
        <el-descriptions-item label="提出时间">{{ requirement.submitDate }}</el-descriptions-item>
        <el-descriptions-item label="期望完成">{{ requirement.expectedDate }}</el-descriptions-item>
        <el-descriptions-item label="审核人">{{ requirement.reviewer }}</el-descriptions-item>
        <el-descriptions-item label="开发负责人">{{ requirement.developer }}</el-descriptions-item>
        <el-descriptions-item label="附件" :span="2">
          <div
            v-for="file in requirement.attachments"
            :key="file.name"
            class="attachment-item"
          >
            <el-icon><Document /></el-icon>
            <span class="attachment-name">{{ file.name }}</span>
            <span class="attachment-size">({{ file.size }})</span>
            <el-button text type="primary" size="small">下载</el-button>
          </div>
          <span v-if="!requirement.attachments || requirement.attachments.length === 0" class="text-muted">暂无附件</span>
        </el-descriptions-item>
      </el-descriptions>
    </el-card>

    <!-- Subtask List -->
    <el-card class="content-card mb-16">
      <template #header>
        <div class="card-header">
          <span class="card-title-text">子任务列表</span>
          <el-button type="primary" size="small" :icon="Plus" @click="openCreateTaskDialog">
            新建子任务
          </el-button>
        </div>
      </template>
      <el-table :data="subtasks" border stripe style="width: 100%">
        <el-table-column prop="title" label="子任务标题" min-width="180" show-overflow-tooltip />
        <el-table-column label="负责人" width="100">
          <template #default="{ row }">
            {{ row.assignee?.name || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="优先级" width="80">
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
        <el-table-column prop="due_date" label="截止日期" width="120" />
      </el-table>
    </el-card>

    <!-- Change History -->
    <el-card class="content-card mb-16">
      <template #header>
        <span class="card-title-text">变更记录</span>
      </template>
      <el-timeline>
        <el-timeline-item
          v-for="log in changeLogs"
          :key="log.version"
          :timestamp="log.date"
          placement="top"
        >
          <p class="change-log-entry">
            <strong>{{ log.version }}</strong>
            <span class="change-log-user">{{ log.user }}</span>
            <span>{{ log.action }}</span>
            <el-button
              v-if="hasDiff(log)"
              text
              type="primary"
              size="small"
              @click="openDiffDialog(log)"
            >
              查看Diff
            </el-button>
          </p>
        </el-timeline-item>
      </el-timeline>
    </el-card>

    <!-- Associated Defects -->
    <el-card class="content-card">
      <template #header>
        <span class="card-title-text">关联缺陷</span>
      </template>
      <el-table :data="defects" border stripe style="width: 100%">
        <el-table-column prop="title" label="缺陷标题" min-width="200" show-overflow-tooltip />
        <el-table-column label="严重程度" width="100">
          <template #default="{ row }">
            <el-tag :type="getSeverityTagType(row.severity)" size="small">
              {{ getSeverityLabel(row.severity) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag type="defect" :status="row.status" />
          </template>
        </el-table-column>
        <el-table-column prop="finder" label="发现人" width="100" />
        <el-table-column prop="found_at" label="发现时间" width="120" />
      </el-table>
    </el-card>

    <!-- Status Action Confirmation Dialog -->
    <el-dialog
      v-model="actionDialogVisible"
      :title="actionDialogTitle"
      width="450px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form label-width="80px">
        <el-form-item label="操作备注">
          <el-input
            v-model="actionComment"
            type="textarea"
            :rows="3"
            placeholder="请输入备注（可选）"
            maxlength="500"
            show-word-limit
          />
        </el-form-item>
        <el-form-item v-if="currentAction?.key === 'reject'" label="驳回提醒">
          <el-alert
            type="warning"
            :closable="false"
            show-icon
            title="驳回后需求将退回提交者重新修改"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="actionDialogVisible = false" :disabled="actionLoading">取消</el-button>
        <el-button type="primary" @click="confirmAction" :loading="actionLoading">确认操作</el-button>
      </template>
    </el-dialog>

    <!-- New Subtask Dialog -->
    <el-dialog
      v-model="taskDialogVisible"
      title="新建子任务"
      width="560px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form
        ref="taskFormRef"
        :model="taskForm"
        :rules="taskRules"
        label-width="80px"
      >
        <el-form-item label="子任务标题" prop="title">
          <el-input
            v-model="taskForm.title"
            placeholder="请输入子任务标题"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="任务描述" prop="description">
          <el-input
            v-model="taskForm.description"
            type="textarea"
            :rows="3"
            placeholder="请输入任务描述（可选）"
            maxlength="1000"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="负责人" prop="assignee_id">
          <el-select v-model="taskForm.assignee_id" placeholder="请选择负责人" style="width: 100%">
            <el-option
              v-for="user in users"
              :key="user.id"
              :label="user.name"
              :value="user.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="优先级" prop="priority">
          <el-select v-model="taskForm.priority" placeholder="请选择优先级" style="width: 100%">
            <el-option
              v-for="opt in priorityOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="截止日期" prop="due_date">
          <el-date-picker
            v-model="taskForm.due_date"
            type="date"
            placeholder="请选择截止日期"
            style="width: 100%"
            value-format="YYYY-MM-DD"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="taskDialogVisible = false" :disabled="taskCreating">取消</el-button>
        <el-button type="primary" @click="submitCreateTask" :loading="taskCreating">确认创建</el-button>
      </template>
    </el-dialog>

    <!-- Diff Comparison Dialog -->
    <el-dialog
      v-model="diffDialogVisible"
      title="变更对比"
      width="650px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-table :data="diffData" border style="width: 100%">
        <el-table-column prop="field" label="变更字段" width="120" />
        <el-table-column label="旧值" min-width="200">
          <template #default="{ row }">
            <span class="diff-old-value">{{ row.old_value || '(空)' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="新值" min-width="200">
          <template #default="{ row }">
            <span class="diff-new-value">{{ row.new_value || '(空)' }}</span>
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button type="primary" @click="diffDialogVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// ============================================================
// Page Layout
// ============================================================

.page-container {
  padding: 0;
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;

  .page-title {
    font-size: $font-size-h2;
    font-weight: 600;
    color: $gray-900;
    margin: 0;
  }
}

// ============================================================
// Card Common
// ============================================================

.content-card {
  border-radius: $border-radius-md;

  :deep(.el-card__header) {
    padding: 12px 20px;
    background: $gray-50;
    border-bottom: 1px solid $gray-200;
  }

  :deep(.el-card__body) {
    padding: 20px;
  }
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title-text {
  font-size: $font-size-h3;
  font-weight: 600;
  color: $gray-900;
}

.mb-16 {
  margin-bottom: 16px;
}

// ============================================================
// Status Flow Action Bar
// ============================================================

.status-flow-card {
  background: #f0f9ff;
  border-color: #b3d8ff;

  :deep(.el-card__body) {
    padding: 16px 20px;
  }

  .status-flow {
    display: flex;
    align-items: center;
  }

  .current-status {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;

    .status-dot {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .status-label-text {
      font-size: $font-size-body;
      font-weight: 600;
      color: $gray-900;
    }
  }

  .available-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;

    .action-hint {
      font-size: $font-size-small;
      color: $gray-500;
      margin-right: 4px;
    }

    .no-action-text {
      font-size: $font-size-small;
      color: $gray-500;
    }
  }
}

// ============================================================
// Descriptions
// ============================================================

:deep(.el-descriptions__label) {
  font-weight: 500;
  color: $gray-700;
  white-space: nowrap;
}

:deep(.el-descriptions__content) {
  color: $gray-900;
}

.project-tag {
  margin-right: 4px;
}

// ============================================================
// Attachments
// ============================================================

.attachment-item {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;

  &:last-child {
    margin-bottom: 0;
  }

  .el-icon {
    color: $gray-500;
    flex-shrink: 0;
  }

  .attachment-name {
    color: $gray-900;
    font-size: $font-size-body;
  }

  .attachment-size {
    color: $gray-500;
    font-size: $font-size-caption;
  }
}

.text-muted {
  color: $gray-500;
  font-size: $font-size-small;
}

// ============================================================
// Change Log Timeline
// ============================================================

.change-log-entry {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin: 0;

  strong {
    color: $color-primary;
  }

  .change-log-user {
    color: $gray-700;
    font-weight: 500;
  }
}

// ============================================================
// Diff Viewer
// ============================================================

.diff-old-value {
  color: $color-danger;
  text-decoration: line-through;
}

.diff-new-value {
  color: $color-success;
  font-weight: 500;
}
</style>
