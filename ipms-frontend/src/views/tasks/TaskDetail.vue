<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, CircleCheck, EditPen, User, VideoPause, VideoPlay } from '@element-plus/icons-vue'
import {
  claimTask,
  getTask,
  holdTask,
  transitionTask,
} from '@/api/task'
import { listAuditLogs } from '@/api/auditLog'
import AsyncState from '@/components/common/AsyncState.vue'
import AssignmentDialog from '@/components/common/AssignmentDialog.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import TaskFormDialog from '@/components/tasks/TaskFormDialog.vue'
import { mapApiError } from '@/composables/useApiError'
import { usePermission } from '@/composables/usePermission'

const route = useRoute()
const router = useRouter()
const { canPerform } = usePermission()

const task = ref(null)
const history = ref([])
const loading = ref(false)
const error = ref(null)
const busy = ref(false)
const assignmentOpen = ref(false)
const editVisible = ref(false)

const taskId = computed(() => route.params.id)
const allowedActions = computed(() => task.value?.allowed_actions ?? [])
const canUse = (action) => canPerform(task.value, action)

const ACTION_LABELS = {
  1: '创建', 2: '编辑', 3: '删除', 4: '状态变更', 5: '审核',
  6: '分配', 7: '上传', 8: '下载', 9: '导出', 10: '登录',
}
const STATUS_LABELS = { 1: '待开始', 2: '进行中', 3: '已完成', 4: '已挂起' }

function historyDescription(record) {
  let detail = record.detail
  if (typeof detail === 'string') {
    try { detail = JSON.parse(detail) } catch { detail = null }
  }
  if (!detail || typeof detail !== 'object') return ''
  const parts = []
  if (detail.from_status || detail.to_status) {
    parts.push(`${STATUS_LABELS[detail.from_status] ?? '-'} → ${STATUS_LABELS[detail.to_status] ?? '-'}`)
  }
  if (detail.reason) parts.push(`原因：${detail.reason}`)
  return parts.join('；')
}

async function loadDetail() {
  loading.value = true
  error.value = null
  try {
    const [taskResponse, historyResponse] = await Promise.all([
      getTask(taskId.value),
      listAuditLogs({
        module: 3,
        target_type: 'task',
        target_id: Number(taskId.value),
        page: 1,
        page_size: 50,
      }),
    ])
    task.value = taskResponse?.data?.data ?? null
    history.value = historyResponse?.data?.data?.items ?? []
  } catch (requestError) {
    task.value = null
    history.value = []
    const mapped = mapApiError(requestError)
    // 后端 404 默认返回英文原文，详情页统一中文化
    if (mapped.status === 404) {
      mapped.message = '任务不存在或已被删除'
    }
    error.value = mapped
  } finally {
    loading.value = false
  }
}

async function mutate(request, successMessage) {
  busy.value = true
  try {
    await request()
    ElMessage.success(successMessage)
    await loadDetail()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '操作失败')
  } finally {
    busy.value = false
  }
}

