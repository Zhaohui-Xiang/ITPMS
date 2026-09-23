import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TaskDetail from './TaskDetail.vue'

const api = vi.hoisted(() => ({
  getTask: vi.fn(),
  claimTask: vi.fn(),
  transitionTask: vi.fn(),
  holdTask: vi.fn(),
  listAuditLogs: vi.fn(),
  messageSuccess: vi.fn(),
  messageError: vi.fn(),
  confirm: vi.fn(),
  prompt: vi.fn(),
}))
vi.mock('@/api/task', () => ({
  getTask: api.getTask,
  claimTask: api.claimTask,
  transitionTask: api.transitionTask,
  holdTask: api.holdTask,
  createTask: vi.fn(),
  updateTask: vi.fn(),
}))
vi.mock('@/api/auditLog', () => ({ listAuditLogs: api.listAuditLogs }))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => ({ params: { id: '51' } }),
  useRouter: () => ({ back: vi.fn() }),
}))
vi.mock('element-plus', () => ({
  ElMessage: { success: api.messageSuccess, error: api.messageError },
  ElMessageBox: { confirm: api.confirm, prompt: api.prompt },
}))
vi.mock('@/composables/usePermission', () => ({
  usePermission: () => ({
    canPerform: (resource, action, localAllowed = true) => (
      localAllowed && resource?.allowed_actions?.includes(action)
    ),
  }),
}))

const response = (data) => ({ data: { data } })
const task = (overrides = {}) => ({
  id: 51,
  title: '执行数据库迁移',
  description: '迁移核心库表结构',
  status: 1,
  status_code: 'TODO',
  status_label: '待开始',
  priority: 2,
  priority_label: '高',
  requirement: { id: 41, title: '数据库迁移需求' },
  project: { id: 7, name: 'ERP 升级' },
  assignee: null,
  due_date: '2026-09-30',
  remind_days_before: 2,
  estimated_hours: 8,
  actual_hours: null,
  suspend_reason: null,
  completed_at: null,
  allowed_actions: ['edit', 'assign', 'claim', 'transition', 'hold'],
  created_at: '2026-09-08T08:00:00Z',
  updated_at: '2026-09-08T08:00:00Z',
  ...overrides,
})
const historyPage = (items) => response({ items, page: 1, page_size: 50, total: items.length, total_pages: 1 })

const render = () => shallowMount(TaskDetail, {
  global: {
    stubs: {
      AsyncState: false,
      ElSkeleton: true,
      ElIcon: true,
      ElEmpty: true,
      ElDescriptions: { template: '<div><slot /></div>' },
      ElDescriptionsItem: { props: ['label'], template: '<div class="desc-item" :data-label="label"><slot /></div>' },
      ElButton: { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
      RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
    },
  },
})

describe('TaskDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getTask.mockResolvedValue(response(task()))
    api.listAuditLogs.mockResolvedValue(historyPage([
      {
        id: 1,
        user_display_name: '演示 IT 项目经理',
        action_type: 4,
        detail: { from_status: 1, to_status: 2 },
        created_at: '2026-09-09T01:00:00Z',
      },
    ]))
    api.transitionTask.mockResolvedValue({})
    api.claimTask.mockResolvedValue({})
    api.confirm.mockResolvedValue('confirm')
    api.prompt.mockResolvedValue({ value: '等待依赖' })
  })

  it('renders read-only info, requirement link and transition history', async () => {
    const wrapper = render()
    await flushPromises()

    expect(api.getTask).toHaveBeenCalledWith('51')
    expect(api.listAuditLogs).toHaveBeenCalledWith(expect.objectContaining({
      module: 3,
      target_type: 'task',
      target_id: 51,
    }))
    expect(wrapper.text()).toContain('执行数据库迁移')
    expect(wrapper.text()).toContain('迁移核心库表结构')
    expect(wrapper.text()).toContain('演示 IT 项目经理')
    expect(wrapper.text()).toContain('待开始 → 进行中')
    const link = wrapper.get('a[href="/requirements/41"]')
    expect(link.text()).toContain('数据库迁移需求')
  })

  it('renders actions from allowed_actions and runs the start transition', async () => {
    const wrapper = render()
    await flushPromises()

    expect(wrapper.find('[data-testid="start-task"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="claim-task"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="complete-task"]').exists()).toBe(false)

    await wrapper.get('[data-testid="start-task"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalled()
    expect(api.transitionTask).toHaveBeenCalledWith(51, { status: 2 })
    expect(api.getTask).toHaveBeenCalledTimes(2)
  })

  it('shows no actions when the server returns none', async () => {
    api.getTask.mockResolvedValue(response(task({ allowed_actions: [] })))
    const wrapper = render()
    await flushPromises()

    for (const testid of ['edit-task', 'claim-task', 'assign-task', 'start-task', 'hold-task']) {
      expect(wrapper.find(`[data-testid="${testid}"]`).exists()).toBe(false)
    }
  })

  it('localizes the 404 error instead of exposing backend text', async () => {
    api.getTask.mockRejectedValueOnce({
      response: { status: 404, data: { message: 'No query results for model [App\\Models\\Task] 51' } },
    })
    api.listAuditLogs.mockResolvedValue(historyPage([]))
    const wrapper = render()
    await flushPromises()

    expect(wrapper.text()).toContain('任务不存在或已被删除')
    expect(wrapper.text()).not.toContain('No query results')
  })
})
