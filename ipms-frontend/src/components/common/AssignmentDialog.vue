<script setup>
import { ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { updateTask } from '@/api/task'
import { assignDefect } from '@/api/defect'
import { mapApiError } from '@/composables/useApiError'
import UserSelector from './UserSelector.vue'
const props = defineProps({ record: { type: Object, default: null }, workType: { type: String, default: 'task' } })
const emit = defineEmits(['close', 'assigned'])
const assigneeId = ref(null), busy = ref(false), error = ref(null)
watch(() => props.record, () => { assigneeId.value = null; error.value = null })
async function save() {
  if (!assigneeId.value || busy.value || !props.record?.allowed_actions?.includes('assign')) return
  busy.value = true
  try {
    const data = { assignee_id: Number(assigneeId.value) }
    if (props.workType === 'defect') await assignDefect(props.record.id, data)
    else await updateTask(props.record.id, data)
    ElMessage.success('负责人已分配')
    emit('assigned')
    emit('close')
  } catch (failure) { error.value = mapApiError(failure) }
  finally { busy.value = false }
}
</script>
<template>
  <el-dialog :model-value="Boolean(record)" title="分配负责人" width="min(480px, 94vw)" :close-on-click-modal="!busy" @update:model-value="!$event && !busy && $emit('close')">
    <p>{{ record?.title }}</p>
    <UserSelector v-model="assigneeId" :project-id="record?.project?.id" :work-type="workType" />
    <p v-if="error" role="alert">{{ error.message }}</p>
    <template #footer><el-button :disabled="busy" @click="$emit('close')">取消</el-button><el-button type="primary" :disabled="!assigneeId" :loading="busy" @click="save">确认分配</el-button></template>
  </el-dialog>
</template>
