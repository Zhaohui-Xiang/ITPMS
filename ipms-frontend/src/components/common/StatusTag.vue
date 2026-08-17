<script setup>
import { computed } from 'vue'

const props = defineProps({
  type: {
    type: String,
    default: 'requirement', // 'requirement' | 'task' | 'defect'
    validator: (v) => ['requirement', 'task', 'defect'].includes(v)
  },
  status: {
    type: String,
    required: true
  }
})

// 需求状态颜色映射
const requirementStatusMap = {
  'pending_review': { color: '#4285F4', label: '待审核' },
  'assigned': { color: '#FF9800', label: '已分配' },
  'developing': { color: '#3F51B5', label: '开发中' },
  'testing': { color: '#9C27B0', label: '测试中' },
  'pending_online': { color: '#00BCD4', label: '待上线' },
  'online': { color: '#67C23A', label: '已上线' },
  'accepted': { color: '#1B5E20', label: '已验收' }
}

// 任务状态颜色映射
const taskStatusMap = {
  'todo': { color: '#9E9E9E', label: '待开始' },
  'in_progress': { color: '#4285F4', label: '进行中' },
  'done': { color: '#67C23A', label: '已完成' },
  'suspended': { color: '#FF9800', label: '已挂起' }
}

// 缺陷状态颜色映射
const defectStatusMap = {
  'pending': { color: '#F56C6C', label: '待确认' },
  'confirmed': { color: '#FF9800', label: '已确认' },
  'fixing': { color: '#4285F4', label: '修复中' },
  'retesting': { color: '#9C27B0', label: '待复测' },
  'closed': { color: '#67C23A', label: '已关闭' },
  'reopened': { color: '#F56C6C', label: '重新打开' }
}

const statusMap = computed(() => {
  switch (props.type) {
    case 'requirement': return requirementStatusMap
    case 'task': return taskStatusMap
    case 'defect': return defectStatusMap
    default: return {}
  }
})

const config = computed(() => {
  return statusMap.value[props.status] || { color: '#909399', label: props.status }
})
</script>

<template>
  <span
    class="status-tag"
    :style="{
      backgroundColor: config.color + '1a',
      color: config.color,
      borderColor: config.color + '33'
    }"
  >
    <span
      class="status-dot"
      :style="{ backgroundColor: config.color }"
    ></span>
    {{ config.label }}
  </span>
</template>

<style scoped lang="scss">
.status-tag {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 9999px;
  font-size: $font-size-caption;
  line-height: 20px;
  border: 1px solid;
  white-space: nowrap;
}

.status-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  margin-right: 6px;
  flex-shrink: 0;
}
</style>
