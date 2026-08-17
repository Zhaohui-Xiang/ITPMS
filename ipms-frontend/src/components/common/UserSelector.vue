<script setup>
import { ref } from 'vue'

const props = defineProps({
  modelValue: {
    type: [String, Number],
    default: null
  },
  placeholder: {
    type: String,
    default: '请选择人员'
  }
})

const emit = defineEmits(['update:modelValue'])

// Mock 用户数据
const userOptions = ref([
  { id: 1, name: '张三', role: '项目经理', org: '信息化部门 - 开发组' },
  { id: 2, name: '李四', role: '开发人员', org: '信息化部门 - SAP组' },
  { id: 3, name: '王五', role: '测试人员', org: '信息化部门 - SAP组' },
  { id: 4, name: '赵六', role: '项目成员', org: '信息化部门 - 开发组' },
  { id: 5, name: '孙七', role: '项目经理', org: '供应商A - 开发组' },
  { id: 6, name: '周八', role: '开发人员', org: '供应商A - 开发组' }
])

function handleChange(val) {
  emit('update:modelValue', val)
}
</script>

<template>
  <el-select
    :model-value="modelValue"
    :placeholder="placeholder"
    filterable
    clearable
    @update:model-value="handleChange"
  >
    <el-option
      v-for="user in userOptions"
      :key="user.id"
      :label="`${user.name} (${user.role})`"
      :value="user.id"
    >
      <div class="user-option">
        <span class="user-option-name">{{ user.name }}</span>
        <span class="user-option-role">{{ user.role }}</span>
        <span class="user-option-org">{{ user.org }}</span>
      </div>
    </el-option>
  </el-select>
</template>

<style scoped lang="scss">
.user-option {
  display: flex;
  align-items: center;
  gap: 8px;
  line-height: 28px;

  .user-option-name {
    font-weight: 500;
  }

  .user-option-role {
    font-size: $font-size-caption;
    color: $color-primary;
    background: #ecf5ff;
    padding: 0 6px;
    border-radius: 3px;
  }

  .user-option-org {
    font-size: $font-size-caption;
    color: $gray-500;
    margin-left: auto;
  }
}
</style>
