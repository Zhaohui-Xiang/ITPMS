<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, FolderChecked, RefreshLeft, Search, View } from '@element-plus/icons-vue'
import { archiveProject, deleteProject, listProjects } from '@/api/project'
import AsyncState from '@/components/common/AsyncState.vue'
import { mapApiError } from '@/composables/useApiError'
import { usePagination } from '@/composables/usePagination'
import { usePermission } from '@/composables/usePermission'

const router = useRouter()
const { isSuperAdmin, canDelete, canPerform } = usePermission()
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

const projects = ref([])
const keyword = ref('')
const status = ref('')
const loading = ref(false)
const error = ref(null)
const busyProjectId = ref(null)

const statusTypes = {
  1: 'success',
  2: 'warning',
  3: 'info',
}

const systemTypeLabels = {
  1: '外部采购',
  2: '内部自研',
}

function buildParams() {
  const params = { ...requestParams.value }
  const normalizedKeyword = keyword.value.trim()

  if (normalizedKeyword) params.keyword = normalizedKeyword
  if (status.value !== '') params.status = Number(status.value)

  return params
}

async function fetchProjects() {
  loading.value = true
  error.value = null

  try {
    const response = await listProjects(buildParams())
    const payload = response?.data?.data ?? {}
    const items = payload.items ?? []
    applyPagination(payload)
    const lastPage = Math.max(totalPages.value, 1)

    if (items.length === 0 && page.value > lastPage) {
      setPage(lastPage)
      await fetchProjects()
      return
    }

    projects.value = items
  } catch (requestError) {
    projects.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  resetPage()
  fetchProjects()
}

function resetFilters() {
  keyword.value = ''
  status.value = ''
  resetPage()
  fetchProjects()
}

function handlePageChange(nextPage) {
  setPage(nextPage)
  fetchProjects()
}

function handlePageSizeChange(nextPageSize) {
  setPageSize(nextPageSize)
  fetchProjects()
}

function canArchiveProject(project) {
  return canPerform(project, 'archive', isSuperAdmin.value)
}

function canDeleteProject(project) {
  return canPerform(project, 'delete', canDelete('project'))
}

function openProject(project) {
  router.push(`/projects/${project.id}`)
}

async function handleArchive(project) {
  try {
    await ElMessageBox.confirm(
      `确定归档项目「${project.name}」吗？归档后不可恢复为活跃状态。`,
      '确认归档',
      {
        type: 'warning',
        confirmButtonText: '归档',
        cancelButtonText: '取消',
      },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }

  busyProjectId.value = project.id
  try {
    await archiveProject(project.id)
    ElMessage.success('项目已归档')
    await fetchProjects()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '项目归档失败')
  } finally {
    busyProjectId.value = null
  }
}

async function handleDelete(project) {
  try {
    await ElMessageBox.confirm(
      `确定删除项目「${project.name}」吗？此操作不可恢复。`,
      '确认删除',
      {
        type: 'warning',
        confirmButtonText: '删除',
        cancelButtonText: '取消',
      },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }

  busyProjectId.value = project.id
  try {
    await deleteProject(project.id)
    ElMessage.success('项目已删除')
    await fetchProjects()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '项目删除失败')
  } finally {
    busyProjectId.value = null
  }
}

function formatDate(value) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'

  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date)
}

onMounted(fetchProjects)
</script>

