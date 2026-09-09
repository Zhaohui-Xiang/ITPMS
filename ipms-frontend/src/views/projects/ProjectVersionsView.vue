<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft, Plus, RefreshLeft, Search } from '@element-plus/icons-vue'
import { getProject } from '@/api/project'
import {
  createProjectVersion,
  listProjectVersions,
} from '@/api/projectVersion'
import AsyncState from '@/components/common/AsyncState.vue'
import VersionListPanel from '@/components/releases/VersionListPanel.vue'
import { mapApiError } from '@/composables/useApiError'
import { usePagination } from '@/composables/usePagination'

const route = useRoute()
const router = useRouter()
const projectId = computed(() => route.params.projectId)

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
} = usePagination()

const statusOptions = [
  { value: 1, label: '草稿' },
  { value: 2, label: '已计划' },
  { value: 3, label: '开发中' },
  { value: 4, label: '测试中' },
  { value: 5, label: '待发布' },
  { value: 6, label: '已发布' },
  { value: 7, label: '已归档' },
]

const project = ref(null)
const versions = ref([])
const loading = ref(false)
const error = ref(null)
const createVisible = ref(false)
const creating = ref(false)
const codeError = ref('')

const filters = reactive({
  keyword: '',
  status: '',
  ownerId: '',
  releaseRange: [],
})

const createForm = reactive({
  code: '',
  name: '',
  description: '',
  owner_id: null,
  planned_start_date: '',
  planned_release_date: '',
})

const canCreateVersion = computed(() => (
  project.value?.allowed_actions?.includes('create_version') ?? false
))

const ownerOptions = computed(() => {
  const candidates = [
    project.value?.manager,
    ...versions.value.map((version) => version.owner),
  ].filter(Boolean)
  const unique = new Map()

  candidates.forEach((owner) => unique.set(owner.id, owner))
  return [...unique.values()]
})

function buildParams() {
  const params = { ...requestParams.value }
  const keyword = filters.keyword.trim()

  if (keyword) params.keyword = keyword
  if (filters.status !== '') params.status = Number(filters.status)
  if (filters.ownerId !== '') params.owner_id = Number(filters.ownerId)
  if (filters.releaseRange?.length === 2) {
    params.planned_release_from = filters.releaseRange[0]
    params.planned_release_to = filters.releaseRange[1]
  }

  return params
}

async function fetchProject() {
  const response = await getProject(projectId.value)
  project.value = response?.data?.data ?? null

  if (createForm.owner_id === null && project.value?.manager?.id) {
    createForm.owner_id = project.value.manager.id
  }
}

async function fetchVersions() {
  const response = await listProjectVersions(projectId.value, buildParams())
  const payload = response?.data?.data ?? {}
  versions.value = payload.items ?? []
  applyPagination(payload)

  const lastPage = Math.max(totalPages.value, 1)
  if (versions.value.length === 0 && page.value > lastPage) {
    setPage(lastPage)
    await fetchVersions()
  }
}

