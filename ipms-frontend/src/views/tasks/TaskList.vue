<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  CircleCheck,
  EditPen,
  Plus,
  User,
  VideoPause,
  VideoPlay,
} from '@element-plus/icons-vue'
import { listRequirements } from '@/api/requirement'
import {
  claimTask,
  createTask,
  holdTask,
  listTasks,
  transitionTask,
  updateTask,
} from '@/api/task'
import { allPages } from '@/api/allPages'
import FilterBar from '@/components/common/FilterBar.vue'
import AssignmentDialog from '@/components/common/AssignmentDialog.vue'
import UserSelector from '@/components/common/UserSelector.vue'
const assignmentRecord = ref(null)
import PaginatedTable from '@/components/common/PaginatedTable.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { mapApiError } from '@/composables/useApiError'
import { usePagination } from '@/composables/usePagination'
import { usePermission } from '@/composables/usePermission'

const route = useRoute()
const router = useRouter()
const { canCreate, canPerform } = usePermission()

function positiveInteger(value, fallback) {
  const parsed = Number.parseInt(value, 10)
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback
}

const {
  page,
  pageSize,
  total,
  totalPages,
  requestParams,
  applyPagination,
  setPage,
  setPageSize,
  resetPage,
} = usePagination({
  page: positiveInteger(route.query.page, 1),
  pageSize: positiveInteger(route.query.page_size, 20),
})

const tasks = ref([])
const requirementOptions = ref([])
const keyword = ref(String(route.query.keyword ?? ''))
const status = ref(route.query.status ? Number(route.query.status) : '')
const priority = ref(route.query.priority ? Number(route.query.priority) : '')
const projectId = ref(route.query.project_id ? Number(route.query.project_id) : '')
const assigneeId = ref(route.query.assignee_id ? Number(route.query.assignee_id) : '')
const loading = ref(false)
const error = ref(null)
const busyId = ref(null)
const dialogVisible = ref(false)
const formMode = ref('create')
const saving = ref(false)
const form = reactive({
  id: null,
  requirement_id: '',
  project_id: '',
  title: '',
  description: '',
  assignee_id: null,
  priority: 3,
  due_date: '',
  remind_days_before: null,
  estimated_hours: null,
  actual_hours: null,
})

const dialogTitle = computed(() => formMode.value === 'create' ? '新建任务' : '编辑任务')
const selectedRequirement = computed(() => (
  requirementOptions.value.find((item) => item.id === Number(form.requirement_id))
))
const projectOptions = computed(() => (
  (selectedRequirement.value?.project_deliveries ?? [])
    .filter((delivery) => delivery.project && delivery.allowed_actions?.includes('create_task'))
    .map((delivery) => delivery.project)
))
const visibleProjects = computed(() => {
  const byId = new Map()
  tasks.value.forEach((task) => {
    if (task.project) byId.set(task.project.id, task.project)
  })
  requirementOptions.value.forEach((requirement) => {
    ;(requirement.projects ?? []).forEach((project) => byId.set(project.id, project))
  })
  return [...byId.values()]
})

function buildParams() {
  const params = { ...requestParams.value }
  if (keyword.value.trim()) params.keyword = keyword.value.trim()
  if (status.value !== '') params.status = Number(status.value)
  if (priority.value !== '') params.priority = Number(priority.value)
  if (projectId.value !== '') params.project_id = Number(projectId.value)
  if (assigneeId.value !== '') params.assignee_id = Number(assigneeId.value)
  return params
}

function queryState() {
  const query = {}
  if (page.value !== 1) query.page = String(page.value)
  if (pageSize.value !== 20) query.page_size = String(pageSize.value)
  if (keyword.value.trim()) query.keyword = keyword.value.trim()
  if (status.value !== '') query.status = String(status.value)
  if (priority.value !== '') query.priority = String(priority.value)
  if (projectId.value !== '') query.project_id = String(projectId.value)
  if (assigneeId.value !== '') query.assignee_id = String(assigneeId.value)
  return query
}

async function syncQuery() {
  await router.replace({ query: queryState() })
}

