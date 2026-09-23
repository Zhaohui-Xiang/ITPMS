<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Check, CloseBold, Refresh } from '@element-plus/icons-vue'
import {
  getRequirement,
  getRequirementVersions,
  reviewRequirement,
  transitionRequirement,
} from '@/api/requirement'
import { listTasks } from '@/api/task'
import { listDefects } from '@/api/defect'
import AsyncState from '@/components/common/AsyncState.vue'
import ProjectDeliveryTable from '@/components/requirements/ProjectDeliveryTable.vue'
import ExecutionOwnerEditor from '@/components/requirements/ExecutionOwnerEditor.vue'
import { mapApiError } from '@/composables/useApiError'

const route = useRoute()
const router = useRouter()

const deliveryTransitions = {
  ASSIGNED: { value: 3, label: '开发中' },
  IN_DEVELOPMENT: { value: 4, label: '测试中' },
  IN_TESTING: { value: 5, label: '待上线' },
  DEPLOYED: { value: 7, label: '已验收' },
}

const requirement = ref(null)
const tasks = ref([])
const defects = ref([])
const revisions = ref([])
const loading = ref(false)
const error = ref(null)
const reviewVisible = ref(false)
const reviewAction = ref('approve')
const reviewComment = ref('')
const reviewBusy = ref(false)

const requirementId = computed(() => route.params.id)
const allowedActions = computed(() => requirement.value?.allowed_actions ?? [])
const canReview = computed(() => allowedActions.value.includes('review'))
const reviewTitle = computed(() => (
  reviewAction.value === 'approve' ? '审核通过需求' : '驳回需求'
))

function paginatedItems(response) {
  return response?.data?.data?.items ?? []
}

async function loadDetail() {
  loading.value = true
  error.value = null

  try {
    const [
      requirementResponse,
      taskResponse,
      defectResponse,
      revisionResponse,
    ] = await Promise.all([
      getRequirement(requirementId.value),
      listTasks({
        requirement_id: requirementId.value,
        page: 1,
        page_size: 100,
      }),
      listDefects({
        requirement_id: requirementId.value,
        page: 1,
        page_size: 100,
      }),
      getRequirementVersions(requirementId.value),
    ])

    requirement.value = requirementResponse?.data?.data ?? null
    tasks.value = paginatedItems(taskResponse)
    defects.value = paginatedItems(defectResponse)
    revisions.value = revisionResponse?.data?.data ?? []
  } catch (requestError) {
    requirement.value = null
    tasks.value = []
    defects.value = []
    revisions.value = []
    const mapped = mapApiError(requestError)
    // 后端 404 默认返回英文原文，详情页统一中文化，不暴露内部信息
    if (mapped.status === 404) {
      mapped.message = '需求不存在或已被删除'
    }
    error.value = mapped
  } finally {
    loading.value = false
  }
}

function openReview(action) {
  reviewAction.value = action
  reviewComment.value = ''
  reviewVisible.value = true
}

async function submitReview() {
  const comment = reviewComment.value.trim()
  if (reviewAction.value === 'reject' && !comment) {
    ElMessage.error('驳回需求必须填写原因')
    return
  }

  reviewBusy.value = true
  try {
    await reviewRequirement(requirement.value.id, {
      action: reviewAction.value,
      ...(comment ? { comment } : {}),
    })
    reviewVisible.value = false
    ElMessage.success(reviewAction.value === 'approve' ? '需求审核通过' : '需求已驳回')
    await loadDetail()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '需求审核失败')
  } finally {
    reviewBusy.value = false
  }
}

async function advanceDelivery(delivery) {
  const target = deliveryTransitions[delivery.delivery_status_code]
  if (!target || !delivery.project?.id) return

  try {
    await ElMessageBox.confirm(
      `确认将“${delivery.project.name}”从${delivery.delivery_status_label}推进至${target.label}？`,
      '推进项目交付',
      {
        type: 'warning',
        confirmButtonText: '确认推进',
        cancelButtonText: '取消',
      },
    )
    await transitionRequirement(requirement.value.id, {
      project_id: delivery.project.id,
      status: target.value,
    })
    ElMessage.success(`${delivery.project.name}已推进至${target.label}`)
    await loadDetail()
  } catch (requestError) {
    if (requestError === 'cancel' || requestError === 'close') return
    ElMessage.error(requestError?.response?.data?.message ?? '项目交付状态更新失败')
  }
}

function formatDate(value) {
  if (!value) return '-'
  return new Intl.DateTimeFormat('zh-CN', {
    dateStyle: 'medium',
    timeStyle: value.includes('T') ? 'short' : undefined,
    hour12: false,
  }).format(new Date(value))
}

