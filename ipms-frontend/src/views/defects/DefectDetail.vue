<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, CircleCheck, Delete, Download, Refresh, Upload, User } from '@element-plus/icons-vue'
import {
  confirmDefect,
  deleteDefectAttachment,
  downloadDefectAttachment,
  getDefect,
  reopenDefect,
  resolveDefect,
  uploadDefectAttachment,
  verifyDefect,
} from '@/api/defect'
import { listAuditLogs } from '@/api/auditLog'
import AsyncState from '@/components/common/AsyncState.vue'
import AssignmentDialog from '@/components/common/AssignmentDialog.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { mapApiError } from '@/composables/useApiError'
import { usePermission } from '@/composables/usePermission'

const route = useRoute()
const router = useRouter()
const { canPerform } = usePermission()

const defect = ref(null)
const history = ref([])
const loading = ref(false)
const error = ref(null)
const busy = ref(false)
const uploading = ref(false)
const assignmentOpen = ref(false)
const fileInput = ref(null)

const defectId = computed(() => route.params.id)
const allowedActions = computed(() => defect.value?.allowed_actions ?? [])
const canUse = (action) => canPerform(defect.value, action)
const attachments = computed(() => defect.value?.attachments ?? [])

const ACTION_LABELS = {
  1: '创建', 2: '编辑', 3: '删除', 4: '状态变更', 5: '审核',
  6: '分配', 7: '上传', 8: '下载', 9: '导出', 10: '登录',
}
const STATUS_LABELS = {
  1: '待确认', 2: '已确认', 3: '修复中', 4: '待复测', 5: '已关闭', 6: '重新打开',
}
const DISCOVERY_PHASE_LABELS = { 1: '开发验证', 2: '测试验证' }
const DEFECT_TYPE_LABELS = { 1: '功能缺陷', 2: '性能缺陷', 3: '安全缺陷', 4: '兼容性缺陷', 5: '其他' }

function formatTime(value) {
  return value ? value.slice(0, 16).replace('T', ' ') : '-'
}

function formatBytes(bytes) {
  if (!Number.isFinite(bytes)) return '-'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

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
  if (detail.result) parts.push(`结果：${detail.result === 'pass' ? '复测通过' : '复测失败'}`)
  if (detail.comment) parts.push(detail.comment)
  if (detail.reason) parts.push(`原因：${detail.reason}`)
  if (detail.attachment) parts.push(`附件：${detail.attachment}`)
  return parts.join('；')
}

