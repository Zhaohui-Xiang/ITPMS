import { defineComponent, h } from 'vue'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectDeliveryTable from './ProjectDeliveryTable.vue'
import RequirementDetail from '@/views/requirements/RequirementDetail.vue'

const {
  getRequirement,
  getRequirementVersions,
  transitionRequirement,
  reviewRequirement,
  listTasks,
  listDefects,
  messageSuccess,
  messageError,
  confirmAction,
  routerBack,
  routerPush,
} = vi.hoisted(() => ({
  getRequirement: vi.fn(),
  getRequirementVersions: vi.fn(),
  transitionRequirement: vi.fn(),
  reviewRequirement: vi.fn(),
  listTasks: vi.fn(),
  listDefects: vi.fn(),
  messageSuccess: vi.fn(),
  messageError: vi.fn(),
  confirmAction: vi.fn(),
  routerBack: vi.fn(),
  routerPush: vi.fn(),
}))

vi.mock('@/api/requirement', () => ({
  getRequirement,
  getRequirementVersions,
  transitionRequirement,
  reviewRequirement,
}))
vi.mock('@/api/task', () => ({ listTasks }))
vi.mock('@/api/defect', () => ({ listDefects }))
vi.mock('element-plus', () => ({
  ElMessage: {
    success: messageSuccess,
    error: messageError,
  },
  ElMessageBox: {
    confirm: confirmAction,
  },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => ({ params: { id: '88' } }),
  useRouter: () => ({ back: routerBack, push: routerPush }),
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

const deliveries = [
  {
    project: { id: 11, name: '采购平台' },
    delivery_status: 3,
    delivery_status_code: 'IN_DEVELOPMENT',
    delivery_status_label: '开发中',
    target_version: {
      id: 101,
      code: 'PUR-2.4',
      name: '采购二期',
      status_code: 'IN_DEVELOPMENT',
    },
    owner: { id: 5, display_name: '周经理' },
    task_progress: { total: 5, completed: 2 },
    open_severe_defect_count: 1,
    can_view_project: true,
    allowed_actions: ['transition'],
  },
  {
    project: { id: 12, name: '财务平台' },
    delivery_status: 6,
    delivery_status_code: 'DEPLOYED',
    delivery_status_label: '已上线',
    target_version: {
      id: 102,
      code: 'FIN-1.8',
      name: '财务升级',
      status_code: 'RELEASED',
    },
    owner: { id: 6, display_name: '陈经理' },
    task_progress: { total: 3, completed: 3 },
    open_severe_defect_count: 0,
    can_view_project: true,
    allowed_actions: [],
  },
]

const requirement = {
  id: 88,
  title: '统一付款审批',
  description: '来自需求数据库',
  status: 3,
  status_code: 'IN_DEVELOPMENT',
  status_label: '开发中',
  priority: 2,
  priority_code: 'HIGH',
  priority_label: '高',
  requirement_type: 1,
  submitter: { id: 1, display_name: '业务用户' },
  reviewer: { id: 2, display_name: '审核经理' },
  dev_lead: { id: 3, display_name: '开发负责人' },
  expected_completion_date: '2026-10-31',
  attachments: [],
  project_deliveries: deliveries,
  allowed_actions: ['edit', 'transition_project', 'create_task'],
  created_at: '2026-09-01T08:00:00Z',
}

function response(data) {
  return { data: { data } }
}

function page(items) {
  return response({
    items,
    page: 1,
    page_size: 100,
    total: items.length,
    total_pages: 1,
  })
}

const DeliveryTableStub = defineComponent({
  name: 'ProjectDeliveryTable',
  props: {
    deliveries: {
      type: Array,
      default: () => [],
    },
  },
  emits: ['transition'],
  template: '<div><span v-for="item in deliveries" :key="item.project.id">{{ item.project.name }}</span></div>',
})

describe('ProjectDeliveryTable', () => {
  it('renders independent project states and both authorized target version links', () => {
    const wrapper = mount(ProjectDeliveryTable, {
      props: { deliveries },
      global: {
        stubs: {
          ElButton,
          ElProgress: {
            props: ['percentage'],
            template: '<span>{{ percentage }}%</span>',
          },
        },
      },
    })

    expect(wrapper.text()).toContain('采购平台')
    expect(wrapper.text()).toContain('开发中')
    expect(wrapper.text()).toContain('PUR-2.4')
    expect(wrapper.text()).toContain('周经理')
    expect(wrapper.text()).toContain('2 / 5')
    expect(wrapper.text()).toContain('财务平台')
    expect(wrapper.text()).toContain('已上线')
    expect(wrapper.text()).toContain('FIN-1.8')
    expect(wrapper.text()).toContain('陈经理')
    expect(wrapper.findAll('[data-testid="target-version-link"]')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('总体状态')
  })

  it('hides project actions and the target link without row authorization', () => {
    const restricted = {
      ...deliveries[0],
      can_view_project: false,
      allowed_actions: [],
    }
    const wrapper = mount(ProjectDeliveryTable, {
      props: { deliveries: [restricted] },
      global: { stubs: { ElButton } },
    })

    expect(wrapper.find('[data-testid="target-version-link"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="transition-project-11"]').exists()).toBe(false)
  })
})

describe('RequirementDetail live aggregate', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getRequirement.mockResolvedValue(response(requirement))
    getRequirementVersions.mockResolvedValue(response([{
      id: 900,
      version_number: 2,
      change_summary: '更新验收范围',
      changes: [],
      changed_by: { id: 2, display_name: '审核经理' },
      changed_at: '2026-09-03T08:00:00Z',
    }]))
    listTasks.mockResolvedValue(page([{
      id: 201,
      title: '接口任务',
      project: { id: 11, name: '采购平台' },
      assignee: { id: 9, display_name: '开发工程师' },
      status_code: 'IN_PROGRESS',
      status_label: '进行中',
    }]))
    listDefects.mockResolvedValue(page([{
      id: 301,
      title: '审批校验缺陷',
      project: { id: 12, name: '财务平台' },
      severity_label: '严重',
      status_code: 'CONFIRMED',
      status_label: '已确认',
    }]))
    transitionRequirement.mockResolvedValue(response({
      ...requirement,
      project_deliveries: [{
        ...deliveries[0],
        delivery_status: 4,
        delivery_status_code: 'IN_TESTING',
        delivery_status_label: '测试中',
      }, deliveries[1]],
    }))
    confirmAction.mockResolvedValue('confirm')
  })

  function mountDetail() {
    return shallowMount(RequirementDetail, {
      global: {
        stubs: {
          AsyncState: { template: '<div><slot /></div>' },
          ProjectDeliveryTable: DeliveryTableStub,
          StatusTag: {
            props: ['statusLabel'],
            template: '<span>{{ statusLabel }}</span>',
          },
          ElButton,
          ElDescriptions: { template: '<dl><slot /></dl>' },
          ElDescriptionsItem: { template: '<div><slot /></div>' },
          ElTable: { template: '<div><slot /></div>' },
          ElTableColumn: true,
          ElTimeline: { template: '<ol><slot /></ol>' },
          ElTimelineItem: { template: '<li><slot /></li>' },
          ElEmpty: true,
          ElInput: true,
          ElDialog: { template: '<div><slot /><slot name="footer" /></div>' },
          ElTag: { template: '<span><slot /></span>' },
        },
      },
    })
  }

  it('loads database-backed detail sections and renders aggregate status once', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(getRequirement).toHaveBeenCalledWith('88')
    expect(listTasks).toHaveBeenCalledWith({
      requirement_id: '88',
      page: 1,
      page_size: 100,
    })
    expect(listDefects).toHaveBeenCalledWith({
      requirement_id: '88',
      page: 1,
      page_size: 100,
    })
    expect(getRequirementVersions).toHaveBeenCalledWith('88')
    expect(wrapper.get('[data-testid="aggregate-status"]').text()).toContain('开发中')
    expect(wrapper.findAll('[data-testid="aggregate-status"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('采购平台')
    expect(wrapper.text()).toContain('财务平台')
    expect(wrapper.text()).toContain('接口任务')
    expect(wrapper.text()).toContain('审批校验缺陷')
    expect(wrapper.text()).toContain('更新验收范围')
    expect(wrapper.text()).not.toContain('新增财务报表功能')
  })

  it('sends project_id when advancing one project delivery', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    await wrapper.findComponent(DeliveryTableStub).vm.$emit('transition', deliveries[0])
    await flushPromises()

    expect(confirmAction).toHaveBeenCalledWith(
      expect.stringContaining('采购平台'),
      '推进项目交付',
      expect.any(Object),
    )
    expect(transitionRequirement).toHaveBeenCalledWith(88, {
      project_id: 11,
      status: 4,
    })
  })

  it('does not render review commands without the server action', async () => {
    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.find('[data-testid="approve-requirement"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reject-requirement"]').exists()).toBe(false)
  })
})