async function fetchTasks() {
  loading.value = true
  error.value = null
  try {
    const response = await listTasks(buildParams())
    const payload = response?.data?.data ?? {}
    const items = payload.items ?? []
    applyPagination(payload)
    const lastPage = Math.max(totalPages.value, 1)
    if (items.length === 0 && page.value > lastPage) {
      setPage(lastPage)
      await syncQuery()
      await fetchTasks()
      return
    }
    tasks.value = items
  } catch (requestError) {
    tasks.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

async function loadRequirements() {
  try {
    requirementOptions.value = await allPages(listRequirements)
  } catch {
    requirementOptions.value = []
  }
}

async function applyFilters() {
  resetPage()
  await syncQuery()
  await fetchTasks()
}

async function resetFilters() {
  keyword.value = ''
  status.value = ''
  priority.value = ''
  projectId.value = ''
  assigneeId.value = ''
  setPageSize(20)
  await router.replace({ query: {} })
  await fetchTasks()
}

async function handlePageChange(nextPage) {
  setPage(nextPage)
  await syncQuery()
  await fetchTasks()
}

async function handlePageSizeChange(nextPageSize) {
  setPageSize(nextPageSize)
  await syncQuery()
  await fetchTasks()
}

function resetForm() {
  Object.assign(form, {
    id: null,
    requirement_id: '',
    project_id: '',
    title: '',
    description: '',
    assignee_id: null,
    priority: 3,
    due_date: '',
    remind_days_before: null,
    estimated_hours: null,
    actual_hours: null,
  })
}

async function openCreate() {
  resetForm()
  formMode.value = 'create'
  dialogVisible.value = true
  if (requirementOptions.value.length === 0) await loadRequirements()
}

async function openEdit(task) {
  resetForm()
  formMode.value = 'edit'
  Object.assign(form, {
    id: task.id,
    requirement_id: task.requirement?.id ?? '',
    project_id: task.project?.id ?? '',
    title: task.title,
    description: task.description ?? '',
    assignee_id: task.assignee?.id ?? null,
    priority: task.priority,
    due_date: task.due_date ?? '',
    remind_days_before: task.remind_days_before,
    estimated_hours: task.estimated_hours,
    actual_hours: task.actual_hours,
  })
  dialogVisible.value = true
  if (requirementOptions.value.length === 0) await loadRequirements()
}

function handleRequirementChange() {
  form.project_id = projectOptions.value.length === 1 ? projectOptions.value[0].id : ''
}

function formPayload() {
  const payload = {
    title: form.title.trim(),
    description: form.description.trim() || null,
    ...(formMode.value === 'create' ? {
      assignee_id: form.assignee_id ? Number(form.assignee_id) : null,
    } : {}),
    priority: Number(form.priority),
    due_date: form.due_date,
    remind_days_before: form.remind_days_before,
    estimated_hours: form.estimated_hours,
  }
  if (formMode.value === 'create') {
    payload.requirement_id = Number(form.requirement_id)
    payload.project_id = Number(form.project_id)
  } else {
    payload.actual_hours = form.actual_hours
  }
  return payload
}

async function saveTask() {
  const payload = formPayload()
  if (!payload.title || !payload.due_date
    || (formMode.value === 'create' && (!payload.requirement_id || !payload.project_id))) {
    ElMessage.error('请填写标题、所属需求、所属项目和截止日期')
    return
  }

  saving.value = true
  try {
    if (formMode.value === 'create') {
      await createTask(payload)
      ElMessage.success(`任务「${payload.title}」已创建`)
    } else {
      await updateTask(form.id, payload)
      ElMessage.success(`任务「${payload.title}」已更新`)
    }
    dialogVisible.value = false
    await fetchTasks()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '任务保存失败')
  } finally {
    saving.value = false
  }
}

async function mutate(task, request, successMessage) {
  busyId.value = task.id
  try {
    await request()
    ElMessage.success(successMessage)
    await fetchTasks()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '操作失败')
  } finally {
    busyId.value = null
  }
}

