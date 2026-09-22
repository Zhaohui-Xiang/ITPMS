<script setup>
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Edit, Refresh } from '@element-plus/icons-vue'
import { listExecutionOwnerOptions, updateRequirement } from '@/api/requirement'
import { mapApiError } from '@/composables/useApiError'

const props = defineProps({ requirement: { type: Object, required: true } })
const emit = defineEmits(['updated', 'stale'])
const canAssign = computed(() => props.requirement.allowed_actions?.includes('assign_owner'))
const visible = ref(false)
const options = ref([])
const loading = ref(false)
const busy = ref(false)
const selected = ref(null)
const openedVersion = ref(null)
const error = ref('')
const optionsError = ref('')
const stale = ref(false)

async function loadOptions() {
  loading.value = true
  optionsError.value = ''
  options.value = []
  try { options.value = (await listExecutionOwnerOptions(props.requirement.id)).data.data }
  catch (failure) { optionsError.value = mapApiError(failure).message }
  finally { loading.value = false }
}
function open() {
  if (!canAssign.value || busy.value || visible.value) return
  if (!stale.value) {
    selected.value = props.requirement.dev_lead_id
    openedVersion.value = props.requirement.version
    error.value = ''
    loadOptions()
  }
  visible.value = true
}
async function save() {
  if (!visible.value || !canAssign.value || stale.value || busy.value || loading.value || optionsError.value) return
  const id = Number(selected.value)
  if (!options.value.some(option => option.id === id)) {
    error.value = '请选择有效的执行负责人'
    return
  }
  if (!Number.isInteger(openedVersion.value) || openedVersion.value < 1) {
    error.value = '缺少有效并发版本，请关闭并刷新需求数据'
    return
  }
  busy.value = true
  error.value = ''
  try {
    await updateRequirement(props.requirement.id, { dev_lead_id: id, version: openedVersion.value })
    visible.value = false
    ElMessage.success('执行负责人已更新')
    emit('updated')
  } catch (failure) {
    const mapped = mapApiError(failure)
    error.value = mapped.message
    stale.value = mapped.requiresReload || mapped.status === 409
  } finally { busy.value = false }
}
</script>

<template>
  <div class="execution-owner">
    <span>{{ requirement.dev_lead?.display_name || '未分配' }}</span>
    <el-button v-if="canAssign" text type="primary" :icon="Edit" data-testid="edit-execution-owner" @click="open">设置负责人</el-button>
    <el-dialog v-model="visible" title="设置执行负责人" width="min(520px, 94vw)"
      :close-on-click-modal="!busy" :close-on-press-escape="!busy" :show-close="!busy">
      <el-form-item label="执行负责人" required>
        <el-select v-model="selected" filterable data-testid="execution-owner-select" :loading="loading"
          :disabled="busy || stale || !canAssign || Boolean(optionsError)" placeholder="选择执行负责人" no-data-text="暂无有效负责人">
          <el-option v-for="user in options" :key="user.id" :label="user.display_name" :value="user.id" />
        </el-select>
      </el-form-item>
      <p v-if="optionsError" class="owner-error" role="alert">{{ optionsError }} <el-button text @click="loadOptions">重试</el-button></p>
      <p v-if="error" class="owner-error" role="alert">{{ error }}</p>
      <p v-if="stale" class="owner-error" role="alert">需求已变更，保存已暂停。重新加载会放弃当前选择，请核对最新数据。</p>
      <template #footer>
        <el-button :disabled="busy" @click="visible = false">取消</el-button>
        <el-button v-if="stale" data-testid="reload-execution-owner" :icon="Refresh" :disabled="busy"
          @click="$emit('stale')">重新加载并核对</el-button>
        <el-button data-testid="save-execution-owner" type="primary" :loading="busy"
          :disabled="loading || stale || !canAssign || Boolean(optionsError)" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.execution-owner { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.execution-owner > span { overflow-wrap: anywhere; }
.owner-error { color: $color-danger; overflow-wrap: anywhere; }
:deep(.el-select) { width: 100%; }
</style>