async function loadDetail() {
  loading.value = true
  error.value = null
  try {
    const [defectResponse, historyResponse] = await Promise.all([
      getDefect(defectId.value),
      listAuditLogs({
        module: 4,
        target_type: 'defect',
        target_id: Number(defectId.value),
        page: 1,
        page_size: 50,
      }),
    ])
    defect.value = defectResponse?.data?.data ?? null
    history.value = historyResponse?.data?.data?.items ?? []
  } catch (requestError) {
    defect.value = null
    history.value = []
    const mapped = mapApiError(requestError)
    // 后端 404 默认返回英文原文，详情页统一中文化
    if (mapped.status === 404) {
      mapped.message = '缺陷不存在或已被删除'
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

async function handleConfirm() {
  try {
    await ElMessageBox.confirm(
      `确认缺陷「${defect.value.title}」有效并进入待分配状态吗？`,
      '确认缺陷',
      { confirmButtonText: '确认有效', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(() => confirmDefect(defect.value.id, {}), '缺陷已确认')
}

async function handleResolve() {
  let description
  try {
    const result = await ElMessageBox.prompt(
      `请填写缺陷「${defect.value.title}」的修复说明`,
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
    () => resolveDefect(defect.value.id, { fix_description: description }),
    '修复已提交复测',
  )
}

async function handleVerify(result) {
  let comment
  try {
    const promptResult = await ElMessageBox.prompt(
      `请记录缺陷「${defect.value.title}」的复测${result === 'pass' ? '通过' : '失败'}说明`,
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
    () => verifyDefect(defect.value.id, { result, comment }),
    result === 'pass' ? '缺陷复测通过' : '缺陷已退回修复',
  )
}

async function handleReopen() {
  let reasonText
  try {
    const result = await ElMessageBox.prompt(
      `请填写重新打开缺陷「${defect.value.title}」的原因`,
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
  await mutate(() => reopenDefect(defect.value.id, { reason: reasonText }), '缺陷已重新打开')
}

function pickFile() {
  fileInput.value?.click()
}

async function handleFileChange(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  uploading.value = true
  try {
    await uploadDefectAttachment(defect.value.id, file)
    ElMessage.success(`附件「${file.name}」已上传`)
    await loadDetail()
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '附件上传失败')
  } finally {
    uploading.value = false
  }
}

function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

async function downloadAttachment(attachment) {
  try {
    const { data } = await downloadDefectAttachment(attachment.id)
    if (!(data instanceof Blob)) throw new Error('下载响应格式不正确')
    saveBlob(data, attachment.filename)
  } catch {
    ElMessage.error('附件下载失败')
  }
}

async function removeAttachment(attachment) {
  try {
    await ElMessageBox.confirm(
      `确定删除附件「${attachment.filename}」吗？`,
      '删除确认',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch (reason) {
    if (reason === 'cancel' || reason === 'close') return
    throw reason
  }
  await mutate(() => deleteDefectAttachment(attachment.id), '附件已删除')
}

onMounted(loadDetail)
</script>

<template>
  <div class="defect-detail">
    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && !defect"
      empty-title="缺陷不存在"
      empty-description="缺陷可能已被删除，或您没有查看权限"
      @retry="loadDetail"
    >
      <template v-if="defect">
        <header class="detail-heading">
          <div>
            <el-button link :icon="ArrowLeft" data-testid="back-to-defects" @click="router.back()">
              返回
            </el-button>
            <h1>{{ defect.title }}</h1>
            <p>缺陷 #{{ defect.id }} · {{ defect.severity_label }}</p>
          </div>
          <div class="heading-actions">
            <StatusTag :status-code="defect.status_code" :status-label="defect.status_label" />
            <el-button
              v-if="canUse('confirm')"
              type="primary"
              :loading="busy"
              data-testid="confirm-defect"
              @click="handleConfirm"
            >
              确认缺陷
            </el-button>
            <el-button
              v-if="canUse('assign')"
              :icon="User"
              data-testid="assign-defect"
              @click="assignmentOpen = true"
            >
              分配
            </el-button>
            <el-button
              v-if="canUse('resolve')"
              type="primary"
              :loading="busy"
              data-testid="resolve-defect"
              @click="handleResolve"
            >
              提交修复
            </el-button>
            <el-button
              v-if="canUse('verify')"
              type="success"
              :icon="CircleCheck"
              :loading="busy"
              data-testid="verify-pass-defect"
              @click="handleVerify('pass')"
            >
              复测通过
            </el-button>
            <el-button
              v-if="canUse('verify')"
              type="danger"
              :loading="busy"
              data-testid="verify-fail-defect"
              @click="handleVerify('fail')"
            >
              复测失败
            </el-button>
            <el-button
              v-if="canUse('reopen')"
              type="warning"
              :icon="Refresh"
              :loading="busy"
              data-testid="reopen-defect"
              @click="handleReopen"
            >
              重开
            </el-button>
          </div>
        </header>

        <section class="detail-band" aria-labelledby="defect-info-title">
          <div class="band-heading">
            <h2 id="defect-info-title">缺陷信息</h2>
          </div>
          <el-descriptions :column="3" border>
            <el-descriptions-item label="所属需求">
              <router-link
                v-if="defect.requirement"
                class="record-link"
                :to="`/requirements/${defect.requirement.id}`"
              >
                {{ defect.requirement.title }}
              </router-link>
              <span v-else>-</span>
            </el-descriptions-item>
            <el-descriptions-item label="所属项目">
              {{ defect.project?.name || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="严重程度">{{ defect.severity_label || '-' }}</el-descriptions-item>
            <el-descriptions-item label="缺陷类型">
              {{ DEFECT_TYPE_LABELS[defect.defect_type] || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="发现阶段">
              {{ DISCOVERY_PHASE_LABELS[defect.discovery_phase] || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="发现时间">{{ formatTime(defect.discovered_at) }}</el-descriptions-item>
            <el-descriptions-item label="提交人">
              {{ defect.reporter?.display_name || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="负责人">
              {{ defect.assignee?.display_name || '未分配' }}
            </el-descriptions-item>
            <el-descriptions-item label="关闭时间">{{ formatTime(defect.closed_at) }}</el-descriptions-item>
            <el-descriptions-item label="缺陷描述" :span="3">
              <span class="long-copy">{{ defect.description || '暂无描述' }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="修复说明" :span="3">
              <span class="long-copy">{{ defect.fix_description || '暂无修复说明' }}</span>
            </el-descriptions-item>
          </el-descriptions>
        </section>

        <section class="detail-band" aria-labelledby="defect-attachments-title">
          <div class="band-heading">
            <h2 id="defect-attachments-title">附件</h2>
            <span>{{ attachments.length }} 个文件</span>
            <el-button
              type="primary"
              size="small"
              :icon="Upload"
              :loading="uploading"
              data-testid="upload-attachment"
              @click="pickFile"
            >
              上传附件
            </el-button>
            <input
              ref="fileInput"
              type="file"
              class="visually-hidden"
              data-testid="attachment-input"
              @change="handleFileChange"
            >
          </div>
          <div v-if="attachments.length === 0" class="section-empty">暂无附件</div>
          <ul v-else class="attachment-list">
            <li v-for="attachment in attachments" :key="attachment.id">
              <div>
                <strong>{{ attachment.filename }}</strong>
                <span>{{ formatBytes(attachment.file_size) }} · {{ attachment.file_type || '未知类型' }}</span>
              </div>
              <div class="attachment-actions">
                <span>
                  {{ attachment.uploaded_by?.display_name || '-' }} · {{ formatTime(attachment.uploaded_at) }}
                </span>
                <el-button
                  text
                  :icon="Download"
                  :data-testid="`download-attachment-${attachment.id}`"
                  @click="downloadAttachment(attachment)"
                >
                  下载
                </el-button>
                <el-button
                  text
                  type="danger"
                  :icon="Delete"
                  :data-testid="`delete-attachment-${attachment.id}`"
                  @click="removeAttachment(attachment)"
                >
                  删除
                </el-button>
              </div>
            </li>
          </ul>
        </section>

        <section class="detail-band" aria-labelledby="defect-history-title">
          <div class="band-heading">
            <h2 id="defect-history-title">状态流转记录</h2>
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
              <span>{{ formatTime(record.created_at) }}</span>
            </li>
          </ul>
        </section>
      </template>
    </AsyncState>

    <AssignmentDialog
      :record="assignmentOpen ? defect : null"
      work-type="defect"
      @close="assignmentOpen = false"
      @assigned="loadDetail"
    />
  </div>
</template>

<style scoped lang="scss">
.defect-detail {
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

.attachment-list,
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
  }
}

.attachment-list {
  li > div:first-child {
    display: flex;
    flex-direction: column;
    gap: 2px;

    span {
      color: $color-muted;
      font-size: $font-size-caption;
    }
  }

  .attachment-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;

    > span {
      color: $color-muted;
      font-size: $font-size-caption;
    }
  }
}

.history-list {
  li {
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

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
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
