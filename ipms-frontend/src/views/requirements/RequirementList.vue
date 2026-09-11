<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { EditPen, Plus, Promotion, View } from '@element-plus/icons-vue'
import { listProjects, listRequirementProjectOptions } from '@/api/project'
import {
  createRequirement,
  listRequirements,
  reviewRequirement,
  updateRequirement,
} from '@/api/requirement'
import { allPages } from '@/api/allPages'
import FilterBar from '@/components/common/FilterBar.vue'
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

const requirements = ref([])
const projects = ref([])
const submissionProjects = ref([])
const projectOptionsError = ref('')
const projectOptionsLoading = ref(false)
async function loadSubmissionProjects() {
  projectOptionsLoading.value = true
  projectOptionsError.value = ''
  try {
    submissionProjects.value = await allPages(listRequirementProjectOptions)
  } catch (failure) {
    projectOptionsError.value = mapApiError(failure).message
  } finally {
    projectOptionsLoading.value = false
  }
}
const keyword = ref(String(route.query.keyword ?? ''))
const status = ref(route.query.status ? Number(route.query.status) : '')
const priority = ref(route.query.priority ? Number(route.query.priority) : '')
const projectId = ref(route.query.project_id ? Number(route.query.project_id) : '')
const loading = ref(false)
const error = ref(null)
const busyId = ref(null)
const dialogVisible = ref(false)
const formMode = ref('create')
const editingRequirement = ref(null)
const canEditProjectScope = computed(() => formMode.value === 'create'
  || editingRequirement.value?.can_edit_project_scope === true)
const saving = ref(false)
const form = reactive({
  id: null,
  title: '',
  description: '',
  priority: 2,
  requirement_type: 1,
  expected_completion_date: '',
  project_ids: [],
})

const dialogTitle = computed(() => formMode.value === 'create' ? '新建需求' : '编辑需求')

function buildParams() {
  const params = { ...requestParams.value }
  const normalizedKeyword = keyword.value.trim()
  if (normalizedKeyword) params.keyword = normalizedKeyword
  if (status.value !== '') params.status = Number(status.value)
  if (priority.value !== '') params.priority = Number(priority.value)
  if (projectId.value !== '') params.project_id = Number(projectId.value)
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
  return query
}

async function syncQuery() {
  await router.replace({ query: queryState() })
}

