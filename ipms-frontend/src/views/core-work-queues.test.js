import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PaginatedTable from '@/components/common/PaginatedTable.vue'
import RequirementList from '@/views/requirements/RequirementList.vue'
import TaskList from '@/views/tasks/TaskList.vue'
import DefectList from '@/views/defects/DefectList.vue'

const mocks = vi.hoisted(() => ({
  routeQuery: {},
  routerReplace: vi.fn(),
  routerPush: vi.fn(),
  listProjects: vi.fn(),
  listRequirements: vi.fn(),
  createRequirement: vi.fn(),
  updateRequirement: vi.fn(),
  reviewRequirement: vi.fn(),
  listTasks: vi.fn(),
  createTask: vi.fn(),
  updateTask: vi.fn(),
  claimTask: vi.fn(),
  transitionTask: vi.fn(),
  holdTask: vi.fn(),
  listDefects: vi.fn(),
  createDefect: vi.fn(),
  updateDefect: vi.fn(),
  confirmDefect: vi.fn(),
  assignDefect: vi.fn(),
  resolveDefect: vi.fn(),
  verifyDefect: vi.fn(),
  reopenDefect: vi.fn(),
  messageSuccess: vi.fn(),
  messageError: vi.fn(),
  confirm: vi.fn(),
  prompt: vi.fn(),
}))

vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => ({ query: mocks.routeQuery }),
  useRouter: () => ({
    replace: mocks.routerReplace,
    push: mocks.routerPush,
  }),
}))
vi.mock('@/api/project', () => ({
  listProjects: mocks.listProjects,
  listRequirementProjectOptions: mocks.listProjects,
}))
vi.mock('@/api/requirement', () => ({
  listRequirements: mocks.listRequirements,
  createRequirement: mocks.createRequirement,
  updateRequirement: mocks.updateRequirement,
  reviewRequirement: mocks.reviewRequirement,
}))
vi.mock('@/api/task', () => ({
  listTasks: mocks.listTasks,
  createTask: mocks.createTask,
  updateTask: mocks.updateTask,
  claimTask: mocks.claimTask,
  transitionTask: mocks.transitionTask,
  holdTask: mocks.holdTask,
}))
vi.mock('@/api/defect', () => ({
  listDefects: mocks.listDefects,
  createDefect: mocks.createDefect,
  updateDefect: mocks.updateDefect,
  confirmDefect: mocks.confirmDefect,
  assignDefect: mocks.assignDefect,
  resolveDefect: mocks.resolveDefect,
  verifyDefect: mocks.verifyDefect,
  reopenDefect: mocks.reopenDefect,
}))
vi.mock('element-plus', () => ({
  ElMessage: {
    success: mocks.messageSuccess,
    error: mocks.messageError,
  },
  ElMessageBox: {
    confirm: mocks.confirm,
    prompt: mocks.prompt,
  },
}))
vi.mock('@/composables/usePermission', () => ({
  usePermission: () => ({
    canCreate: () => true,
    canEdit: () => true,
    canPerform: (resource, action, localAllowed = true) => (
      localAllowed && resource.allowed_actions?.includes(action)
    ),
    isSuperAdmin: ref(false),
  }),
}))

const ElButton = defineComponent({
  name: 'ElButton',
  emits: ['click'],
  setup(_props, { attrs, emit, slots }) {
    return () => h('button', {
      ...attrs,
      type: 'button',
      onClick: () => emit('click'),
    }, slots.default?.())
  },
})

const passthrough = (name) => defineComponent({
  name,
  setup(_props, { slots }) {
    return () => h('div', [slots.default?.(), slots.footer?.()])
  },
})

const stubs = {
  ElButton,
  ElDialog: passthrough('ElDialog'),
  ElForm: passthrough('ElForm'),
  ElFormItem: passthrough('ElFormItem'),
  ElTooltip: passthrough('ElTooltip'),
  ElPagination: true,
  ElSkeleton: true,
  ElEmpty: passthrough('ElEmpty'),
  WarningFilled: true,
  ElInput: true,
  ElSelect: true,
  ElOption: true,
  ElDatePicker: true,
  ElInputNumber: true,
  ElIcon: passthrough('ElIcon'),
  Search: true,
  RefreshLeft: true,
  Plus: true,
  View: true,
  EditPen: true,
  User: true,
  VideoPlay: true,
  CircleCheck: true,
  VideoPause: true,
  Check: true,
  Refresh: true,
  Promotion: true,
}

