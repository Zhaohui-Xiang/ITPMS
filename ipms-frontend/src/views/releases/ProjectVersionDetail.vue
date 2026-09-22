<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  ArrowLeft,
  Edit,
  Refresh,
  UploadFilled,
  VideoPlay,
  WarningFilled,
} from '@element-plus/icons-vue'
import {
  checkProjectVersionGate,
  getProjectVersion,
  listProjectVersionHistory,
  releaseProjectVersion,
  transitionProjectVersion,
  updateProjectVersion,
} from '@/api/projectVersion'
import AsyncState from '@/components/common/AsyncState.vue'
import ReleaseDialog from '@/components/releases/ReleaseDialog.vue'
import ReleaseGatePanel from '@/components/releases/ReleaseGatePanel.vue'
import RequirementPlanner from '@/components/releases/RequirementPlanner.vue'
import VersionEditDialog from '@/components/releases/VersionEditDialog.vue'
import VersionHistory from '@/components/releases/VersionHistory.vue'
import VersionProgress from '@/components/releases/VersionProgress.vue'
import VersionStatusTag from '@/components/releases/VersionStatusTag.vue'
import { mapApiError } from '@/composables/useApiError'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const statusDefinitions = [
  { value: 1, code: 'DRAFT', label: '草稿' },
  { value: 2, code: 'PLANNED', label: '已计划' },
  { value: 3, code: 'IN_DEVELOPMENT', label: '开发中' },
  { value: 4, code: 'IN_TESTING', label: '测试中' },
  { value: 5, code: 'READY_TO_RELEASE', label: '待发布' },
  { value: 6, code: 'RELEASED', label: '已发布' },
  { value: 7, code: 'ARCHIVED', label: '已归档' },
]

const transitionMap = {
  DRAFT: ['PLANNED'],
  PLANNED: ['DRAFT', 'IN_DEVELOPMENT'],
  IN_DEVELOPMENT: ['PLANNED', 'IN_TESTING'],
  IN_TESTING: ['IN_DEVELOPMENT', 'READY_TO_RELEASE'],
  READY_TO_RELEASE: ['IN_TESTING'],
  RELEASED: ['ARCHIVED'],
  ARCHIVED: [],
}

const version = ref(null)
const gateResult = ref(null)
const historyItems = ref([])
const loading = ref(false)
const commandBusy = ref(false)
const scopeBusy = ref(false)
const error = ref(null)
const activeTab = ref('overview')
const commandVisible = ref(false)
const commandMode = ref('transition')
const commandTarget = ref(null)
const editVisible = ref(false)
const editBusy = ref(false)
const editError = ref(null)
const editStale = ref(false)

const workspaceBusy = computed(() => (
  loading.value || scopeBusy.value || commandBusy.value || editBusy.value
))
const workspaceBlocked = computed(() => (
  workspaceBusy.value || editVisible.value || commandVisible.value
))

const versionId = computed(() => route.params.id)
const allowedActions = computed(() => version.value?.allowed_actions ?? [])
const transitionTargets = computed(() => {
  const codes = transitionMap[version.value?.status_code] ?? []
  return statusDefinitions.filter((status) => codes.includes(status.code))
})
const isReadOnly = computed(() => ['RELEASED', 'ARCHIVED'].includes(
  version.value?.status_code,
))
const canEdit = computed(() => (
  ['DRAFT', 'PLANNED', 'IN_DEVELOPMENT', 'IN_TESTING'].includes(version.value?.status_code)
  && allowedActions.value.includes('edit')
))
const canTransition = computed(() => (
  allowedActions.value.includes('transition')
  && transitionTargets.value.length > 0
))
const canRelease = computed(() => (
  version.value?.status_code === 'READY_TO_RELEASE'
  && allowedActions.value.includes('release')
))
const canForceRelease = computed(() => (
  authStore.isSuperAdmin
  && ['IN_TESTING', 'READY_TO_RELEASE'].includes(version.value?.status_code)
  && allowedActions.value.includes('force_release')
))
const currentGateResult = computed(() => {
  if (['RELEASED', 'ARCHIVED'].includes(version.value?.status_code)) {
    return version.value?.release_snapshot?.gate_result
      ?? gateResult.value
      ?? version.value?.gate_result
  }
  return gateResult.value ?? version.value?.gate_result
})
const currentCounts = computed(() => {
  const snapshot = version.value?.release_snapshot
  if (snapshot && ['RELEASED', 'ARCHIVED'].includes(version.value?.status_code)) {
    return {
      requirements: snapshot.requirement_scope?.length ?? 0,
      tasks: snapshot.task_count ?? 0,
      defects: snapshot.defect_count ?? 0,
    }
  }
  return version.value?.counts ?? {}
})