async function loadWorkspace() {
  loading.value = true
  error.value = null

  try {
    await Promise.all([fetchProject(), fetchVersions()])
  } catch (requestError) {
    project.value = null
    versions.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  resetPage()
  loading.value = true
  error.value = null
  try {
    await fetchVersions()
  } catch (requestError) {
    versions.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.keyword = ''
  filters.status = ''
  filters.ownerId = ''
  filters.releaseRange = []
  applyFilters()
}

async function handlePageChange(nextPage) {
  setPage(nextPage)
  await applyPage()
}

async function handlePageSizeChange(nextPageSize) {
  setPageSize(nextPageSize)
  await applyPage()
}

async function applyPage() {
  loading.value = true
  error.value = null
  try {
    await fetchVersions()
  } catch (requestError) {
    versions.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

function resetCreateForm() {
  createForm.code = ''
  createForm.name = ''
  createForm.description = ''
  createForm.owner_id = project.value?.manager?.id ?? null
  createForm.planned_start_date = ''
  createForm.planned_release_date = ''
  codeError.value = ''
}

function openCreateDialog() {
  resetCreateForm()
  createVisible.value = true
}

async function submitCreate() {
  codeError.value = ''
  if (!createForm.code.trim() || !createForm.name.trim()) {
    ElMessage.error('请填写版本编号和名称')
    return
  }

  creating.value = true
  try {
    await createProjectVersion(projectId.value, {
      code: createForm.code.trim(),
      name: createForm.name.trim(),
      description: createForm.description.trim() || null,
      owner_id: createForm.owner_id,
      planned_start_date: createForm.planned_start_date || null,
      planned_release_date: createForm.planned_release_date || null,
    })
    ElMessage.success('发布版本已创建')
    createVisible.value = false
    resetPage()
    await fetchVersions()
  } catch (requestError) {
    if (requestError?.response?.data?.error_code === 'VERSION_CODE_EXISTS') {
      codeError.value = requestError.response.data.message || '项目内版本编号已存在'
      return
    }
    ElMessage.error(requestError?.response?.data?.message ?? '创建发布版本失败')
  } finally {
    creating.value = false
  }
}

function openVersion(version) {
  router.push(`/project-versions/${version.id}`)
}

function goBack() {
  router.push(`/projects/${projectId.value}`)
}

function goProjectTab(tab) {
  if (tab === 'versions') return
  router.push({
    name: 'ProjectDetail',
    params: { id: projectId.value },
    query: { tab },
  })
}

onMounted(loadWorkspace)
</script>

<template>
  <div class="page-container version-workspace">
    <header class="workspace-heading">
      <div class="heading-copy">
        <el-button class="back-button" link :icon="ArrowLeft" @click="goBack">
          返回项目
        </el-button>
        <h1 class="page-title">{{ project?.name || '项目' }} · 发布版本</h1>
        <p class="page-description">管理项目级版本、计划日期与发布准备情况</p>
      </div>
      <el-button
        v-if="canCreateVersion"
        type="primary"
        :icon="Plus"
        @click="openCreateDialog"
      >
        新建版本
      </el-button>
    </header>

    <nav class="workspace-tabs" aria-label="项目工作区">
      <button type="button" @click="goProjectTab('overview')">项目概览</button>
      <button type="button" class="is-active">发布版本</button>
      <button type="button" @click="goProjectTab('requirements')">关联需求</button>
      <button type="button" @click="goProjectTab('defects')">近期缺陷</button>
    </nav>

    <section class="filter-band" aria-label="版本筛选">
      <el-input
        v-model="filters.keyword"
        class="keyword-filter"
        clearable
        placeholder="搜索版本编号或名称"
        :prefix-icon="Search"
        @keyup.enter="applyFilters"
      />
      <el-select
        v-model="filters.status"
        clearable
        placeholder="全部状态"
        @change="applyFilters"
      >
        <el-option
          v-for="option in statusOptions"
          :key="option.value"
          :label="option.label"
          :value="option.value"
        />
      </el-select>
      <el-select
        v-model="filters.ownerId"
        clearable
        placeholder="全部负责人"
        @change="applyFilters"
      >
        <el-option
          v-for="owner in ownerOptions"
          :key="owner.id"
          :label="owner.display_name"
          :value="owner.id"
        />
      </el-select>
      <el-date-picker
        v-model="filters.releaseRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        range-separator="至"
        start-placeholder="发布日期起"
        end-placeholder="发布日期止"
        @change="applyFilters"
      />
      <el-button type="primary" :icon="Search" @click="applyFilters">查询</el-button>
      <el-tooltip content="清除全部筛选" placement="top">
        <el-button
          aria-label="重置筛选"
          :icon="RefreshLeft"
          @click="resetFilters"
        />
      </el-tooltip>
    </section>

    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && versions.length === 0"
      empty-title="暂无发布版本"
      empty-description="当前项目尚未创建符合条件的版本"
      @retry="loadWorkspace"
    >
      <section class="version-band" aria-label="发布版本列表">
        <div class="band-heading">
          <h2>版本记录</h2>
          <span>共 {{ total }} 个版本</span>
        </div>
        <VersionListPanel :versions="versions" @open="openVersion" />
        <el-pagination
          v-if="total > 0"
          background
          layout="total, sizes, prev, pager, next"
          :current-page="page"
          :page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          :total="total"
          @current-change="handlePageChange"
          @size-change="handlePageSizeChange"
        />
      </section>
    </AsyncState>

    <el-dialog
      v-model="createVisible"
      title="新建项目版本"
      width="min(620px, 92vw)"
      destroy-on-close
      @closed="resetCreateForm"
    >
      <el-form label-position="top">
        <div class="form-grid">
          <el-form-item label="版本编号" required :error="codeError">
            <el-input
              v-model="createForm.code"
              maxlength="50"
              placeholder="例如 2026.09"
              @input="codeError = ''"
            />
          </el-form-item>
          <el-form-item label="版本名称" required>
            <el-input
              v-model="createForm.name"
              maxlength="100"
              placeholder="输入版本名称"
            />
          </el-form-item>
          <el-form-item label="负责人">
            <el-select v-model="createForm.owner_id" placeholder="选择内部 IT 项目经理">
              <el-option
                v-for="owner in ownerOptions"
                :key="owner.id"
                :label="owner.display_name"
                :value="owner.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="计划开始日期">
            <el-date-picker
              v-model="createForm.planned_start_date"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="选择日期"
            />
          </el-form-item>
          <el-form-item label="计划发布日期">
            <el-date-picker
              v-model="createForm.planned_release_date"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="选择日期"
            />
          </el-form-item>
          <el-form-item class="description-field" label="版本说明">
            <el-input
              v-model="createForm.description"
              type="textarea"
              :rows="3"
              placeholder="说明本次版本目标与范围"
            />
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="submitCreate">
          创建版本
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.version-workspace {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.workspace-heading,
.band-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
}

.heading-copy {
  min-width: 0;
}

.back-button {
  margin: 0 0 8px -4px;
}

.workspace-tabs {
  display: flex;
  gap: 24px;
  border-bottom: 1px solid $color-border;

  button {
    position: relative;
    min-height: 42px;
    padding: 0 2px;
    border: 0;
    color: $color-muted;
    background: transparent;
    cursor: pointer;
    font: inherit;

    &.is-active {
      color: $color-primary;
      font-weight: 700;
    }

    &.is-active::after {
      position: absolute;
      right: 0;
      bottom: -1px;
      left: 0;
      height: 2px;
      background: $color-primary;
      content: '';
    }
  }
}

.filter-band {
  display: grid;
  grid-template-columns: minmax(210px, 1fr) 150px 170px minmax(270px, 1.2fr) auto auto;
  gap: 10px;
  align-items: center;
  padding: 14px 0;
  border-bottom: 1px solid $color-border;
}

.version-band {
  background: #fff;
}

.band-heading {
  align-items: center;
  padding: 0 0 12px;

  h2 {
    color: $color-ink;
    font-size: $font-size-h3;
  }

  span {
    color: $color-muted;
    font-size: $font-size-small;
  }
}

.el-pagination {
  justify-content: flex-end;
  padding-top: 16px;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.description-field {
  grid-column: 1 / -1;
}

:deep(.el-select),
:deep(.el-date-editor) {
  width: 100%;
}

@media (max-width: 1180px) {
  .filter-band {
    grid-template-columns: minmax(220px, 1fr) 150px 170px auto auto;

    :deep(.el-date-editor) {
      grid-column: 1 / span 2;
    }
  }
}

@media (max-width: 760px) {
  .workspace-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .workspace-tabs {
    gap: 16px;
    overflow-x: auto;
  }

  .filter-band,
  .form-grid {
    grid-template-columns: 1fr;
  }

  .filter-band :deep(.el-date-editor),
  .description-field {
    grid-column: auto;
  }
}
</style>