const page = (items, overrides = {}) => ({
  data: {
    data: {
      items,
      page: overrides.page ?? 1,
      page_size: overrides.page_size ?? 20,
      total: overrides.total ?? items.length,
      total_pages: overrides.total_pages ?? 1,
    },
  },
})

const requirement = (actions = []) => ({
  id: 41,
  title: '数据库迁移需求',
  description: '来自接口的数据',
  priority: 2,
  priority_label: '高',
  requirement_type: 1,
  status: 1,
  status_code: 'PENDING_REVIEW',
  status_label: '待审核',
  projects: [{ id: 7, name: 'ERP 升级' }],
  project_deliveries: [],
  submitter: { id: 2, display_name: '业务申请人' },
  allowed_actions: actions,
  updated_at: '2026-09-08T08:00:00Z',
})

const task = (actions = []) => ({
  id: 51,
  title: '执行数据库迁移',
  priority: 2,
  priority_label: '高',
  status: 1,
  status_code: 'TODO',
  status_label: '待处理',
  requirement: { id: 41, title: '数据库迁移需求' },
  project: { id: 7, name: 'ERP 升级' },
  assignee: null,
  due_date: '2026-09-30',
  allowed_actions: actions,
})

const defect = (id, title, actions) => ({
  id,
  title,
  severity: 2,
  severity_label: '严重',
  status: actions.includes('verify') ? 4 : 5,
  status_code: actions.includes('verify') ? 'PENDING_RETEST' : 'CLOSED',
  status_label: actions.includes('verify') ? '待复测' : '已关闭',
  requirement: { id: 41, title: '数据库迁移需求' },
  project: { id: 7, name: 'ERP 升级' },
  reporter: { id: 3, display_name: '测试人员' },
  assignee: { id: 8, display_name: '开发人员' },
  allowed_actions: actions,
})

function mountView(component) {
  return mount(component, { global: { stubs } })
}