async function loadAllHistory() {
  const firstResponse = await listProjectVersionHistory(versionId.value, {
    page: 1,
    page_size: 100,
  })
  const firstPage = firstResponse?.data?.data ?? {}
  const items = [...(firstPage.items ?? [])]
  const totalPages = Number(firstPage.total_pages ?? 1)

  if (totalPages <= 1) return items

  const remainingResponses = await Promise.all(
    Array.from({ length: totalPages - 1 }, (_, index) => (
      listProjectVersionHistory(versionId.value, {
        page: index + 2,
        page_size: 100,
      })
    )),
  )

  remainingResponses.forEach((response) => {
    items.push(...(response?.data?.data?.items ?? []))
  })

  return items
}

async function loadWorkspace() {
  loading.value = true
  error.value = null

  try {
    const detailResponse = await getProjectVersion(versionId.value)
    version.value = detailResponse?.data?.data ?? null
    editStale.value = false
    gateResult.value = version.value?.gate_result ?? null
    historyItems.value = version.value?.history ?? []

    const [gateResponse, historyResponse] = await Promise.allSettled([
      checkProjectVersionGate(versionId.value),
      loadAllHistory(),
    ])

    if (gateResponse.status === 'fulfilled') {
      gateResult.value = gateResponse.value?.data?.data ?? gateResult.value
    }
    if (historyResponse.status === 'fulfilled') {
      historyItems.value = historyResponse.value
    }
  } catch (requestError) {
    version.value = null
    gateResult.value = null
    historyItems.value = []
    error.value = mapApiError(requestError)
  } finally {
    loading.value = false
  }
}

async function refreshWorkspace() {
  if (workspaceBlocked.value) return
  await loadWorkspace()
}

function openEdit() {
  if (!canEdit.value || workspaceBlocked.value) return
  if (!editStale.value) editError.value = null
  editVisible.value = true
}

async function handleEdit(payload) {
  if (!editVisible.value || !canEdit.value || workspaceBusy.value || commandVisible.value || editStale.value) return
  editBusy.value = true
  editError.value = null

  try {
    await updateProjectVersion(versionId.value, payload)
    editVisible.value = false
    ElMessage.success('版本信息已更新')
    await loadWorkspace()
  } catch (requestError) {
    editError.value = mapApiError(requestError)
    editStale.value = editError.value.requiresReload || editError.value.status === 409
  } finally {
    editBusy.value = false
  }
}

async function reloadEditVersion() {
  if (!editVisible.value || workspaceBusy.value || commandVisible.value || !editStale.value) return
  editBusy.value = true

  try {
    const response = await getProjectVersion(versionId.value)
    const latest = response?.data?.data
    if (!latest) throw new Error('无法读取最新版本，请重试')
    version.value = latest
    gateResult.value = latest.gate_result ?? null
    historyItems.value = latest.history ?? []
    editStale.value = false
    editError.value = null
  } catch (requestError) {
    // A failed reload must not discard the draft or unlock another stale save.
    editError.value = mapApiError(requestError)
  } finally {
    editBusy.value = false
  }
}

function openTransition(target) {
  if (workspaceBlocked.value || !canTransition.value
    || !transitionTargets.value.some((status) => status.code === target?.code)) return

  commandMode.value = 'transition'
  commandTarget.value = target
  commandVisible.value = true
}

function openRelease(force = false) {
  if (workspaceBlocked.value || !(force ? canForceRelease.value : canRelease.value)) return

  commandMode.value = force ? 'force' : 'release'
  commandTarget.value = null
  commandVisible.value = true
}

async function refreshGate() {
  try {
    const response = await checkProjectVersionGate(versionId.value)
    gateResult.value = response?.data?.data ?? gateResult.value
  } catch {
    // The detail response remains the fallback if this optional refresh fails.
  }
}

