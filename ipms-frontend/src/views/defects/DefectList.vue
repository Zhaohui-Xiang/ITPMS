<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check, EditPen, Plus, Refresh, User } from '@element-plus/icons-vue'
import {
  assignDefect,
  confirmDefect,
  createDefect,
  listDefects,
  reopenDefect,
  resolveDefect,
  updateDefect,
  verifyDefect,
} from '@/api/defect'
import { listRequirements } from '@/api/requirement'
import { allPages } from '@/api/allPages'
import FilterBar from '@/components/common/FilterBar.vue'
import AssignmentDialog from '@/components/common/AssignmentDialog.vue'
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

const defects = ref([])
const requirementOptions = ref([])
const keyword = ref(String(route.query.keyword ?? ''))
const status = ref(route.query.status ? Number(route.query.status) : '')
const severity = ref(route.query.severity ? Number(route.query.severity) : '')
const projectId = ref(route.query.project_id ? Number(route.query.project_id) : '')
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
  severity: 3,
  defect_type: 1,
  discovery_phase: 2,
})

const dialogTitle = computed(() => formMode.value === 'create' ? '登记缺陷' : '编辑缺陷')
const selectedRequirement = computed(() => (
  requirementOptions.value.find((item) => item.id === Number(form.requirement_id))
))
const projectOptions = computed(() => (
  (selectedRequirement.value?.projects ?? []).filter(Boolean)
))
const visibleProjects = computed(() => {
  const byId = new Map()
  defects.value.forEach((defect) => {
    if (defect.project) byId.set(defect.project.id, defect.project)
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
  if (severity.value !== '') params.severity = Number(severity.value)
  if (projectId.value !== '') params.project_id = Number(projectId.value)
  return params
}

function queryState() {
  const query = {}
  if (page.value !== 1) query.page = String(page.value)
  if (pageSize.value !== 20) query.page_size = String(pageSize.value)
  if (keyword.value.trim()) query.keyword = keyword.value.trim()
  if (status.value !== '') query.status = String(status.value)
  if (severity.value !== '') query.severity = String(severity.value)
  if (projectId.value !== '') query.project_id = String(projectId.value)
  return query
}

async function syncQuery() {
  await router.replace({ query: queryState() })
}

async function fetchDefects() {
  loading.value = true
  error.value = null
  try {
    const response = await listDefects(buildParams())
    const payload = response?.data?.data ?? {}
    const items = payload.items ?? []
    applyPagination(payload)
    const lastPage = Math.max(totalPages.value, 1)
    if (items.length === 0 && page.value > lastPage) {
      setPage(lastPage)
      await syncQuery()
      await fetchDefects()
      return
    }
    defects.value = items
  } catch (requestError) {
    defects.value = []
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
  await fetchDefects()
}

async function resetFilters() {
  keyword.value = ''
  status.value = ''
  severity.value = ''
  projectId.value = ''
  setPageSize(20)
  await router.replace({ query: {} })
  await fetchDefects()
}

async function handlePageChange(nextPage) {
  setPage(nextPage)
  await syncQuery()
  await fetchDefects()
}

async function handlePageSizeChange(nextPageSize) {
  setPageSize(nextPageSize)
  await syncQuery()
  await fetchDefects()
}

function resetForm() {
  Object.assign(form, {
    id: null,
    requirement_id: '',
    project_id: '',
    title: '',
    description: '',
    severity: 3,
    defect_type: 1,
    discovery_phase: 2,
  })
}

async function openCreate() {
  resetForm()
  formMode.value = 'create'
  dialogVisible.value = true
  if (requirementOptions.value.length === 0) await loadRequirements()
}

function openEdit(defect) {
  resetForm()
  formMode.value = 'edit'
  Object.assign(form, {
    id: defect.id,
    requirement_id: defect.requirement?.id ?? '',
    project_id: defect.project?.id ?? '',
    title: defect.title,
    description: defect.description ?? '',
    severity: defect.severity,
    defect_type: defect.defect_type,
    discovery_phase: defect.discovery_phase,
  })
  dialogVisible.value = true
}

function handleRequirementChange() {
  form.project_id = projectOptions.value.length === 1 ? projectOptions.value[0].id : ''
}

function formPayload() {
  const payload = {
    title: form.title.trim(),
    description: form.description.trim(),
    severity: Number(form.severity),
    defect_type: Number(form.defect_type),
  }
  if (formMode.value === 'create') {
    payload.requirement_id = Number(form.requirement_id)
    payload.project_id = Number(form.project_id)
    payload.discovery_phase = Number(form.discovery_phase)
  }
  return payload
}

async function saveDefect() {
  const payload = formPayload()
  if (!payload.title || !payload.description
    || (formMode.value === 'create' && (!payload.requirement_id || !payload.project_id))) {
    ElMessage.error('请填写标题、描述、所属需求和所属项目')
    return
  }

  saving.value = true
  try {
    if (formMode.value === 'create') {
      await createDefect(payload)
      ElMessage.success(`缺陷「${payload.title}」已登记`)
    } else {
      await updateDefect(form.id, payload)
      ElMessage.success(`缺陷「${payload.title}」已更新`)
    }
    dialogVisible.value = false
    await fetchDefects()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '缺陷保存失败')
  } finally {
    saving.value = false
  }
}

async function mutate(defect, request, successMessage) {
  busyId.value = defect.id
  try {
    await request()
    ElMessage.success(successMessage)
    await fetchDefects()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '操作失败')
  } finally {
    busyId.value = null
  }
}

async function handleConfirm(defect) {
  try {
    await ElMessageBox.confirm(
      `确认缺陷「${defect.title}」有效并进入待分配状态吗？`,
      '确认缺陷',
      { confirmButtonText: '确认有效', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(defect, () => confirmDefect(defect.id, {}), '缺陷已确认')
}

function handleAssign(defect) {
  assignmentRecord.value = defect
}

async function handleResolve(defect) {
  let description
  try {
    const result = await ElMessageBox.prompt(
      `请填写缺陷「${defect.title}」的修复说明`,
      '提交修复',
      {
        inputType: 'textarea',
        inputPattern: /\S+/,
        inputErrorMessage: '修复说明不能为空',
        confirmButtonText: '提交复测',
        cancelButtonText: '取消',
      },
    )
    description = result.value.trim()
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(
    defect,
    () => resolveDefect(defect.id, { fix_description: description }),
    '修复已提交复测',
  )
}

async function handleVerify(defect, result) {
  let comment
  try {
    const promptResult = await ElMessageBox.prompt(
      `请记录缺陷「${defect.title}」的复测${result === 'pass' ? '通过' : '失败'}说明`,
      '复测验证',
      {
        inputType: 'textarea',
        inputPattern: /\S+/,
        inputErrorMessage: '复测说明不能为空',
        confirmButtonText: result === 'pass' ? '确认通过' : '确认失败',
        cancelButtonText: '取消',
      },
    )
    comment = promptResult.value.trim()
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(
    defect,
    () => verifyDefect(defect.id, { result, comment }),
    result === 'pass' ? '缺陷复测通过' : '缺陷已退回修复',
  )
}

async function handleReopen(defect) {
  let reasonText
  try {
    const result = await ElMessageBox.prompt(
      `请填写重新打开缺陷「${defect.title}」的原因`,
      '重新打开缺陷',
      {
        inputType: 'textarea',
        inputPattern: /\S+/,
        inputErrorMessage: '重新打开原因不能为空',
        confirmButtonText: '重新打开',
        cancelButtonText: '取消',
      },
    )
    reasonText = result.value.trim()
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(
    defect,
    () => reopenDefect(defect.id, { reason: reasonText }),
    '缺陷已重新打开',
  )
}

function canUse(defect, action, localAllowed = true) {
  return canPerform(defect, action, localAllowed)
}

onMounted(() => {
  fetchDefects()
  loadRequirements()
})
</script>

<template>
  <div class="page-container queue-page">
    <header class="page-heading">
      <div>
        <h1 class="page-title">缺陷管理</h1>
        <p class="page-description">闭环跟踪缺陷确认、修复与复测</p>
      </div>
      <el-button v-if="canCreate('defect')" type="primary" :icon="Plus" @click="openCreate">
        登记缺陷
      </el-button>
    </header>

    <FilterBar :busy="loading" @search="applyFilters" @reset="resetFilters">
      <el-input
        v-model="keyword"
        class="filter-control filter-control--wide"
        clearable
        placeholder="搜索缺陷标题"
        @keyup.enter="applyFilters"
      />
      <el-select v-model="status" class="filter-control" clearable placeholder="全部状态">
        <el-option label="待确认" :value="1" />
        <el-option label="已确认" :value="2" />
        <el-option label="修复中" :value="3" />
        <el-option label="待复测" :value="4" />
        <el-option label="已关闭" :value="5" />
        <el-option label="重新打开" :value="6" />
      </el-select>
      <el-select v-model="severity" class="filter-control" clearable placeholder="全部严重程度">
        <el-option label="致命" :value="1" />
        <el-option label="严重" :value="2" />
        <el-option label="一般" :value="3" />
        <el-option label="轻微" :value="4" />
      </el-select>
      <el-select v-model="projectId" class="filter-control" clearable filterable placeholder="全部项目">
        <el-option v-for="project in visibleProjects" :key="project.id" :label="project.name" :value="project.id" />
      </el-select>
    </FilterBar>

    <PaginatedTable
      :loading="loading"
      :error="error"
      :rows="defects"
      :total="total"
      :page="page"
      :page-size="pageSize"
      empty-title="暂无缺陷"
      empty-description="当前筛选条件下没有可查看的缺陷"
      @retry="fetchDefects"
      @page-change="handlePageChange"
      @page-size-change="handlePageSizeChange"
    >
      <template #default="{ rows }">
        <table class="data-table">
          <thead>
            <tr>
              <th>缺陷</th>
              <th>项目 / 需求</th>
              <th>严重程度</th>
              <th>负责人</th>
              <th>状态</th>
              <th class="actions-column">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="defect in rows" :key="defect.id">
              <td>
                <span class="record-title">{{ defect.title }}</span>
                <span class="record-meta">BUG-{{ defect.id }} · {{ defect.reporter?.display_name || '-' }}</span>
              </td>
              <td class="wrap-cell">
                <span>{{ defect.project?.name || '-' }}</span>
                <span class="record-meta">{{ defect.requirement?.title || '-' }}</span>
              </td>
              <td>
                <span class="severity" :class="`severity--${defect.severity_code?.toLowerCase() || 'unknown'}`">
                  {{ defect.severity_label || '-' }}
                </span>
              </td>
              <td>{{ defect.assignee?.display_name || '未分配' }}</td>
              <td>
                <StatusTag :status-code="defect.status_code" :status-label="defect.status_label" />
              </td>
              <td>
                <div class="row-actions">
                  <el-tooltip v-if="canUse(defect, 'edit')" content="编辑缺陷">
                    <el-button
                      text
                      :icon="EditPen"
                      :data-testid="`edit-defect-${defect.id}`"
                      aria-label="编辑缺陷"
                      @click="openEdit(defect)"
                    />
                  </el-tooltip>
                  <el-button
                    v-if="canUse(defect, 'confirm')"
                    text
                    type="primary"
                    :icon="Check"
                    :loading="busyId === defect.id"
                    @click="handleConfirm(defect)"
                  >
                    确认
                  </el-button>
                  <el-button
                    v-if="canUse(defect, 'assign')"
                    text
                    :icon="User"
                    @click="handleAssign(defect)"
                  >
                    指派
                  </el-button>
                  <el-button
                    v-if="canUse(defect, 'resolve')"
                    text
                    type="primary"
                    @click="handleResolve(defect)"
                  >
                    提交修复
                  </el-button>
                  <template v-if="canUse(defect, 'verify')">
                    <el-button
                      text
                      type="success"
                      :data-testid="`verify-defect-${defect.id}`"
                      @click="handleVerify(defect, 'pass')"
                    >
                      复测通过
                    </el-button>
                    <el-button text type="danger" @click="handleVerify(defect, 'fail')">
                      复测失败
                    </el-button>
                  </template>
                  <el-button
                    v-if="canUse(defect, 'reopen')"
                    text
                    type="warning"
                    :icon="Refresh"
                    :data-testid="`reopen-defect-${defect.id}`"
                    @click="handleReopen(defect)"
                  >
                    重开
                  </el-button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </PaginatedTable>

    <AssignmentDialog :record="assignmentRecord" work-type="defect" @close="assignmentRecord = null" @assigned="fetchDefects" />
    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="min(680px, 94vw)" destroy-on-close>
      <el-form label-position="top">
        <div v-if="formMode === 'create'" class="form-grid">
          <el-form-item label="所属需求" required>
            <el-select
              v-model="form.requirement_id"
              filterable
              placeholder="选择需求"
              @change="handleRequirementChange"
            >
              <el-option
                v-for="requirement in requirementOptions"
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
        <el-form-item label="缺陷标题" required>
          <el-input v-model="form.title" maxlength="200" show-word-limit />
        </el-form-item>
        <el-form-item label="缺陷描述" required>
          <el-input v-model="form.description" type="textarea" :rows="5" maxlength="5000" show-word-limit />
        </el-form-item>
        <div class="form-grid">
          <el-form-item label="严重程度" required>
            <el-select v-model="form.severity">
              <el-option label="致命" :value="1" />
              <el-option label="严重" :value="2" />
              <el-option label="一般" :value="3" />
              <el-option label="轻微" :value="4" />
            </el-select>
          </el-form-item>
          <el-form-item label="缺陷类型" required>
            <el-select v-model="form.defect_type">
              <el-option label="功能缺陷" :value="1" />
              <el-option label="性能缺陷" :value="2" />
              <el-option label="安全缺陷" :value="3" />
              <el-option label="兼容性缺陷" :value="4" />
              <el-option label="其他" :value="5" />
            </el-select>
          </el-form-item>
          <el-form-item v-if="formMode === 'create'" label="发现阶段" required>
            <el-select v-model="form.discovery_phase">
              <el-option label="开发验证" :value="1" />
              <el-option label="测试验证" :value="2" />
            </el-select>
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveDefect">保存</el-button>
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
  width: 170px;

  &--wide {
    width: min(320px, 100%);
  }
}

.data-table {
  width: 100%;
  min-width: 1060px;
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
  width: 330px;
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
  max-width: 250px;
  white-space: normal;
}

.severity {
  font-weight: 600;

  &--critical {
    color: $color-danger;
  }

  &--high {
    color: #b45309;
  }

  &--medium {
    color: $color-primary;
  }

  &--low {
    color: $color-muted;
  }
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

  :deep(.el-select) {
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