async function fetchRequirements() {
  loading.value = true
  error.value = null
  try {
    const response = await listRequirements(buildParams())
    const payload = response?.data?.data ?? {}
    const items = payload.items ?? []
    applyPagination(payload)
    const lastPage = Math.max(totalPages.value, 1)
    if (items.length === 0 && page.value > lastPage) {
      setPage(lastPage)
      await syncQuery()
      await fetchRequirements()
      return
    }
    requirements.value = items
  } catch (requestError) {
    requirements.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

async function loadProjects() {
  try {
    projects.value = await allPages(listProjects)
  } catch {
    projects.value = []
  }
}

async function applyFilters() {
  resetPage()
  await syncQuery()
  await fetchRequirements()
}

async function resetFilters() {
  keyword.value = ''
  status.value = ''
  priority.value = ''
  projectId.value = ''
  setPageSize(20)
  await router.replace({ query: {} })
  await fetchRequirements()
}

async function handlePageChange(nextPage) {
  setPage(nextPage)
  await syncQuery()
  await fetchRequirements()
}

async function handlePageSizeChange(nextPageSize) {
  setPageSize(nextPageSize)
  await syncQuery()
  await fetchRequirements()
}

function resetForm() {
  Object.assign(form, {
    id: null,
    title: '',
    description: '',
    priority: 2,
    requirement_type: 1,
    expected_completion_date: '',
    project_ids: [],
  })
}

async function openCreate() {
  resetForm()
  formMode.value = 'create'
  dialogVisible.value = true
  await loadSubmissionProjects()
}

async function openEdit(requirement) {
  resetForm()
  formMode.value = 'edit'
  editingRequirement.value = requirement
  Object.assign(form, {
    id: requirement.id,
    title: requirement.title,
    description: requirement.description ?? '',
    priority: requirement.priority,
    requirement_type: requirement.requirement_type,
    expected_completion_date: requirement.expected_completion_date ?? '',
    project_ids: (requirement.projects ?? []).map((project) => project.id),
  })
  dialogVisible.value = true
  projectOptionsError.value = ''
  submissionProjects.value = requirement.projects ?? []
  if (canEditProjectScope.value) await loadSubmissionProjects()
}

function formPayload() {
  return {
    title: form.title.trim(),
    description: form.description.trim(),
    priority: Number(form.priority),
    requirement_type: Number(form.requirement_type),
    expected_completion_date: form.expected_completion_date || null,
    ...(canEditProjectScope.value ? { project_ids: form.project_ids.map(Number) } : {}),
  }
}

async function saveRequirement() {
  const payload = formPayload()
  if (!payload.title || !payload.description
    || (canEditProjectScope.value && payload.project_ids.length === 0)) {
    ElMessage.error('请填写标题、描述并至少选择一个项目')
    return
  }

  saving.value = true
  try {
    if (formMode.value === 'create') {
      await createRequirement(payload)
      ElMessage.success(`需求「${payload.title}」已提交`)
    } else {
      await updateRequirement(form.id, payload)
      ElMessage.success(`需求「${payload.title}」已更新`)
    }
    dialogVisible.value = false
    await fetchRequirements()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '需求保存失败')
  } finally {
    saving.value = false
  }
}

async function approve(requirement) {
  try {
    await ElMessageBox.confirm(
      `确认通过需求「${requirement.title}」吗？`,
      '审核需求',
      {
        type: 'warning',
        confirmButtonText: '通过',
        cancelButtonText: '取消',
      },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }

  await mutate(requirement, () => reviewRequirement(requirement.id, { action: 'approve' }), '需求已通过')
}

async function reject(requirement) {
  let comment
  try {
    const result = await ElMessageBox.prompt(
      `请填写需求「${requirement.title}」的驳回原因`,
      '驳回需求',
      {
        inputType: 'textarea',
        inputPattern: /\S+/,
        inputErrorMessage: '驳回原因不能为空',
        confirmButtonText: '确认驳回',
        cancelButtonText: '取消',
      },
    )
    comment = result.value.trim()
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }

  await mutate(
    requirement,
    () => reviewRequirement(requirement.id, { action: 'reject', comment }),
    '需求已驳回',
  )
}

async function mutate(requirement, request, successMessage) {
  busyId.value = requirement.id
  try {
    await request()
    ElMessage.success(successMessage)
    await fetchRequirements()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '操作失败')
  } finally {
    busyId.value = null
  }
}

function openDetail(requirement) {
  router.push(`/requirements/${requirement.id}`)
}

function projectNames(requirement) {
  return (requirement.projects ?? []).map((project) => project.name).join('、') || '-'
}

function canUse(requirement, action, localAllowed = true) {
  return canPerform(requirement, action, localAllowed)
}

onMounted(() => {
  fetchRequirements()
  loadProjects()
})
</script>

<template>
  <div class="page-container queue-page">
    <header class="page-heading">
      <div>
        <h1 class="page-title">需求管理</h1>
        <p class="page-description">集中处理需求提交、审核与跨项目交付</p>
      </div>
      <el-button v-if="canCreate('requirement')" type="primary" :icon="Plus" @click="openCreate">
        新建需求
      </el-button>
    </header>

    <FilterBar :busy="loading" @search="applyFilters" @reset="resetFilters">
      <el-input
        v-model="keyword"
        class="filter-control filter-control--wide"
        clearable
        placeholder="搜索需求标题"
        @keyup.enter="applyFilters"
      />
      <el-select v-model="status" class="filter-control" clearable placeholder="全部状态">
        <el-option label="待审核" :value="1" />
        <el-option label="已分配" :value="2" />
        <el-option label="开发中" :value="3" />
        <el-option label="测试中" :value="4" />
        <el-option label="待上线" :value="5" />
        <el-option label="已上线" :value="6" />
        <el-option label="已验收" :value="7" />
      </el-select>
      <el-select v-model="priority" class="filter-control" clearable placeholder="全部优先级">
        <el-option label="紧急" :value="1" />
        <el-option label="高" :value="2" />
        <el-option label="中" :value="3" />
        <el-option label="低" :value="4" />
      </el-select>
      <el-select v-model="projectId" class="filter-control" clearable filterable placeholder="全部项目">
        <el-option v-for="project in projects" :key="project.id" :label="project.name" :value="project.id" />
      </el-select>
    </FilterBar>

    <PaginatedTable
      :loading="loading"
      :error="error"
      :rows="requirements"
      :total="total"
      :page="page"
      :page-size="pageSize"
      empty-title="暂无需求"
      empty-description="当前筛选条件下没有可查看的需求"
      @retry="fetchRequirements"
      @page-change="handlePageChange"
      @page-size-change="handlePageSizeChange"
    >
      <template #default="{ rows }">
        <table class="data-table">
          <thead>
            <tr>
              <th>需求</th>
              <th>关联项目</th>
              <th>优先级</th>
              <th>状态</th>
              <th>提交人</th>
              <th class="actions-column">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="requirement in rows" :key="requirement.id">
              <td>
                <button class="record-link" type="button" @click="openDetail(requirement)">
                  {{ requirement.title }}
                </button>
                <span class="record-meta">REQ-{{ requirement.id }}</span>
              </td>
              <td class="wrap-cell">{{ projectNames(requirement) }}</td>
              <td>{{ requirement.priority_label || '-' }}</td>
              <td>
                <StatusTag
                  :status-code="requirement.status_code"
                  :status-label="requirement.status_label"
                />
              </td>
              <td>{{ requirement.submitter?.display_name || '-' }}</td>
              <td>
                <div class="row-actions">
                  <el-tooltip content="查看详情">
                    <el-button text :icon="View" aria-label="查看需求" @click="openDetail(requirement)" />
                  </el-tooltip>
                  <el-tooltip v-if="canUse(requirement, 'edit')" content="编辑需求">
                    <el-button
                      text
                      :icon="EditPen"
                      :data-testid="`edit-requirement-${requirement.id}`"
                      aria-label="编辑需求"
                      @click="openEdit(requirement)"
                    />
                  </el-tooltip>
                  <template v-if="canUse(requirement, 'review')">
                    <el-button
                      text
                      type="success"
                      :loading="busyId === requirement.id"
                      :data-testid="`review-requirement-${requirement.id}`"
                      @click="approve(requirement)"
                    >
                      通过
                    </el-button>
                    <el-button text type="danger" @click="reject(requirement)">驳回</el-button>
                  </template>
                  <el-tooltip v-if="canUse(requirement, 'transition_project')" content="进入项目交付">
                    <el-button
                      text
                      :icon="Promotion"
                      aria-label="项目交付"
                      @click="openDetail(requirement)"
                    />
                  </el-tooltip>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </PaginatedTable>

    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="min(640px, 94vw)" destroy-on-close>
      <el-alert v-if="projectOptionsError" :title="projectOptionsError" type="error" :closable="false">
        <el-button text @click="loadSubmissionProjects">重试</el-button>
      </el-alert>
      <p v-if="formMode === 'edit' && editingRequirement?.status_code === 'PENDING_REVIEW' && editingRequirement?.review_comment" class="review-comment">
        驳回原因：{{ editingRequirement.review_comment }}。保存后将重新送审。
      </p>
      <el-form label-position="top">
        <el-form-item label="需求标题" required>
          <el-input v-model="form.title" maxlength="200" show-word-limit />
        </el-form-item>
        <el-form-item label="需求描述" required>
          <el-input v-model="form.description" type="textarea" :rows="5" />
        </el-form-item>
        <div class="form-grid">
          <el-form-item label="优先级" required>
            <el-select v-model="form.priority">
              <el-option label="紧急" :value="1" />
              <el-option label="高" :value="2" />
              <el-option label="中" :value="3" />
              <el-option label="低" :value="4" />
            </el-select>
          </el-form-item>
          <el-form-item label="需求类型" required>
            <el-select v-model="form.requirement_type">
              <el-option label="功能需求" :value="1" />
              <el-option label="优化需求" :value="2" />
              <el-option label="数据需求" :value="3" />
              <el-option label="接口需求" :value="4" />
              <el-option label="其他" :value="5" />
            </el-select>
          </el-form-item>
          <el-form-item label="期望完成日期">
            <el-date-picker
              v-model="form.expected_completion_date"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="选择日期"
            />
          </el-form-item>
          <el-form-item label="关联项目" required>
            <el-select v-model="form.project_ids" multiple filterable :disabled="!canEditProjectScope" :loading="projectOptionsLoading" placeholder="选择项目">
              <el-option
                v-for="project in submissionProjects"
                :key="project.id"
                :label="project.name"
                :value="project.id"
              />
            </el-select>
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" :disabled="projectOptionsLoading || Boolean(projectOptionsError)" @click="saveRequirement">保存</el-button>
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
  min-width: 940px;
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
  width: 230px;
}

.record-link {
  display: block;
  padding: 0;
  border: 0;
  background: transparent;
  color: $color-primary;
  cursor: pointer;
  font: inherit;
  font-weight: 600;
  letter-spacing: 0;
  text-align: left;
}

.record-meta {
  display: block;
  margin-top: 4px;
  color: $color-muted;
  font-size: $font-size-caption;
}

.wrap-cell {
  max-width: 260px;
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
  :deep(.el-date-editor) {
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