async function handleCommand(payload) {
  if (!commandVisible.value || workspaceBusy.value || editVisible.value) return
  if (commandMode.value === 'transition' && !canTransition.value) return
  if (commandMode.value === 'release' && !canRelease.value) return
  if (commandMode.value === 'force' && !canForceRelease.value) return
  commandBusy.value = true

  try {
    if (commandMode.value === 'transition') {
      await transitionProjectVersion(versionId.value, payload)
      ElMessage.success('版本状态已更新')
    } else {
      await releaseProjectVersion(versionId.value, payload)
      ElMessage.success(commandMode.value === 'force' ? '版本已强制发布' : '版本已发布')
    }

    commandVisible.value = false
    await loadWorkspace()
  } catch (requestError) {
    const mapped = mapApiError(requestError)

    if (mapped.requiresReload) {
      commandVisible.value = false
      await loadWorkspace()
      ElMessage.warning(mapped.message)
      return
    }

    if (mapped.targetTab === 'release-gate') {
      activeTab.value = 'release-gate'
      gateResult.value = {
        passed: false,
        checks: mapped.blockers,
        blocking: mapped.blockers,
      }
      await refreshGate()
      ElMessage.error(mapped.message)
      return
    }

    ElMessage.error(mapped.message)
  } finally {
    commandBusy.value = false
  }
}

function handleScopeBusy(busy) {
  scopeBusy.value = busy
}

async function handleScopeChanged() {
  await loadWorkspace()
}

async function handleScopeStale(message) {
  await loadWorkspace()
  ElMessage.warning(message || '版本数据已更新，请重试')
}

function goBack() {
  const projectId = version.value?.project?.id
  if (projectId) {
    router.push(`/projects/${projectId}/versions`)
    return
  }
  router.push('/projects')
}

onMounted(loadWorkspace)
</script>

