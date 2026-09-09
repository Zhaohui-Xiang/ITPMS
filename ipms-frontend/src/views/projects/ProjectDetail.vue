<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowRight, RefreshRight } from '@element-plus/icons-vue'
import { getProject } from '@/api/project'
import { listRequirements } from '@/api/requirement'
import { listDefects } from '@/api/defect'
import AsyncState from '@/components/common/AsyncState.vue'
import { mapApiError } from '@/composables/useApiError'

const route = useRoute()
const router = useRouter()
const projectId = computed(() => route.params.id)
const activeTab = computed(() => route.query.tab || 'overview')

const project = ref(null)
const requirements = ref([])
const defects = ref([])
const loading = ref(false)
const error = ref(null)

const systemTypeLabels = {
  1: '外部采购',
  2: '内部自研',
}

const versionStatusLabels = {
  DRAFT: '草稿',
  PLANNED: '已计划',
  IN_DEVELOPMENT: '开发中',
  IN_TESTING: '测试中',
  READY_TO_RELEASE: '待发布',
  RELEASED: '已发布',
  ARCHIVED: '已归档',
}

const versionStats = computed(() => {
  const counts = project.value?.version_counts?.by_status ?? {}
  return Object.entries(versionStatusLabels).map(([code, label]) => ({
    code,
    label,
    value: counts[code] ?? 0,
  }))
})

async function loadWorkspace() {
  loading.value = true
  error.value = null

  try {
    const [projectResponse, requirementResponse, defectResponse] = await Promise.all([
      getProject(projectId.value),
      listRequirements({ project_id: projectId.value, page: 1, page_size: 10 }),
      listDefects({ project_id: projectId.value, page: 1, page_size: 10 }),
    ])

    project.value = projectResponse?.data?.data ?? null
    requirements.value = requirementResponse?.data?.data?.items ?? []
    defects.value = defectResponse?.data?.data?.items ?? []
  } catch (requestError) {
    project.value = null
    requirements.value = []
    defects.value = []
    error.value = mapApiError(requestError)
    ElMessage.error(error.value.message)
  } finally {
    loading.value = false
  }
}

function selectTab(tab) {
  if (tab === 'versions') {
    router.push({
      name: 'ProjectVersions',
      params: { projectId: projectId.value },
    })
    return
  }

  router.replace({
    name: 'ProjectDetail',
    params: { id: projectId.value },
    query: tab === 'overview' ? {} : { tab },
  })
}

function openRequirement(requirement) {
  router.push(`/requirements/${requirement.id}`)
}

function openVersions() {
  selectTab('versions')
}