async function handleClaim(task) {
  try {
    await ElMessageBox.confirm(
      `确认认领任务「${task.title}」吗？`,
      '认领任务',
      { confirmButtonText: '认领', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(task, () => claimTask(task.id), '任务已认领')
}

function handleAssign(task) {
  assignmentRecord.value = task
}

async function handleTransition(task, targetStatus, targetLabel) {
  try {
    await ElMessageBox.confirm(
      `确认将任务「${task.title}」变更为“${targetLabel}”吗？`,
      '变更任务状态',
      { confirmButtonText: '确认', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(
    task,
    () => transitionTask(task.id, { status: targetStatus }),
    `任务已变更为${targetLabel}`,
  )
}

async function handleHold(task) {
  let reasonText
  try {
    const result = await ElMessageBox.prompt(
      `请填写任务「${task.title}」的挂起原因`,
      '挂起任务',
      {
        inputType: 'textarea',
        inputPattern: /\S+/,
        inputErrorMessage: '挂起原因不能为空',
        confirmButtonText: '确认挂起',
        cancelButtonText: '取消',
      },
    )
    reasonText = result.value.trim()
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(task, () => holdTask(task.id, { reason: reasonText }), '任务已挂起')
}

function canUse(task, action, localAllowed = true) {
  return canPerform(task, action, localAllowed)
}

onMounted(() => {
  fetchTasks()
  loadRequirements()
})
</script>

<template>
  <div class="page-container queue-page">
    <header class="page-heading">
      <div>
        <h1 class="page-title">任务管理</h1>
        <p class="page-description">按项目和负责人跟踪开发交付任务</p>
      </div>
      <el-button v-if="canCreate('task')" type="primary" :icon="Plus" @click="openCreate">
        新建任务
      </el-button>
    </header>

    <FilterBar :busy="loading" @search="applyFilters" @reset="resetFilters">
      <el-input
        v-model="keyword"
        class="filter-control filter-control--wide"
        clearable
        placeholder="搜索任务标题"
        @keyup.enter="applyFilters"
      />
      <el-select v-model="status" class="filter-control" clearable placeholder="全部状态">
        <el-option label="待开始" :value="1" />
        <el-option label="进行中" :value="2" />
        <el-option label="已完成" :value="3" />
        <el-option label="已挂起" :value="4" />
      </el-select>
      <el-select v-model="priority" class="filter-control" clearable placeholder="全部优先级">
        <el-option label="紧急" :value="1" />
        <el-option label="高" :value="2" />
        <el-option label="中" :value="3" />
        <el-option label="低" :value="4" />
      </el-select>
      <el-select v-model="projectId" class="filter-control" clearable filterable placeholder="全部项目">
        <el-option v-for="project in visibleProjects" :key="project.id" :label="project.name" :value="project.id" />
      </el-select>
      <el-select v-model="assigneeId" class="filter-control" clearable placeholder="全部负责人">
        <el-option v-for="user in [...new Map(tasks.filter(item => item.assignee).map(item => [item.assignee.id, item.assignee])).values()]" :key="user.id" :label="user.display_name" :value="user.id" />
      </el-select>
    </FilterBar>

    <PaginatedTable
      :loading="loading"
      :error="error"
      :rows="tasks"
      :total="total"
      :page="page"
      :page-size="pageSize"
      empty-title="暂无任务"
      empty-description="当前筛选条件下没有可查看的任务"
      @retry="fetchTasks"
      @page-change="handlePageChange"
      @page-size-change="handlePageSizeChange"
    >
      <template #default="{ rows }">
        <table class="data-table">
          <thead>
            <tr>
              <th>任务</th>
              <th>项目 / 需求</th>
              <th>负责人</th>
              <th>截止日期</th>
              <th>优先级</th>
              <th>状态</th>
              <th class="actions-column">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="task in rows" :key="task.id">
              <td>
                <span class="record-title">{{ task.title }}</span>
                <span class="record-meta">TASK-{{ task.id }}</span>
              </td>
              <td class="wrap-cell">
                <span>{{ task.project?.name || '-' }}</span>
                <span class="record-meta">{{ task.requirement?.title || '-' }}</span>
              </td>
              <td>{{ task.assignee?.display_name || '未分配' }}</td>
              <td>{{ task.due_date || '-' }}</td>
              <td>{{ task.priority_label || '-' }}</td>
              <td>
                <StatusTag :status-code="task.status_code" :status-label="task.status_label" />
              </td>
              <td>
                <div class="row-actions">
                  <el-tooltip v-if="canUse(task, 'edit')" content="编辑任务">
                    <el-button
                      text
                      :icon="EditPen"
                      :data-testid="`edit-task-${task.id}`"
                      aria-label="编辑任务"
                      @click="openEdit(task)"
                    />
                  </el-tooltip>
                  <el-button
                    v-if="canUse(task, 'claim')"
                    text
                    type="primary"
                    :icon="User"
                    :loading="busyId === task.id"
                    :data-testid="`claim-task-${task.id}`"
                    @click="handleClaim(task)"
                  >
                    认领
                  </el-button>
                  <el-button
                    v-if="canUse(task, 'assign')"
                    text
                    :icon="User"
                    :data-testid="`assign-task-${task.id}`"
                    @click="handleAssign(task)"
                  >
                    分配
                  </el-button>
                  <el-button
                    v-if="canUse(task, 'transition') && task.status_code === 'TODO'"
                    text
                    type="primary"
                    :icon="VideoPlay"
                    @click="handleTransition(task, 2, '进行中')"
                  >
                    开始
                  </el-button>
                  <el-button
                    v-if="canUse(task, 'transition') && task.status_code === 'IN_PROGRESS'"
                    text
                    type="success"
                    :icon="CircleCheck"
                    @click="handleTransition(task, 3, '已完成')"
                  >
                    完成
                  </el-button>
                  <template v-if="canUse(task, 'transition') && task.status_code === 'SUSPENDED'">
                    <el-button text @click="handleTransition(task, 1, '待开始')">恢复待办</el-button>
                    <el-button text type="primary" @click="handleTransition(task, 2, '进行中')">恢复执行</el-button>
                  </template>
                  <el-button
                    v-if="canUse(task, 'hold')"
                    text
                    type="warning"
                    :icon="VideoPause"
                    @click="handleHold(task)"
                  >
                    挂起
                  </el-button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </PaginatedTable>

    <AssignmentDialog :record="assignmentRecord" work-type="task" @close="assignmentRecord = null" @assigned="fetchTasks" />
    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="min(680px, 94vw)" destroy-on-close>
      <el-form label-position="top">
        <div v-if="formMode === 'create'" class="form-grid">
          <el-form-item label="所属需求" required>
            <el-select
              v-model="form.requirement_id"
              filterable
              placeholder="选择已审核需求"
              @change="handleRequirementChange"
            >
              <el-option
                v-for="requirement in requirementOptions.filter((item) => item.allowed_actions?.includes('create_task'))"
                :key="requirement.id"
                :label="requirement.title"
                :value="requirement.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="所属项目" required>
            <el-select v-model="form.project_id" filterable placeholder="选择关联项目">
              <el-option
                v-for="project in projectOptions"
                :key="project.id"
                :label="project.name"
                :value="project.id"
              />
            </el-select>
          </el-form-item>
        </div>
        <el-form-item label="任务标题" required>
          <el-input v-model="form.title" maxlength="200" show-word-limit />
        </el-form-item>
        <el-form-item label="任务描述">
          <el-input v-model="form.description" type="textarea" :rows="4" />
        </el-form-item>
        <div class="form-grid">
          <el-form-item v-if="formMode === 'create'" label="负责人">
            <UserSelector v-model="form.assignee_id" :project-id="form.project_id" />
          </el-form-item>
          <el-form-item label="优先级" required>
            <el-select v-model="form.priority">
              <el-option label="紧急" :value="1" />
              <el-option label="高" :value="2" />
              <el-option label="中" :value="3" />
              <el-option label="低" :value="4" />
            </el-select>
          </el-form-item>
          <el-form-item label="截止日期" required>
            <el-date-picker v-model="form.due_date" type="date" value-format="YYYY-MM-DD" />
          </el-form-item>
          <el-form-item label="提前提醒天数">
            <el-input-number v-model="form.remind_days_before" :min="0" controls-position="right" />
          </el-form-item>
          <el-form-item label="预计工时">
            <el-input-number v-model="form.estimated_hours" :min="0" :precision="1" controls-position="right" />
          </el-form-item>
          <el-form-item v-if="formMode === 'edit'" label="实际工时">
            <el-input-number v-model="form.actual_hours" :min="0" :precision="1" controls-position="right" />
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveTask">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.queue-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.page-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
}

.page-title {
  margin: 0;
  color: $color-ink;
  font-size: $font-size-h1;
  letter-spacing: 0;
}

.page-description {
  margin: 6px 0 0;
  color: $color-muted;
}

.filter-control {
  width: 165px;

  &--wide {
    width: min(290px, 100%);
  }
}

.data-table {
  width: 100%;
  min-width: 1120px;
  border-collapse: collapse;
  color: $color-ink;
  font-size: $font-size-body;

  th,
  td {
    padding: 14px 16px;
    border-bottom: 1px solid $color-border;
    text-align: left;
    vertical-align: middle;
  }

  th {
    background: $color-canvas;
    color: $color-muted;
    font-size: $font-size-small;
    font-weight: 600;
  }

  tbody tr:hover {
    background: #f8fafc;
  }

  tbody tr:last-child td {
    border-bottom: 0;
  }
}

.actions-column {
  width: 310px;
}

.record-title {
  display: block;
  font-weight: 600;
}

.record-meta {
  display: block;
  margin-top: 4px;
  color: $color-muted;
  font-size: $font-size-caption;
}

.wrap-cell {
  max-width: 240px;
  white-space: normal;
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 4px;

  :deep(.el-button + .el-button) {
    margin-left: 0;
  }
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 16px;

  :deep(.el-select),
  :deep(.el-date-editor),
  :deep(.el-input-number) {
    width: 100%;
  }
}

@media (max-width: 760px) {
  .page-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .filter-control,
  .filter-control--wide {
    width: 100%;
  }

  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
