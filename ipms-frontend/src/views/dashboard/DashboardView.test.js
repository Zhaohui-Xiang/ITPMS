import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/auth'
import DashboardView from './DashboardView.vue'
import {
  getDashboardStats,
  getDashboardSummary,
  getRecentRequirements,
  getRecentTasks,
} from '@/api/dashboard'

const { push } = vi.hoisted(() => ({
  push: vi.fn(),
}))

vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRouter: () => ({ push }),
}))

vi.mock('@/api/dashboard', () => ({
  getDashboardSummary: vi.fn(),
  getDashboardStats: vi.fn(),
  getRecentRequirements: vi.fn(),
  getRecentTasks: vi.fn(),
}))

const ElButton = {
  inheritAttrs: false,
  emits: ['click'],
  template: '<button v-bind="$attrs" type="button" @click="$emit(\'click\')"><slot /></button>',
}
const ElTag = {
  props: ['type', 'size'],
  template: '<span class="el-tag"><slot /></span>',
}
const ElEmpty = {
  props: ['description'],
  template: '<div class="el-empty">{{ description }}<slot /></div>',
}

const stubs = {
  ElButton,
  ElTag,
  ElEmpty,
  ElCard: { template: '<section><slot name="header" /><slot /></section>' },
  ElCol: { template: '<div><slot /></div>' },
  ElIcon: { template: '<i><slot /></i>' },
  ElRow: { template: '<div><slot /></div>' },
  ElSkeleton: { template: '<div class="el-skeleton" />' },
  ElTable: { template: '<div><slot /></div>' },
  ElTableColumn: { template: '<div />' },
  WarningFilled: true,
  ArrowRight: true,
  Calendar: true,
}

function response(summary) {
  return { data: { code: 200, message: 'success', data: summary } }
}

function emptySummary(metrics = []) {
  return {
    metrics,
    priority_queue: [],
    release_risks: [],
  }
}

function mountDashboard(role) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.user = {
    id: 1,
    display_name: 'Dashboard User',
    roles: [role],
    user_type: role === 'requester' ? 3 : role.startsWith('supplier_') ? 2 : 1,
  }

  return mount(DashboardView, {
    global: {
      plugins: [pinia],
      stubs,
    },
  })
}

describe('DashboardView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it.each([
    ['it_pm', '内部 IT 项目经理', '待审核需求'],
    ['supplier_dev', '供应商开发人员', '我的临期任务'],
    ['requester', '系统用户', '审核中需求'],
  ])('renders server metrics and role label for %s', async (role, roleLabel, metricLabel) => {
    getDashboardSummary.mockResolvedValue(response(emptySummary([
      {
        key: 'role_metric',
        label: metricLabel,
        value: 3,
        target_url: '/requirements',
      },
    ])))

    const wrapper = mountDashboard(role)
    await flushPromises()

    expect(wrapper.get('[data-testid="dashboard-role"]').text()).toBe(roleLabel)
    expect(wrapper.get('[data-testid="metric-role_metric"]').text()).toContain(metricLabel)
    expect(wrapper.get('[data-testid="metric-role_metric"]').text()).toContain('3')
    expect(getDashboardSummary).toHaveBeenCalledTimes(1)
    expect(getDashboardStats).not.toHaveBeenCalled()
    expect(getRecentRequirements).not.toHaveBeenCalled()
    expect(getRecentTasks).not.toHaveBeenCalled()
  })

  it('navigates queue records with the server target_url', async () => {
    getDashboardSummary.mockResolvedValue(response({
      metrics: [],
      priority_queue: [{
        type: 'task',
        id: 17,
        title: 'Complete workflow task',
        project: 'Operations',
        due_at: '2026-09-08',
        severity: 'high',
        target_url: '/tasks',
      }],
      release_risks: [{
        type: 'project_version',
        id: 23,
        title: 'R2026.09 September release',
        project: 'Operations',
        due_at: '2026-09-09',
        severity: 'critical',
        target_url: '/projects/8/versions',
      }],
    }))

    const wrapper = mountDashboard('it_pm')
    await flushPromises()
    await wrapper.get('[data-testid="queue-link-task-17"]').trigger('click')
    await wrapper.get('[data-testid="queue-link-project_version-23"]').trigger('click')

    expect(push).toHaveBeenNthCalledWith(1, '/tasks')
    expect(push).toHaveBeenNthCalledWith(2, '/projects/8/versions')
  })

  it('renders a request failure and retries the summary', async () => {
    getDashboardSummary
      .mockRejectedValueOnce(new Error('Dashboard unavailable'))
      .mockResolvedValueOnce(response(emptySummary()))

    const wrapper = mountDashboard('it_pm')
    await flushPromises()

    expect(wrapper.get('[data-state="error"]').text()).toContain('Dashboard unavailable')
    await wrapper.get('[data-testid="retry"]').trigger('click')
    await flushPromises()

    expect(getDashboardSummary).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-state="error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('暂无优先事项')
    expect(wrapper.text()).toContain('暂无发布风险')
  })

  it('renders independent empty states without legacy mock records', async () => {
    getDashboardSummary.mockResolvedValue(response(emptySummary()))

    const wrapper = mountDashboard('requester')
    await flushPromises()

    expect(wrapper.text()).toContain('暂无优先事项')
    expect(wrapper.text()).toContain('暂无发布风险')
    expect(wrapper.text()).not.toContain('新增财务报表功能')
    expect(wrapper.text()).not.toContain('SAP B1')
    expect(wrapper.text()).not.toContain('2026-08-02')
  })
})