function openDefectQueue() {
  router.push({ path: '/defects', query: { project_id: projectId.value } })
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

onMounted(loadWorkspace)
</script>

<template>
  <div class="page-container project-workspace">
    <header class="workspace-heading">
      <div>
        <p class="workspace-kicker">项目工作区</p>
        <h1 class="page-title">{{ project?.name || '项目详情' }}</h1>
        <p class="page-description">{{ project?.description || '查看项目交付与发布状态' }}</p>
      </div>
      <el-button :icon="RefreshRight" :loading="loading" @click="loadWorkspace">
        刷新
      </el-button>
    </header>

    <nav class="workspace-tabs" aria-label="项目工作区">
      <button
        v-for="tab in [
          { key: 'overview', label: '项目概览' },
          { key: 'versions', label: '发布版本' },
          { key: 'requirements', label: '关联需求' },
          { key: 'defects', label: '近期缺陷' },
        ]"
        :key="tab.key"
        type="button"
        :class="{ 'is-active': activeTab === tab.key }"
        @click="selectTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && !project"
      empty-title="项目不存在"
      empty-description="当前项目不可用或不在你的权限范围内"
      @retry="loadWorkspace"
    >
      <section v-if="activeTab === 'overview'" class="overview-layout">
        <div class="project-facts">
          <div>
            <span>项目状态</span>
            <strong>{{ project?.status_label || '-' }}</strong>
          </div>
          <div>
            <span>系统类型</span>
            <strong>{{ systemTypeLabels[project?.system_type] || '-' }}</strong>
          </div>
          <div>
            <span>内部负责人</span>
            <strong>{{ project?.manager?.display_name || '-' }}</strong>
          </div>
          <div>
            <span>交付供应商</span>
            <strong>{{ project?.supplier_org?.name || '-' }}</strong>
          </div>
          <div>
            <span>创建时间</span>
            <strong>{{ formatDate(project?.created_at) }}</strong>
          </div>
        </div>

        <div class="metric-strip">
          <button type="button" @click="selectTab('requirements')">
            <span>关联需求</span>
            <strong>{{ project?.counts?.requirements ?? 0 }}</strong>
          </button>
          <div>
            <span>任务</span>
            <strong>{{ project?.counts?.tasks ?? 0 }}</strong>
          </div>
          <button type="button" @click="selectTab('defects')">
            <span>缺陷</span>
            <strong>{{ project?.counts?.defects ?? 0 }}</strong>
          </button>
          <button type="button" @click="openVersions">
            <span>发布版本</span>
            <strong>{{ project?.counts?.versions ?? 0 }}</strong>
          </button>
        </div>

        <section class="version-overview" aria-labelledby="version-overview-title">
          <div class="section-heading">
            <div>
              <h2 id="version-overview-title">版本状态分布</h2>
              <p>来自当前项目的实时版本统计</p>
            </div>
            <el-button type="primary" link :icon="ArrowRight" @click="openVersions">
              查看版本工作区
            </el-button>
          </div>
          <div class="version-stats">
            <div v-for="item in versionStats" :key="item.code">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
        </section>
      </section>

      <section v-else-if="activeTab === 'requirements'" class="data-band">
        <div class="section-heading">
          <div>
            <h2>关联需求</h2>
            <p>显示当前项目最近更新的需求</p>
          </div>
          <el-button type="primary" link @click="router.push({ path: '/requirements', query: { project_id: projectId } })">
            查看全部
          </el-button>
        </div>
        <div v-if="requirements.length" class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>需求</th>
                <th>状态</th>
                <th>优先级</th>
                <th>更新时间</th>
                <th class="action-column">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="requirement in requirements" :key="requirement.id">
                <td>{{ requirement.title }}</td>
                <td>{{ requirement.status_label || '-' }}</td>
                <td>{{ requirement.priority_label || requirement.priority || '-' }}</td>
                <td>{{ formatDate(requirement.updated_at) }}</td>
                <td class="action-column">
                  <el-button type="primary" link @click="openRequirement(requirement)">查看</el-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="inline-empty">当前项目暂无关联需求</p>
      </section>

      <section v-else-if="activeTab === 'defects'" class="data-band">
        <div class="section-heading">
          <div>
            <h2>近期缺陷</h2>
            <p>显示当前项目最近记录的缺陷</p>
          </div>
          <el-button type="primary" link @click="openDefectQueue">查看全部</el-button>
        </div>
        <div v-if="defects.length" class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>缺陷</th>
                <th>严重程度</th>
                <th>状态</th>
                <th>发现时间</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="defect in defects" :key="defect.id">
                <td>{{ defect.title }}</td>
                <td>{{ defect.severity_label || defect.severity || '-' }}</td>
                <td>{{ defect.status_label || '-' }}</td>
                <td>{{ formatDate(defect.discovered_at || defect.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="inline-empty">当前项目暂无缺陷记录</p>
      </section>
    </AsyncState>
  </div>
</template>

<style scoped lang="scss">
.project-workspace {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.workspace-heading,
.section-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
}

.workspace-kicker {
  margin-bottom: 5px;
  color: $color-primary;
  font-size: $font-size-caption;
  font-weight: 700;
}

.workspace-tabs {
  display: flex;
  gap: 24px;
  border-bottom: 1px solid $color-border;
  overflow-x: auto;

  button {
    position: relative;
    min-height: 42px;
    padding: 0 2px;
    border: 0;
    color: $color-muted;
    background: transparent;
    cursor: pointer;
    font: inherit;
    white-space: nowrap;

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

.overview-layout {
  display: grid;
  gap: 24px;
}

.project-facts {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  border-block: 1px solid $color-border;

  div {
    min-width: 0;
    padding: 16px 18px;
    border-right: 1px solid $color-border;

    &:last-child {
      border-right: 0;
    }
  }

  span,
  strong {
    display: block;
  }

  span {
    margin-bottom: 7px;
    color: $color-muted;
    font-size: $font-size-caption;
  }

  strong {
    color: $color-ink;
    font-size: $font-size-small;
    overflow-wrap: anywhere;
  }
}

.metric-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  border-bottom: 1px solid $color-border;

  div,
  button {
    min-height: 94px;
    padding: 16px 18px;
    border: 0;
    border-right: 1px solid $color-border;
    background: #fff;
    text-align: left;

    &:last-child {
      border-right: 0;
    }
  }

  button {
    cursor: pointer;

    &:hover {
      background: #f8fafc;
    }
  }

  span,
  strong {
    display: block;
  }

  span {
    color: $color-muted;
    font-size: $font-size-small;
  }

  strong {
    margin-top: 10px;
    color: $color-ink;
    font-size: 26px;
    line-height: 30px;
  }
}

.version-overview,
.data-band {
  padding-top: 4px;
}

.section-heading {
  align-items: center;
  margin-bottom: 14px;

  h2 {
    color: $color-ink;
    font-size: $font-size-h3;
  }

  p {
    margin-top: 4px;
    color: $color-muted;
    font-size: $font-size-small;
  }
}

.version-stats {
  display: grid;
  grid-template-columns: repeat(7, minmax(96px, 1fr));
  border-block: 1px solid $color-border;
  overflow-x: auto;

  div {
    min-width: 96px;
    padding: 15px 12px;
    border-right: 1px solid $color-border;

    &:last-child {
      border-right: 0;
    }
  }

  span,
  strong {
    display: block;
  }

  span {
    color: $color-muted;
    font-size: $font-size-caption;
  }

  strong {
    margin-top: 7px;
    color: $color-ink;
    font-size: $font-size-h3;
  }
}

.table-scroll {
  width: 100%;
  overflow-x: auto;

  table {
    width: 100%;
    min-width: 720px;
    border-collapse: collapse;
  }

  th,
  td {
    padding: 13px 12px;
    border-bottom: 1px solid $color-border;
    text-align: left;
    font-size: $font-size-small;
  }

  th {
    color: $color-muted;
    background: $color-canvas;
    font-size: $font-size-caption;
  }
}

.action-column {
  width: 84px;
  text-align: right !important;
}

.inline-empty {
  padding: 56px 20px;
  color: $color-muted;
  text-align: center;
}

@media (max-width: 1024px) {
  .project-facts {
    grid-template-columns: repeat(3, minmax(0, 1fr));

    div:nth-child(3) {
      border-right: 0;
    }
  }
}

@media (max-width: 720px) {
  .workspace-heading {
    flex-direction: column;
  }

  .project-facts,
  .metric-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));

    div,
    button {
      border-bottom: 1px solid $color-border;
    }
  }
}
</style>