function formatBytes(value) {
  const bytes = Number(value ?? 0)
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function revisionSummary(revision) {
  if (revision.change_summary) return revision.change_summary
  const changes = Array.isArray(revision.changes) ? revision.changes : []
  return changes.length > 0 ? `${changes.length} 个字段发生变化` : '保存需求版本'
}

onMounted(loadDetail)
</script>

<template>
  <div class="page-container requirement-detail">
    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && !requirement"
      empty-title="需求不存在"
      empty-description="无法读取该需求"
      @retry="loadDetail"
    >
      <template v-if="requirement">
        <header class="detail-heading">
          <div>
            <el-button link :icon="ArrowLeft" @click="router.back()">
              返回需求列表
            </el-button>
            <h1>{{ requirement.title }}</h1>
            <p>需求 #{{ requirement.id }} · 数据版本 {{ requirement.version }}</p>
          </div>
          <div class="heading-actions">
            <el-button
              :icon="Refresh"
              aria-label="刷新需求数据"
              @click="loadDetail"
            />
            <el-button
              v-if="canReview"
              data-testid="reject-requirement"
              type="danger"
              plain
              :icon="CloseBold"
              @click="openReview('reject')"
            >
              驳回
            </el-button>
            <el-button
              v-if="canReview"
              data-testid="approve-requirement"
              type="primary"
              :icon="Check"
              @click="openReview('approve')"
            >
              审核通过
            </el-button>
          </div>
        </header>

        <section class="detail-band overview-band" aria-labelledby="requirement-overview-title">
          <div class="band-heading">
            <h2 id="requirement-overview-title">需求概览</h2>
            <span
              data-testid="aggregate-status"
              class="aggregate-status"
              :data-status-code="requirement.status_code"
            >
              总体状态：{{ requirement.status_label }}
            </span>
          </div>
          <el-descriptions :column="3" border>
            <el-descriptions-item label="优先级">
              {{ requirement.priority_label || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="需求类型">
              {{ requirement.requirement_type ? `类型 #${requirement.requirement_type}` : '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="期望完成">
              {{ requirement.expected_completion_date || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="提出人">
              {{ requirement.submitter?.display_name || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="审核人">
              {{ requirement.reviewer?.display_name || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="执行负责人">
              <ExecutionOwnerEditor
                :requirement="requirement"
                @updated="loadDetail"
                @stale="loadDetail"
              />
            </el-descriptions-item>
            <el-descriptions-item label="需求描述" :span="3">
              <span class="long-copy">{{ requirement.description || '暂无描述' }}</span>
            </el-descriptions-item>
          </el-descriptions>
        </section>

        <section class="detail-band" aria-labelledby="project-delivery-title">
          <div class="band-heading">
            <h2 id="project-delivery-title">项目交付</h2>
            <span>{{ requirement.project_deliveries?.length ?? 0 }} 个项目</span>
          </div>
          <ProjectDeliveryTable
            :deliveries="requirement.project_deliveries ?? []"
            @transition="advanceDelivery"
          />
        </section>

        <section class="detail-band" aria-labelledby="requirement-tasks-title">
          <div class="band-heading">
            <h2 id="requirement-tasks-title">关联任务</h2>
            <span>{{ tasks.length }} 项</span>
          </div>
          <div v-if="tasks.length === 0" class="section-empty">暂无关联任务</div>
          <div v-else class="data-table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>任务</th>
                  <th>项目</th>
                  <th>负责人</th>
                  <th>状态</th>
                  <th>截止日期</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="task in tasks" :key="task.id">
                  <td>{{ task.title }}</td>
                  <td>{{ task.project?.name || '-' }}</td>
                  <td>{{ task.assignee?.display_name || '-' }}</td>
                  <td>{{ task.status_label || '-' }}</td>
                  <td>{{ task.due_date || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="detail-band" aria-labelledby="requirement-defects-title">
          <div class="band-heading">
            <h2 id="requirement-defects-title">关联缺陷</h2>
            <span>{{ defects.length }} 项</span>
          </div>
          <div v-if="defects.length === 0" class="section-empty">暂无关联缺陷</div>
          <div v-else class="data-table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>缺陷</th>
                  <th>项目</th>
                  <th>严重程度</th>
                  <th>状态</th>
                  <th>负责人</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="defect in defects" :key="defect.id">
                  <td>{{ defect.title }}</td>
                  <td>{{ defect.project?.name || '-' }}</td>
                  <td>{{ defect.severity_label || '-' }}</td>
                  <td>{{ defect.status_label || '-' }}</td>
                  <td>{{ defect.assignee?.display_name || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="detail-band" aria-labelledby="requirement-attachments-title">
          <div class="band-heading">
            <h2 id="requirement-attachments-title">需求附件</h2>
            <span>{{ requirement.attachments?.length ?? 0 }} 个文件</span>
          </div>
          <div
            v-if="!requirement.attachments?.length"
            class="section-empty"
          >
            暂无需求附件
          </div>
          <ul v-else class="attachment-list">
            <li v-for="attachment in requirement.attachments" :key="attachment.id">
              <div>
                <strong>{{ attachment.filename }}</strong>
                <span>
                  {{ formatBytes(attachment.file_size) }} ·
                  {{ attachment.file_type || '未知类型' }}
                </span>
              </div>
              <span>
                {{ attachment.uploaded_by?.display_name || '-' }} ·
                {{ formatDate(attachment.uploaded_at) }}
              </span>
            </li>
          </ul>
        </section>

        <section class="detail-band" aria-labelledby="requirement-history-title">
          <div class="band-heading">
            <h2 id="requirement-history-title">修订历史</h2>
            <span>{{ revisions.length }} 个版本</span>
          </div>
          <div v-if="revisions.length === 0" class="section-empty">暂无修订记录</div>
          <ol v-else class="revision-list">
            <li v-for="revision in revisions" :key="revision.id">
              <span class="revision-number">V{{ revision.version_number }}</span>
              <div>
                <strong>{{ revisionSummary(revision) }}</strong>
                <span>
                  {{ revision.changed_by?.display_name || '系统' }} ·
                  {{ formatDate(revision.changed_at) }}
                </span>
              </div>
            </li>
          </ol>
        </section>
      </template>
    </AsyncState>

    <el-dialog
      v-model="reviewVisible"
      :title="reviewTitle"
      width="min(520px, 92vw)"
      destroy-on-close
    >
      <el-input
        v-model="reviewComment"
        type="textarea"
        :rows="4"
        maxlength="1000"
        :placeholder="reviewAction === 'reject' ? '填写驳回原因' : '填写审核意见（选填）'"
      />
      <template #footer>
        <el-button :disabled="reviewBusy" @click="reviewVisible = false">
          取消
        </el-button>
        <el-button type="primary" :loading="reviewBusy" @click="submitReview">
          确认
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.requirement-detail {
  width: 100%;
}

.detail-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 18px;
  border-bottom: 1px solid $color-border;

  h1 {
    margin: 8px 0 0;
    overflow-wrap: anywhere;
    color: $color-ink;
    font-size: $font-size-h1;
    letter-spacing: 0;
  }

  p {
    margin: 6px 0 0;
    color: $color-muted;
    font-size: $font-size-small;
  }
}

.heading-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;

  :deep(.el-button + .el-button) {
    margin-left: 0;
  }
}

.detail-band {
  padding: 22px 0;
  border-bottom: 1px solid $color-border;
}

.band-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 14px;

  h2 {
    margin: 0;
    color: $color-ink;
    font-size: $font-size-h3;
  }

  > span {
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.aggregate-status {
  display: inline-flex;
  min-height: 26px;
  align-items: center;
  padding: 3px 9px;
  border: 1px solid #b2ddff;
  border-radius: 4px;
  color: $color-primary;
  background: $color-primary-soft;
  font-size: $font-size-caption;
  font-weight: 700;
}

.long-copy {
  line-height: 1.7;
  white-space: pre-wrap;
}

.data-table-scroll {
  width: 100%;
  overflow-x: auto;
}

.data-table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  table-layout: fixed;

  th,
  td {
    padding: 12px;
    border-bottom: 1px solid $color-border;
    color: $color-ink;
    font-size: $font-size-small;
    text-align: left;
  }

  th {
    color: $color-muted;
    background: $color-canvas;
    font-size: $font-size-caption;
    font-weight: 600;
  }
}

.section-empty {
  display: grid;
  min-height: 100px;
  place-items: center;
  color: $color-muted;
  background: $color-canvas;
  font-size: $font-size-small;
}

.attachment-list,
.revision-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.attachment-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  min-height: 62px;
  padding: 10px 0;
  border-bottom: 1px solid $color-border;

  strong,
  span {
    display: block;
  }

  strong {
    color: $color-ink;
    font-size: $font-size-small;
  }

  span {
    margin-top: 4px;
    color: $color-muted;
    font-size: $font-size-caption;
  }

  > span {
    margin-top: 0;
    text-align: right;
  }
}

.revision-list li {
  display: grid;
  grid-template-columns: 52px minmax(0, 1fr);
  gap: 14px;
  padding: 13px 0;
  border-bottom: 1px solid $color-border;

  strong,
  span {
    display: block;
  }

  strong {
    color: $color-ink;
    font-size: $font-size-small;
  }

  div span {
    margin-top: 4px;
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.revision-number {
  color: $color-primary;
  font-size: $font-size-small;
  font-weight: 700;
}

@media (max-width: 900px) {
  .detail-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .heading-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 640px) {
  .band-heading,
  .attachment-list li {
    align-items: flex-start;
    flex-direction: column;
  }

  .attachment-list li > span {
    text-align: left;
  }
}
</style>