describe('core work queues', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    Object.keys(mocks.routeQuery).forEach((key) => delete mocks.routeQuery[key])
    mocks.listProjects.mockResolvedValue(page([{ id: 7, name: 'ERP 升级' }]))
    mocks.listRequirements.mockResolvedValue(page([requirement()]))
    mocks.listTasks.mockResolvedValue(page([task()]))
    mocks.listDefects.mockResolvedValue(page([]))
    mocks.claimTask.mockResolvedValue({})
    mocks.verifyDefect.mockResolvedValue({})
    mocks.reopenDefect.mockResolvedValue({})
    mocks.confirm.mockResolvedValue('confirm')
    mocks.prompt.mockResolvedValue({ value: '验证完成' })
  })

  it('loads requirements from route filters with the API page_size contract and resets the query', async () => {
    Object.assign(mocks.routeQuery, {
      page: '2',
      page_size: '50',
      keyword: '迁移',
      status: '1',
    })
    mocks.listRequirements.mockResolvedValueOnce(page([requirement()], {
      page: 2,
      page_size: 50,
      total: 51,
      total_pages: 2,
    }))

    const wrapper = mountView(RequirementList)
    await flushPromises()

    expect(mocks.listRequirements).toHaveBeenCalledWith({
      page: 2,
      page_size: 50,
      keyword: '迁移',
      status: 1,
    })
    expect(wrapper.text()).toContain('数据库迁移需求')

    await wrapper.get('[data-testid="reset-filters"]').trigger('click')
    await flushPromises()
    expect(mocks.routerReplace).toHaveBeenCalledWith({ query: {} })
  })

  it('does not render requirement mutations when the server returns no allowed actions', async () => {
    const wrapper = mountView(RequirementList)
    await flushPromises()

    expect(wrapper.find('[data-testid="edit-requirement-41"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="review-requirement-41"]').exists()).toBe(false)
  })

  it('does not send locked project links when editing an approved requirement', async () => {
    mocks.listRequirements.mockResolvedValue(page([{
      ...requirement(['edit']),
      status: 2,
      status_code: 'ASSIGNED',
      can_edit_project_scope: false,
    }]))
    mocks.updateRequirement.mockResolvedValue({})
    const wrapper = mountView(RequirementList)
    await flushPromises()
    await wrapper.get('[data-testid="edit-requirement-41"]').trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find((button) => button.text() === '保存').trigger('click')
    await flushPromises()
    expect(mocks.updateRequirement).toHaveBeenCalledWith(41, expect.objectContaining({
      title: '数据库迁移需求',
    }))
    expect(mocks.updateRequirement.mock.calls[0][1]).not.toHaveProperty('project_ids')
  })

  it('claims a task and reloads its current page', async () => {
    Object.assign(mocks.routeQuery, { page: '2' })
    mocks.listTasks.mockResolvedValue(page([task(['claim'])], {
      page: 2,
      total: 21,
      total_pages: 2,
    }))

    const wrapper = mountView(TaskList)
    await flushPromises()
    await wrapper.get('[data-testid="claim-task-51"]').trigger('click')
    await flushPromises()

    expect(mocks.claimTask).toHaveBeenCalledWith(51)
    expect(mocks.listTasks).toHaveBeenLastCalledWith({ page: 2, page_size: 20 })
  })

  it('only offers requirements with create_task in the create dialog and explains the empty state', async () => {
    const selectStubs = {
      ...stubs,
      ElSelect: { template: '<div class="el-select-stub"><slot /><slot name="empty" /></div>' },
      ElOption: { props: ['value', 'label'], template: '<li class="el-option-stub" :data-value="value">{{ label }}</li>' },
    }
    mocks.listRequirements.mockResolvedValue(page([
      requirement(['create_task']),
      { ...requirement([]), id: 42, title: '无权限需求' },
    ]))

    const wrapper = mount(TaskList, { global: { stubs: selectStubs } })
    await flushPromises()
    const values = wrapper.findAll('.el-option-stub')
      .map((option) => option.attributes('data-value'))
    expect(values).toContain('41')
    expect(values).not.toContain('42')

    mocks.listRequirements.mockResolvedValue(page([requirement([])]))
    const emptyWrapper = mount(TaskList, { global: { stubs: selectStubs } })
    await flushPromises()
    const emptyValues = emptyWrapper.findAll('.el-option-stub')
      .map((option) => option.attributes('data-value'))
    expect(emptyValues).not.toContain('41')
    expect(emptyWrapper.text()).toContain('暂无可拆分任务的需求')
  })

  it('verifies and reopens defects through named confirmation flows then reloads', async () => {
    mocks.listDefects.mockResolvedValue(page([
      defect(61, '权限校验失败', ['verify']),
      defect(62, '发布后回归', ['reopen']),
    ]))

    const wrapper = mountView(DefectList)
    await flushPromises()

    await wrapper.get('[data-testid="verify-defect-61"]').trigger('click')
    await flushPromises()
    expect(mocks.verifyDefect).toHaveBeenCalledWith(61, {
      result: 'pass',
      comment: '验证完成',
    })

    mocks.prompt.mockResolvedValueOnce({ value: '问题再次出现' })
    await wrapper.get('[data-testid="reopen-defect-62"]').trigger('click')
    await flushPromises()
    expect(mocks.reopenDefect).toHaveBeenCalledWith(62, {
      reason: '问题再次出现',
    })
    expect(mocks.listDefects).toHaveBeenCalledTimes(3)
  })

  it('saves task edits without changing assignment when assign is not allowed', async () => {
    mocks.listTasks.mockResolvedValue(page([task(['edit'])]))
    mocks.updateTask.mockResolvedValue({})
    const wrapper = mountView(TaskList)
    await flushPromises()
    await wrapper.get('[data-testid="edit-task-51"]').trigger('click')
    await flushPromises()
    const save = wrapper.findAll('button').find((button) => button.text() === '保存')
    await save.trigger('click')
    await flushPromises()
    expect(mocks.updateTask).toHaveBeenCalledWith(51, expect.objectContaining({
      title: '执行数据库迁移',
      due_date: '2026-09-30',
    }))
    expect(mocks.updateTask.mock.calls[0][1]).not.toHaveProperty('assignee_id')
  })

  it('hides stale rows when the server denies a subsequent load', async () => {
    mocks.listTasks
      .mockResolvedValueOnce(page([task(['claim'])]))
      .mockRejectedValueOnce({ response: { status: 403, data: { message: '无权限' } } })
    const wrapper = mountView(TaskList)
    await flushPromises()
    await wrapper.get('[data-testid="reset-filters"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="claim-task-51"]').exists()).toBe(false)
    expect(wrapper.find('[data-state="error"]').exists()).toBe(true)
  })

  it('renders loading instead of empty when both are true', () => {
    const wrapper = mount(PaginatedTable, {
      props: {
        loading: true,
        rows: [],
        total: 0,
        page: 1,
        pageSize: 20,
      },
      global: { stubs },
    })

    expect(wrapper.find('[data-state="loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-state="empty"]').exists()).toBe(false)
  })
})