<template>
  <div
    class="page-container version-detail"
    :data-active-tab="activeTab"
    :data-status-code="version?.status_code"
  >
    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && !version"
      empty-title="版本不存在"
      empty-description="无法读取该项目版本"
      @retry="refreshWorkspace"
    >
      <template v-if="version">
        <header class="detail-heading">
          <div class="heading-copy">
            <el-button link :icon="ArrowLeft" @click="goBack">
              返回版本列表
            </el-button>
            <div class="version-title-line">
              <h1>{{ version.code }} · {{ version.name }}</h1>
              <VersionStatusTag
                :status-code="version.status_code"
                :status-label="version.status_label"
              />
            </div>
            <p>{{ version.project?.name }} · 负责人 {{ version.owner?.display_name || '-' }}</p>
          </div>

          <div class="command-bar" aria-label="版本操作">
            <el-button
              :icon="Refresh"
              aria-label="刷新版本数据"
              :disabled="workspaceBlocked"
              @click="refreshWorkspace"
            />
            <el-button
              v-if="canEdit"
              data-testid="edit-version-command"
              :icon="Edit"
              :disabled="workspaceBlocked"
              @click="openEdit"
            >
              编辑版本信息
            </el-button>
            <el-button
              v-for="target in canTransition ? transitionTargets : []"
              :key="target.code"
              data-testid="transition-command"
              :icon="VideoPlay"
              :disabled="workspaceBlocked"
              @click="openTransition(target)"
            >
              {{ target.value < version.status ? `回退至${target.label}` : `推进至${target.label}` }}
            </el-button>
            <el-button
              v-if="canRelease"
              data-testid="release-command"
              type="primary"
              :icon="UploadFilled"
              :disabled="workspaceBlocked"
              @click="openRelease(false)"
            >
              正式发布
            </el-button>
            <el-button
              v-if="canForceRelease"
              data-testid="force-release-command"
              type="danger"
              :icon="WarningFilled"
              :disabled="workspaceBlocked"
              @click="openRelease(true)"
            >
              强制发布
            </el-button>
            <span v-if="isReadOnly" class="readonly-status">版本只读</span>
          </div>
        </header>

        <section class="progress-band" aria-label="版本状态">
          <VersionProgress :status-code="version.status_code" />
        </section>

        <el-tabs v-model="activeTab" class="version-tabs">
          <el-tab-pane label="版本概览" name="overview">
            <section class="detail-band">
              <div class="band-heading">
                <h2>版本信息</h2>
                <span>并发版本 {{ version.lock_version }}</span>
              </div>
              <el-descriptions :column="3" border>
                <el-descriptions-item label="项目">
                  {{ version.project?.name || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="负责人">
                  {{ version.owner?.display_name || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="计划发布日期">
                  {{ version.planned_release_date || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="计划开始日期">
                  {{ version.planned_start_date || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="实际发布时间">
                  {{ version.released_at || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="范围统计">
                  {{ currentCounts.requirements ?? 0 }} 需求 /
                  {{ currentCounts.tasks ?? 0 }} 任务 /
                  {{ currentCounts.defects ?? 0 }} 缺陷
                </el-descriptions-item>
              </el-descriptions>

              <div class="text-section">
                <h3>版本说明</h3>
                <p>{{ version.description || '暂无版本说明' }}</p>
              </div>
              <div class="text-section">
                <h3>发布说明</h3>
                <p>{{ version.release_snapshot?.release_notes || version.release_notes || '暂无发布说明' }}</p>
              </div>
            </section>
          </el-tab-pane>

          <el-tab-pane
            :label="`需求范围（${currentCounts.requirements ?? 0}）`"
            name="scope"
          >
            <section class="detail-band">
              <RequirementPlanner
                :version="version"
                :disabled="workspaceBlocked"
                @busy="handleScopeBusy"
                @changed="handleScopeChanged"
                @stale="handleScopeStale"
              />
            </section>
          </el-tab-pane>

          <el-tab-pane label="发布门禁" name="release-gate">
            <section class="detail-band">
              <ReleaseGatePanel
                :result="currentGateResult"
                :project-id="version.project?.id"
                :version-id="version.id"
              />
            </section>
          </el-tab-pane>

          <el-tab-pane
            :label="`变更历史（${version.counts?.histories ?? historyItems.length}）`"
            name="history"
          >
            <section class="detail-band">
              <VersionHistory
                :items="historyItems"
                :snapshot="version.release_snapshot"
              />
            </section>
          </el-tab-pane>
        </el-tabs>
      </template>
    </AsyncState>

    <VersionEditDialog
      v-if="version && editVisible"
      v-model="editVisible"
      :version="version"
      :editable="canEdit"
      :busy="editBusy"
      :error="editError"
      :stale="editStale"
      @confirm="handleEdit"
      @reload="reloadEditVersion"
    />

    <ReleaseDialog
      v-if="version"
      v-model="commandVisible"
      :mode="commandMode"
      :version="version"
      :target-status="commandTarget"
      :busy="commandBusy"
      @confirm="handleCommand"
    />
  </div>
</template>

<style scoped lang="scss">
.version-detail {
  width: 100%;
}

.detail-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 18px;
  border-bottom: 1px solid $color-border;
}

.heading-copy {
  min-width: 0;

  p {
    margin: 7px 0 0;
    color: $color-muted;
    font-size: $font-size-small;
  }
}

.version-title-line {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 8px;

  h1 {
    margin: 0;
    overflow-wrap: anywhere;
    color: $color-ink;
    font-size: $font-size-h1;
    letter-spacing: 0;
  }
}

.command-bar {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;

  :deep(.el-button + .el-button) {
    margin-left: 0;
  }
}

.readonly-status {
  display: inline-flex;
  min-height: 32px;
  align-items: center;
  padding: 0 10px;
  border: 1px solid $color-border;
  border-radius: 4px;
  color: $color-muted;
  background: $color-canvas;
  font-size: $font-size-caption;
}

.progress-band {
  padding: 22px 0 18px;
  border-bottom: 1px solid $color-border;
}

.version-tabs {
  margin-top: 12px;

  :deep(.el-tabs__header) {
    margin-bottom: 0;
  }

  :deep(.el-tabs__content) {
    overflow: visible;
  }
}

.detail-band {
  padding: 22px 0 8px;
}

.band-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;

  h2 {
    margin: 0;
    color: $color-ink;
    font-size: $font-size-h3;
  }

  span {
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.text-section {
  padding: 18px 0;
  border-bottom: 1px solid $color-border;

  &:last-child {
    border-bottom: 0;
  }

  h3 {
    margin: 0;
    color: $color-ink;
    font-size: $font-size-body;
  }

  p {
    margin: 8px 0 0;
    color: $color-muted;
    font-size: $font-size-small;
    line-height: 1.7;
    white-space: pre-wrap;
  }
}

@media (max-width: 960px) {
  .detail-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .command-bar {
    justify-content: flex-start;
  }
}

@media (max-width: 680px) {
  .version-title-line {
    align-items: flex-start;
    flex-direction: column;
  }

  .command-bar {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));

    :deep(.el-button) {
      width: 100%;
    }
  }

  :deep(.el-descriptions__body) {
    overflow-x: auto;
  }
}
</style>