<template>
  <div class="page-container project-list-page">
    <header class="page-heading">
      <div>
        <h1 class="page-title">项目管理</h1>
        <p class="page-description">查看已授权项目及其交付与发布概况</p>
      </div>
      <span class="record-count">共 {{ total }} 个项目</span>
    </header>

    <section class="filter-band" aria-label="项目筛选">
      <el-input
        v-model="keyword"
        class="keyword-input"
        clearable
        placeholder="搜索项目名称"
        :prefix-icon="Search"
        @keyup.enter="applyFilters"
        @clear="applyFilters"
      />
      <el-select
        v-model="status"
        class="status-select"
        clearable
        placeholder="全部状态"
        @change="applyFilters"
      >
        <el-option label="活跃" :value="1" />
        <el-option label="维护中" :value="2" />
        <el-option label="已归档" :value="3" />
      </el-select>
      <el-button type="primary" :icon="Search" @click="applyFilters">查询</el-button>
      <el-button :icon="RefreshLeft" @click="resetFilters">重置</el-button>
    </section>

    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && projects.length === 0"
      empty-title="暂无项目"
      empty-description="当前权限范围内没有可查看的项目"
      @retry="fetchProjects"
    >
      <section class="table-band" aria-label="项目列表">
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th class="project-column">项目</th>
                <th>状态</th>
                <th>类型</th>
                <th>负责人</th>
                <th>供应商</th>
                <th class="number-column">需求</th>
                <th class="number-column">任务</th>
                <th class="number-column">缺陷</th>
                <th class="number-column">版本</th>
                <th>更新时间</th>
                <th class="action-column">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="project in projects" :key="project.id">
                <td>
                  <button
                    type="button"
                    class="project-link"
                    @click="openProject(project)"
                  >
                    <span class="project-name">{{ project.name }}</span>
                    <span class="project-description">{{ project.description || '暂无描述' }}</span>
                  </button>
                </td>
                <td>
                  <el-tag :type="statusTypes[project.status]" size="small" effect="plain">
                    {{ project.status_label || '-' }}
                  </el-tag>
                </td>
                <td>{{ systemTypeLabels[project.system_type] || '-' }}</td>
                <td>{{ project.manager?.display_name || '未分配' }}</td>
                <td>{{ project.supplier_org?.name || '-' }}</td>
                <td class="number-cell">{{ project.counts?.requirements ?? 0 }}</td>
                <td class="number-cell">{{ project.counts?.tasks ?? 0 }}</td>
                <td class="number-cell">{{ project.counts?.defects ?? 0 }}</td>
                <td class="number-cell">{{ project.counts?.versions ?? 0 }}</td>
                <td>{{ formatDate(project.updated_at) }}</td>
                <td>
                  <div class="row-actions">
                    <el-tooltip content="查看项目">
                      <el-button
                        text
                        :icon="View"
                        :aria-label="`查看项目 ${project.name}`"
                        @click="openProject(project)"
                      />
                    </el-tooltip>
                    <el-tooltip v-if="canArchiveProject(project)" content="归档项目">
                      <el-button
                        text
                        :icon="FolderChecked"
                        :loading="busyProjectId === project.id"
                        :data-testid="`archive-${project.id}`"
                        :aria-label="`归档项目 ${project.name}`"
                        @click="handleArchive(project)"
                      />
                    </el-tooltip>
                    <el-tooltip v-if="canDeleteProject(project)" content="删除项目">
                      <el-button
                        text
                        type="danger"
                        :icon="Delete"
                        :loading="busyProjectId === project.id"
                        :data-testid="`delete-${project.id}`"
                        :aria-label="`删除项目 ${project.name}`"
                        @click="handleDelete(project)"
                      />
                    </el-tooltip>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <el-pagination
          v-if="total > pageSize"
          class="project-pagination"
          :current-page="page"
          :page-size="pageSize"
          :page-sizes="[20, 50, 100]"
          :total="total"
          layout="total, sizes, prev, pager, next"
          @current-change="handlePageChange"
          @size-change="handlePageSizeChange"
        />
      </section>
    </AsyncState>
  </div>
</template>

<style scoped lang="scss">
.project-list-page {
  max-width: $content-max-width;
  margin: 0 auto;
  padding: 0 24px 24px;
}

.page-heading {
  display: flex;
  min-height: 72px;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
}

.page-title {
  margin: 0;
  color: $color-ink;
  font-size: $font-size-h1;
  font-weight: 600;
}

.page-description {
  margin: 5px 0 0;
  color: $color-muted;
  font-size: $font-size-small;
}

.record-count {
  flex: 0 0 auto;
  color: $color-muted;
  font-size: $font-size-small;
}

.filter-band {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 0;
  border-top: 1px solid $color-border;
  border-bottom: 1px solid $color-border;
}

.keyword-input {
  width: 320px;
}

.status-select {
  width: 150px;
}

.table-band {
  background: #fff;
  border-bottom: 1px solid $color-border;
}

.table-scroll {
  overflow-x: auto;
}

table {
  width: 100%;
  min-width: 1080px;
  border-collapse: collapse;
  table-layout: fixed;
}

th,
td {
  height: 52px;
  padding: 8px 10px;
  border-bottom: 1px solid $color-border;
  color: $color-ink;
  font-size: $font-size-small;
  text-align: left;
  vertical-align: middle;
}

th {
  height: 42px;
  background: $color-canvas;
  color: $color-muted;
  font-weight: 600;
}

tbody tr:hover {
  background: #f8fafc;
}

.project-column {
  width: 230px;
}

.number-column {
  width: 58px;
  text-align: center;
}

.number-cell {
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.action-column {
  width: 132px;
}

.project-link {
  width: 100%;
  padding: 0;
  overflow: hidden;
  border: 0;
  background: transparent;
  color: inherit;
  cursor: pointer;
  text-align: left;
}

.project-name,
.project-description {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.project-name {
  color: $color-primary;
  font-weight: 600;
}

.project-description {
  margin-top: 4px;
  color: $color-muted;
  font-size: $font-size-caption;
}

.row-actions {
  display: flex;
  min-width: 116px;
  align-items: center;
  gap: 2px;

  :deep(.el-button) {
    width: 36px;
    height: 36px;
    margin: 0;
  }
}

.project-pagination {
  justify-content: flex-end;
  padding: 16px 0;
}

@media (max-width: 1100px) {
  .project-list-page {
    padding-right: 16px;
    padding-left: 16px;
  }

  .keyword-input {
    width: 260px;
  }
}
</style>
