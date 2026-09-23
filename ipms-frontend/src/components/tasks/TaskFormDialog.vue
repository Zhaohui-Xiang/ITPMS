<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { createTask, updateTask } from '@/api/task'
import UserSelector from '@/components/common/UserSelector.vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'create',
    validator: (value) => ['create', 'edit'].includes(value),
  },
  // 编辑模式下的任务原始数据
  task: {
    type: Object,
    default: null,
  },
  // 可选需求（含 project_deliveries.allowed_actions），由父级按数据范围加载
  requirements: {
    type: Array,
    default: () => [],
  },
  // R2 场景：锁定所属需求（从需求详情拆任务）
  lockRequirement: {
    type: Boolean,
    default: false,
  },
  initialRequirementId: {
    type: [Number, String],
    default: null,
  },
})

const emit = defineEmits(['update:modelValue', 'saved'])

const saving = ref(false)
const form = reactive({
  id: null,
  requirement_id: '',
  project_id: '',
  title: '',
  description: '',
  assignee_id: null,
  priority: 3,
  due_date: '',
  remind_days_before: null,
  estimated_hours: null,
  actual_hours: null,
})

const visible = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})
const isCreate = computed(() => props.mode === 'create')
const dialogTitle = computed(() => (isCreate.value ? '新建任务' : '编辑任务'))
const selectedRequirement = computed(() => (
  props.requirements.find((item) => item.id === Number(form.requirement_id))
))
const creatableRequirements = computed(() => (
  props.requirements.filter((item) => item.allowed_actions?.includes('create_task'))
))
const projectOptions = computed(() => (
  (selectedRequirement.value?.project_deliveries ?? [])
    .filter((delivery) => delivery.project && delivery.allowed_actions?.includes('create_task'))
    .map((delivery) => delivery.project)
))

watch(() => props.modelValue, (open) => {
  if (!open) return
  if (isCreate.value) {
    Object.assign(form, {
      id: null,
      requirement_id: props.initialRequirementId ?? '',
      project_id: '',
      title: '',
      description: '',
      assignee_id: null,
      priority: 3,
      due_date: '',
      remind_days_before: null,
      estimated_hours: null,
      actual_hours: null,
    })
    handleRequirementChange()
  } else if (props.task) {
    Object.assign(form, {
      id: props.task.id,
      requirement_id: props.task.requirement?.id ?? '',
      project_id: props.task.project?.id ?? '',
      title: props.task.title,
      description: props.task.description ?? '',
      assignee_id: props.task.assignee?.id ?? null,
      priority: props.task.priority,
      due_date: props.task.due_date ?? '',
      remind_days_before: props.task.remind_days_before,
      estimated_hours: props.task.estimated_hours,
      actual_hours: props.task.actual_hours,
    })
  }
}, { immediate: true })

function handleRequirementChange() {
  form.project_id = projectOptions.value.length === 1 ? projectOptions.value[0].id : ''
}

function formPayload() {
  const payload = {
    title: form.title.trim(),
    description: form.description.trim() || null,
    ...(isCreate.value ? {
      assignee_id: form.assignee_id ? Number(form.assignee_id) : null,
    } : {}),
    priority: Number(form.priority),
    due_date: form.due_date,
    remind_days_before: form.remind_days_before,
    estimated_hours: form.estimated_hours,
  }
  if (isCreate.value) {
    payload.requirement_id = Number(form.requirement_id)
    payload.project_id = Number(form.project_id)
  } else {
    payload.actual_hours = form.actual_hours
  }
  return payload
}

async function saveTask() {
  const payload = formPayload()
  if (!payload.title || !payload.due_date
    || (isCreate.value && (!payload.requirement_id || !payload.project_id))) {
    ElMessage.error('请填写标题、所属需求、所属项目和截止日期')
    return
  }

  saving.value = true
  try {
    if (isCreate.value) {
      await createTask(payload)
      ElMessage.success(`任务「${payload.title}」已创建`)
    } else {
      await updateTask(form.id, payload)
      ElMessage.success(`任务「${payload.title}」已更新`)
    }
    visible.value = false
    emit('saved')
  } catch (requestError) {
    ElMessage.error(requestError?.response?.data?.message ?? '任务保存失败')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog v-model="visible" :title="dialogTitle" width="min(680px, 94vw)" destroy-on-close>
    <el-form label-position="top">
      <div v-if="isCreate" class="form-grid">
        <el-form-item label="所属需求" required>
          <el-select
            v-model="form.requirement_id"
            filterable
            :disabled="lockRequirement"
            placeholder="选择可拆分任务的需求"
            @change="handleRequirementChange"
          >
            <el-option
              v-for="requirement in creatableRequirements"
              :key="requirement.id"
              :label="requirement.title"
              :value="requirement.id"
            />
            <template #empty>
              <div class="select-empty-hint">
                暂无可拆分任务的需求：需求需审核通过且您具备其项目范围的操作权限
              </div>
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="所属项目" required>
          <el-select v-model="form.project_id" filterable placeholder="选择关联项目">
            <el-option
              v-for="project in projectOptions"
              :key="project.id"
              :label="project.name"
              :value="project.id"
            />
          </el-select>
        </el-form-item>
      </div>
      <el-form-item label="任务标题" required>
        <el-input v-model="form.title" maxlength="200" show-word-limit />
      </el-form-item>
      <el-form-item label="任务描述">
        <el-input v-model="form.description" type="textarea" :rows="4" />
      </el-form-item>
      <div class="form-grid">
        <el-form-item v-if="isCreate" label="负责人">
          <UserSelector v-model="form.assignee_id" :project-id="form.project_id" />
        </el-form-item>
        <el-form-item label="优先级" required>
          <el-select v-model="form.priority">
            <el-option label="紧急" :value="1" />
            <el-option label="高" :value="2" />
            <el-option label="中" :value="3" />
            <el-option label="低" :value="4" />
          </el-select>
        </el-form-item>
        <el-form-item label="截止日期" required>
          <el-date-picker v-model="form.due_date" type="date" value-format="YYYY-MM-DD" />
        </el-form-item>
        <el-form-item label="提前提醒天数">
          <el-input-number v-model="form.remind_days_before" :min="0" controls-position="right" />
        </el-form-item>
        <el-form-item label="预计工时">
          <el-input-number v-model="form.estimated_hours" :min="0" :precision="1" controls-position="right" />
        </el-form-item>
        <el-form-item v-if="!isCreate" label="实际工时">
          <el-input-number v-model="form.actual_hours" :min="0" :precision="1" controls-position="right" />
        </el-form-item>
      </div>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="saving" data-testid="save-task" @click="saveTask">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.select-empty-hint {
  padding: 12px 16px;
  color: $color-muted;
  font-size: $font-size-caption;
  line-height: 1.6;
}

@media (max-width: 760px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
