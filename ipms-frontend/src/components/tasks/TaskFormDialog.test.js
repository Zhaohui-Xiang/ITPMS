import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TaskFormDialog from './TaskFormDialog.vue'

const { createTask, updateTask, messageError, messageSuccess } = vi.hoisted(() => ({
  createTask: vi.fn(),
  updateTask: vi.fn(),
  messageError: vi.fn(),
  messageSuccess: vi.fn(),
}))
vi.mock('@/api/task', () => ({ createTask, updateTask }))
vi.mock('element-plus', () => ({
  ElMessage: { error: messageError, success: messageSuccess },
}))

const stubs = {
  ElDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /><slot name="footer" /></div>' },
  ElForm: { template: '<form><slot /></form>' },
  ElFormItem: { props: ['label'], template: '<div class="form-item" :data-label="label"><slot /></div>' },
  ElInput: {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
  ElSelect: {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue', 'change'],
    template: '<select :disabled="disabled" :value="modelValue" '
      + '@change="$emit(\'update:modelValue\', Number($event.target.value)); $emit(\'change\')"><slot /></select>'
      + '<slot name="empty" />',
  },
  ElOption: { props: ['value', 'label'], template: '<option :value="value">{{ label }}</option>' },
  ElDatePicker: {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<input data-testid="due-date" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
  ElInputNumber: true,
  ElButton: { props: ['loading'], template: '<button><slot /></button>' },
  UserSelector: true,
}

const requirement = {
  id: 41,
  title: '数据库迁移需求',
  allowed_actions: ['create_task'],
  project_deliveries: [
    { project: { id: 7, name: 'ERP 升级' }, allowed_actions: ['create_task'] },
  ],
}

const editTask = {
  id: 51,
  title: '执行数据库迁移',
  description: '已有描述',
  requirement: { id: 41, title: '数据库迁移需求' },
  project: { id: 7, name: 'ERP 升级' },
  assignee: null,
  priority: 2,
  due_date: '2026-09-30',
  remind_days_before: 2,
  estimated_hours: 8,
  actual_hours: null,
}

function mountDialog(props) {
  return mount(TaskFormDialog, {
    props: { modelValue: true, ...props },
    global: { stubs },
  })
}

describe('TaskFormDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    createTask.mockResolvedValue({ data: { data: { id: 99 } } })
    updateTask.mockResolvedValue({ data: { data: { id: 51 } } })
  })

  it('prefills and locks the requirement when splitting from a requirement detail', async () => {
    const wrapper = mountDialog({
      mode: 'create',
      requirements: [requirement],
      lockRequirement: true,
      initialRequirementId: 41,
    })
    await flushPromises()

    const requirementSelect = wrapper.get('.form-item[data-label="所属需求"] select')
    expect(requirementSelect.attributes('disabled')).toBeDefined()
    expect(requirementSelect.element.value).toBe('41')
    // 单一可选项目自动带入
    expect(wrapper.get('.form-item[data-label="所属项目"] select').element.value).toBe('7')

    await wrapper.get('.form-item[data-label="任务标题"] input').setValue('迁移执行')
    await wrapper.get('[data-testid="due-date"]').setValue('2026-10-01')
    await wrapper.get('[data-testid="save-task"]').trigger('click')
    await flushPromises()

    expect(createTask).toHaveBeenCalledWith(expect.objectContaining({
      requirement_id: 41,
      project_id: 7,
      title: '迁移执行',
      due_date: '2026-10-01',
      priority: 3,
    }))
    expect(wrapper.emitted('saved')).toBeTruthy()
  })

  it('updates a task in edit mode without resending assignment fields', async () => {
    const wrapper = mountDialog({ mode: 'edit', task: editTask, requirements: [requirement] })
    await flushPromises()

    expect(wrapper.get('.form-item[data-label="任务标题"] input').element.value).toBe('执行数据库迁移')
    await wrapper.get('[data-testid="save-task"]').trigger('click')
    await flushPromises()

    expect(updateTask).toHaveBeenCalledWith(51, expect.objectContaining({
      title: '执行数据库迁移',
      due_date: '2026-09-30',
    }))
    const payload = updateTask.mock.calls[0][1]
    expect(payload).not.toHaveProperty('requirement_id')
    expect(payload).not.toHaveProperty('project_id')
    expect(payload).not.toHaveProperty('assignee_id')
  })

  it('explains the empty state when no requirement allows create_task', async () => {
    const wrapper = mountDialog({
      mode: 'create',
      requirements: [{ ...requirement, allowed_actions: [] }],
    })
    await flushPromises()

    expect(wrapper.text()).toContain('暂无可拆分任务的需求')
    await wrapper.get('[data-testid="save-task"]').trigger('click')
    expect(messageError).toHaveBeenCalled()
    expect(createTask).not.toHaveBeenCalled()
  })
})