async function handleClaim() {
  try {
    await ElMessageBox.confirm(
      `确认认领任务「${task.value.title}」吗？`,
      '认领任务',
      { confirmButtonText: '认领', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(() => claimTask(task.value.id), '任务已认领')
}

async function handleTransition(targetStatus, targetLabel) {
  try {
    await ElMessageBox.confirm(
      `确认将任务「${task.value.title}」变更为“${targetLabel}”吗？`,
      '变更任务状态',
      { confirmButtonText: '确认', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(
    () => transitionTask(task.value.id, { status: targetStatus }),
    `任务已变更为${targetLabel}`,
  )
}

async function handleHold() {
  let reasonText
  try {
    const { value } = await ElMessageBox.prompt(
      `请填写任务「${task.value.title}」的挂起原因`,
      '挂起任务',
      {
        inputErrorMessage: '挂起原因不能为空',
        confirmButtonText: '确认挂起',
        cancelButtonText: '取消',
        inputValidator: (value) => Boolean(value && value.trim()),
      },
    )
    reasonText = value.trim()
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(() => holdTask(task.value.id, { reason: reasonText }), '任务已挂起')
}

onMounted(loadDetail)
</script>

<template>
  <div class="task-detail">
    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && !task"
      empty-title="任务不存在"
      empty-description="任务可能已被删除，或您没有查看权限"
      @retry="loadDetail"
    >
      <template v-if="task">
        <header class="detail-heading">
          <div>
            <el-button link :icon="ArrowLeft" data-testid="back-to-tasks" @click="router.back()">
              返回
            </el-button>
            <h1>{{ task.title }}</h1>
            <p>任务 #{{ task.id }}</p>
          </div>
          <div class="heading-actions">
            <StatusTag :status-code="task.status_code" :status-label="task.status_label" />
            <el-button
              v-if="canUse('edit')"
              text
              :icon="EditPen"
              data-testid="edit-task"
              @click="editVisible = true"
            >
              编辑
            </el-button>
            <el-button
              v-if="canUse('claim')"
              type="primary"
              :icon="User"
              :loading="busy"
              data-testid="claim-task"
              @click="handleClaim"
            >
              认领
            </el-button>
            <el-button
              v-if="canUse('assign')"
              :icon="User"
              data-testid="assign-task"
              @click="assignmentOpen = true"
            >
              分配
            </el-button>
            <el-button
              v-if="canUse('transition') && task.status_code === 'TODO'"
              type="primary"
              :icon="VideoPlay"
              :loading="busy"
              data-testid="start-task"
              @click="handleTransition(2, '进行中')"
            >
              开始
            </el-button>
            <el-button
              v-if="canUse('transition') && task.status_code === 'IN_PROGRESS'"
              type="success"
              :icon="CircleCheck"
              :loading="busy"
              data-testid="complete-task"
              @click="handleTransition(3, '已完成')"
            >
              完成
            </el-button>
            <template v-if="canUse('transition') && task.status_code === 'SUSPENDED'">
              <el-button :loading="busy" @click="handleTransition(1, '待开始')">恢复待办</el-button>
              <el-button type="primary" :loading="busy" @click="handleTransition(2, '进行中')">恢复执行</el-button>
            </template>
            <el-button
              v-if="canUse('hold')"
              type="warning"
              :icon="VideoPause"
              :loading="busy"
              data-testid="hold-task"
              @click="handleHold"
            >
              挂起
            </el-button>
          </div>
        </header>

        <section class="detail-band" aria-labelledby="task-info-title">
          <div class="band-heading">
            <h2 id="task-info-title">任务信息</h2>
          </div>
          <el-descriptions :column="3" border>
            <el-descriptions-item label="所属需求">
              <router-link
                v-if="task.requirement"
                class="record-link"
                :to="`/requirements/${task.requirement.id}`"
              >
                {{ task.requirement.title }}
              </router-link>
              <span v-else>-</span>
            </el-descriptions-item>
            <el-descriptions-item label="所属项目">
              {{ task.project?.name || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="负责人">
              {{ task.assignee?.display_name || '未分配' }}
            </el-descriptions-item>
            <el-descriptions-item label="优先级">{{ task.priority_label || '-' }}</el-descriptions-item>
            <el-descriptions-item label="截止日期">{{ task.due_date || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提前提醒天数">
              {{ task.remind_days_before ?? '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="预计工时">
              {{ task.estimated_hours ?? '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="实际工时">
              {{ task.actual_hours ?? '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="完成时间">
              {{ task.completed_at ? task.completed_at.slice(0, 16).replace('T', ' ') : '-' }}
            </el-descriptions-item>
            <el-descriptions-item v-if="task.suspend_reason" label="挂起原因" :span="3">
              {{ task.suspend_reason }}
            </el-descriptions-item>
            <el-descriptions-item label="任务描述" :span="3">
              <span class="long-copy">{{ task.description || '暂无描述' }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="创建时间">
              {{ task.created_at ? task.created_at.slice(0, 16).replace('T', ' ') : '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="更新时间">
              {{ task.updated_at ? task.updated_at.slice(0, 16).replace('T', ' ') : '-' }}
            </el-descriptions-item>
          </el-descriptions>
        </section>

        <section class="detail-band" aria-labelledby="task-history-title">
          <div class="band-heading">
            <h2 id="task-history-title">状态流转记录</h2>
            <span>{{ history.length }} 条</span>
          </div>
          <div v-if="history.length === 0" class="section-empty">暂无流转记录</div>
          <ul v-else class="history-list">
            <li v-for="record in history" :key="record.id">
              <div>
                <strong>{{ record.user_display_name || record.user_name }}</strong>
                <span>{{ ACTION_LABELS[record.action_type] || record.action_type }}</span>
                <span v-if="historyDescription(record)" class="history-detail">
                  {{ historyDescription(record) }}
                </span>
              </div>
              <span>{{ (record.created_at || '').slice(0, 16).replace('T', ' ') }}</span>
            </li>
          </ul>
        </section>
      </template>
    </AsyncState>

    <AssignmentDialog
      :record="assignmentOpen ? task : null"
      work-type="task"
      @close="assignmentOpen = false"
      @assigned="loadDetail"
    />
    <TaskFormDialog
      v-if="task"
      v-model="editVisible"
      mode="edit"
      :task="task"
      @saved="loadDetail"
    />
  </div>
</template>

<style scoped lang="scss">
.task-detail {
  width: 100%;
}

.detail-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 18px;
  border-bottom: 1px solid $color-border;

  h1 {
    margin: 8px 0 0;
    overflow-wrap: anywhere;
    color: $color-ink;
    font-size: $font-size-h1;
  }

  p {
    margin: 6px 0 0;
    color: $color-muted;
  }
}

.heading-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.detail-band {
  margin-top: 20px;
  padding: 18px 20px;
  background: #fff;
  border: 1px solid $color-border;
  border-radius: 8px;
}

.band-heading {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;

  h2 {
    margin: 0;
    font-size: $font-size-h2;
  }

  span {
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.long-copy {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.section-empty {
  padding: 24px 0;
  color: $color-muted;
  text-align: center;
}

.record-link {
  color: $color-primary;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.history-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;

  li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 10px 12px;
    background: $color-canvas;
    border-radius: 6px;

    > div {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }

    > span {
      flex-shrink: 0;
      color: $color-muted;
      font-size: $font-size-caption;
    }
  }
}

.history-detail {
  color: $color-muted;
}

@media (max-width: 760px) {
  .detail-heading {
    flex-direction: column;
  }

  .heading-actions {
    justify-content: flex-start;
  }
}
</style>
