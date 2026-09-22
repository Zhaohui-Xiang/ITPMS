<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Check, Refresh } from '@element-plus/icons-vue'
import { getProject } from '@/api/project'
import { mapApiError } from '@/composables/useApiError'

const props = defineProps({
  modelValue: Boolean,
  version: { type: Object, required: true },
  editable: Boolean,
  busy: Boolean,
  stale: Boolean,
  error: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'confirm', 'reload'])
const form = reactive({})
const lockVersion = ref(null)
const localError = ref('')
const manager = ref(null)
const ownerLoading = ref(false)
const ownerError = ref('')
const blocked = computed(() => props.busy || props.stale || !props.editable)
const fieldErrors = computed(() => Object.values(props.error?.fieldErrors ?? {}).flat())

watch(() => props.version, async (version, _previous, onCleanup) => {
  let cancelled = false
  onCleanup(() => { cancelled = true })
  Object.assign(form, {
    code: version.code ?? '',
    name: version.name ?? '',
    description: version.description ?? '',
    owner_id: version.owner?.id ?? null,
    planned_start_date: version.planned_start_date ?? '',
    planned_release_date: version.planned_release_date ?? '',
    release_notes: version.release_notes ?? '',
  })
  lockVersion.value = version.lock_version
  localError.value = ''
  manager.value = null
  ownerError.value = ''
  ownerLoading.value = true
  try {
    const response = await getProject(version.project.id)
    if (!cancelled) manager.value = response?.data?.data?.manager ?? null
  } catch (error) {
    if (!cancelled) ownerError.value = mapApiError(error).message
  } finally {
    if (!cancelled) ownerLoading.value = false
  }
}, { immediate: true })

function close() {
  if (!props.busy) emit('update:modelValue', false)
}

function submit() {
  if (blocked.value) return
  localError.value = ''
  const code = form.code.trim()
  const name = form.name.trim()
  const ownerChanged = form.owner_id !== (props.version.owner?.id ?? null)

  if (!code || code.length > 50 || !/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(code)) {
    localError.value = '版本编号须以字母或数字开头，仅支持字母、数字、点、下划线和连字符，最多 50 字符'
  } else if (!name || name.length > 100) {
    localError.value = '版本名称不能为空，最多 100 字符'
  } else if (form.release_notes.length > 10000) {
    localError.value = '发布说明不能超过 10000 字符'
  } else if (form.planned_start_date && form.planned_release_date
    && form.planned_release_date < form.planned_start_date) {
    localError.value = '计划发布日期不能早于计划开始日期'
  } else if (!Number.isInteger(lockVersion.value) || lockVersion.value < 1) {
    localError.value = '缺少有效并发版本，请关闭并刷新版本数据'
  } else if (ownerChanged && form.owner_id !== null
    && (ownerLoading.value || ownerError.value || form.owner_id !== manager.value?.id)) {
    localError.value = '负责人只能选择项目已分配的内部 IT 项目经理'
  }
  if (localError.value) return

  emit('confirm', {
    lock_version: lockVersion.value,
    code,
    name,
    description: form.description || null,
    planned_start_date: form.planned_start_date || null,
    planned_release_date: form.planned_release_date || null,
    release_notes: form.release_notes.trim() || null,
    ...(ownerChanged ? { owner_id: form.owner_id } : {}),
  })
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="编辑版本信息"
    width="min(640px, 94vw)"
    top="5vh"
    :close-on-click-modal="false"
    :close-on-press-escape="!busy"
    :show-close="!busy"
    @update:model-value="close"
  >
    <div class="version-edit-body">
      <div v-if="stale" class="edit-alert" role="alert">
        版本已变更，保存已暂停。重新加载会替换当前草稿，请核对最新内容后再保存。
      </div>
      <div v-else-if="!editable" class="edit-alert" role="alert">当前版本已不可编辑。</div>
      <div v-if="localError || error" class="edit-alert" role="alert">
        <p>{{ localError || error?.message }}</p>
        <p v-for="(message, index) in fieldErrors" :key="index">{{ message }}</p>
      </div>
      <el-form label-position="top" :disabled="blocked" @submit.prevent="submit">
        <div class="edit-grid">
          <el-form-item label="版本编号" required>
            <el-input v-model="form.code" data-testid="edit-code" maxlength="50" />
          </el-form-item>
          <el-form-item label="版本名称" required>
            <el-input v-model="form.name" data-testid="edit-name" maxlength="100" />
          </el-form-item>
          <el-form-item label="负责人" :error="ownerError">
            <el-select
              v-model="form.owner_id"
              data-testid="edit-owner"
              :loading="ownerLoading"
              :disabled="ownerLoading || Boolean(ownerError)"
              clearable
              placeholder="未设置"
              no-data-text="项目尚未分配内部 IT 项目经理"
              @clear="form.owner_id = null"
            >
              <el-option
                v-if="version.owner && version.owner.id !== manager?.id"
                :value="version.owner.id"
                :label="version.owner.display_name"
                disabled
              />
              <el-option v-if="manager" :value="manager.id" :label="manager.display_name" />
            </el-select>
          </el-form-item>
          <el-form-item label="计划开始日期">
            <el-date-picker
              v-model="form.planned_start_date"
              data-testid="edit-planned_start_date"
              type="date"
              format="YYYY-MM-DD"
              value-format="YYYY-MM-DD"
            />
          </el-form-item>
          <el-form-item label="计划发布日期">
            <el-date-picker
              v-model="form.planned_release_date"
              data-testid="edit-planned_release_date"
              type="date"
              format="YYYY-MM-DD"
              value-format="YYYY-MM-DD"
            />
          </el-form-item>
          <el-form-item class="full-width" label="版本说明">
            <el-input v-model="form.description" data-testid="edit-description" type="textarea" :rows="3" />
          </el-form-item>
          <el-form-item class="full-width" label="发布说明">
            <el-input
              v-model="form.release_notes"
              data-testid="edit-release_notes"
              type="textarea"
              :rows="5"
              maxlength="10000"
              show-word-limit
            />
          </el-form-item>
        </div>
      </el-form>
    </div>
    <template #footer>
      <div class="edit-footer">
        <el-button data-testid="cancel-version-edit" :disabled="busy" @click="close">取消</el-button>
        <el-button
          v-if="stale"
          data-testid="reload-version-info"
          :icon="Refresh"
          :loading="busy"
          :disabled="busy"
          @click="$emit('reload')"
        >
          重新加载并核对
        </el-button>
        <el-button
          data-testid="save-version-info"
          type="primary"
          :icon="Check"
          :loading="busy"
          :disabled="blocked"
          @click="submit"
        >
          保存
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.version-edit-body {
  max-height: 70vh;
  overflow-y: auto;
  padding: 0 4px;
}

.edit-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  column-gap: 16px;

  :deep(.el-form-item),
  :deep(.el-form-item__content) { min-width: 0; }

  :deep(.el-select),
  :deep(.el-date-editor) {
    width: 100%;
    min-width: 0;
  }
}

.full-width { grid-column: 1 / -1; }

.edit-alert {
  margin-bottom: 14px;
  padding: 10px 12px;
  border-left: 3px solid $color-danger;
  color: $color-danger;
  background: #fff1f1;
  overflow-wrap: anywhere;

  p { margin: 0; }
}

.edit-footer {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;

  :deep(.el-button + .el-button) { margin-left: 0; }
}

@media (max-width: 600px) {
  .edit-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
