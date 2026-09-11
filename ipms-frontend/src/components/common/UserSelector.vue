<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { listAssigneeOptions } from '@/api/workOptions'
import { mapApiError } from '@/composables/useApiError'
const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  projectId: { type: [Number, String], default: null },
  workType: { type: String, default: 'task' },
  placeholder: { type: String, default: '选择负责人' },
})
const emit = defineEmits(['update:modelValue'])
const options = ref([]), loading = ref(false), error = ref(null)
let sequence = 0
async function fetchOptions() {
  const current = ++sequence
  options.value = []
  error.value = null
  if (!props.projectId) { loading.value = false; return }
  loading.value = true
  try {
    const { data } = await listAssigneeOptions(props.projectId, props.workType)
    if (current === sequence) options.value = data.data
  } catch (failure) { if (current === sequence) error.value = mapApiError(failure) }
  finally { if (current === sequence) loading.value = false }
}
watch(() => [props.projectId, props.workType], (_value, previous) => {
  if (previous) emit('update:modelValue', null)
  fetchOptions()
}, { immediate: true })
onBeforeUnmount(() => { sequence++ })
</script>
<template>
  <div class="user-selector">
    <el-select :model-value="modelValue" :placeholder="placeholder" :disabled="!projectId || Boolean(error)" :loading="loading" filterable clearable no-data-text="暂无可分配人员" @update:model-value="$emit('update:modelValue', $event)">
      <el-option v-for="user in options" :key="user.id" :label="user.display_name" :value="user.id" />
    </el-select>
    <span v-if="error" class="user-error">{{ error.message }} <el-button text @click="fetchOptions">重试</el-button></span>
  </div>
</template>
<style scoped>.user-selector,.user-selector .el-select{width:100%;min-width:0}.user-error{color:#b42318;font-size:13px}</style>
