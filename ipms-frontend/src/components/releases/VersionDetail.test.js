import { defineComponent, h } from 'vue'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VersionProgress from './VersionProgress.vue'
import ReleaseGatePanel from './ReleaseGatePanel.vue'
import RequirementPlanner from './RequirementPlanner.vue'
import ReleaseDialog from './ReleaseDialog.vue'
import ProjectVersionDetail from '@/views/releases/ProjectVersionDetail.vue'

const {
  getProjectVersion,
  checkProjectVersionGate,
  listProjectVersionHistory,
  listUnplannedRequirements,
  planRequirementVersion,
  unplanRequirementVersion,
  transitionProjectVersion,
  releaseProjectVersion,
  messageError,
  messageSuccess,
  messageWarning,
  routerPush,
  authState,
} = vi.hoisted(() => ({
  getProjectVersion: vi.fn(),
  checkProjectVersionGate: vi.fn(),
  listProjectVersionHistory: vi.fn(),
  listUnplannedRequirements: vi.fn(),
  planRequirementVersion: vi.fn(),
  unplanRequirementVersion: vi.fn(),
  transitionProjectVersion: vi.fn(),
  releaseProjectVersion: vi.fn(),
  messageError: vi.fn(),
  messageSuccess: vi.fn(),
  messageWarning: vi.fn(),
  routerPush: vi.fn(),
  authState: { isSuperAdmin: false },
}))

vi.mock('@/api/projectVersion', () => ({
  getProjectVersion,
  checkProjectVersionGate,
  listProjectVersionHistory,
  listUnplannedRequirements,
  planRequirementVersion,
  unplanRequirementVersion,
  transitionProjectVersion,
  releaseProjectVersion,
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authState,
}))
vi.mock('element-plus', () => ({
  ElMessage: {
    error: messageError,
    success: messageSuccess,
    warning: messageWarning,
  },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => ({ params: { id: '42' } }),
  useRouter: () => ({ push: routerPush }),
}))

const ElButton = defineComponent({
  name: 'ElButton',
  inheritAttrs: false,
  props: {
    disabled: Boolean,
    loading: Boolean,
  },
  emits: ['click'],
  setup(props, { attrs, emit, slots }) {
    return () => h('button', {
      ...attrs,
      disabled: props.disabled || props.loading,
      type: 'button',
      onClick: () => emit('click'),
    }, slots.default?.())
  },
})

const ElInput = defineComponent({
  name: 'ElInput',
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number], default: '' },
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('input', {
      ...attrs,
      value: props.modelValue,
      onInput: (event) => emit('update:modelValue', event.target.value),
    })
  },
})

const ReleaseDialogStub = defineComponent({
  name: 'ReleaseDialog',
  props: {
    modelValue: Boolean,
    mode: String,
    targetStatus: Object,
  },
  emits: ['confirm', 'update:modelValue'],
  template: '<div v-if="modelValue" data-testid="dialog-stub">{{ mode }}</div>',
})

const VersionHistoryStub = defineComponent({
  name: 'VersionHistory',
  props: {
    items: {
      type: Array,
      default: () => [],
    },
  },
  template: '<div data-testid="history-count">{{ items.length }}</div>',
})

const baseVersion = (overrides = {}) => ({
  id: 42,
  code: '2026.9',
  name: '九月发布',
  description: '数据库中的版本说明',
  status: 4,
  status_code: 'IN_TESTING',
  status_label: '测试中',
  lock_version: 3,
  project: { id: 9, name: '核心系统' },
  owner: { id: 7, display_name: '项目经理' },
  planned_start_date: '2026-09-01',
  planned_release_date: '2026-09-30',
  release_notes: '发布说明',
  counts: { requirements: 1, tasks: 2, defects: 1, histories: 2 },
  scope: [{
    requirement_project_id: 81,
    requirement_id: 18,
    project_id: 9,
    project_version_id: 42,
    delivery_status: 4,
    requirement: {
      id: 18,
      title: '版本范围需求',
      status: 4,
      priority: 2,
    },
  }],
  history: [],
  release_snapshot: null,
  gate_result: {
    target_status: 5,
    target_status_code: 'READY_TO_RELEASE',
    passed: true,
    checks: [],
    blocking: [],
  },
  allowed_actions: ['edit', 'transition', 'plan_requirements', 'force_release'],
  ...overrides,
})

function response(data) {
  return { data: { data } }
}

function pageResponse(items = []) {
  return response({
    items,
    page: 1,
    page_size: 100,
    total: items.length,
    total_pages: 1,
  })
}

function componentStubs() {
  return {
    ElButton,
    ElInput,
    ElTooltip: { template: '<span><slot /></span>' },
    ElEmpty: { template: '<div><slot /></div>' },
  }
}

describe('version release components', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.isSuperAdmin = false
    listUnplannedRequirements.mockResolvedValue(pageResponse([{
      id: 27,
      title: '未规划需求',
      priority_label: '高',
      status_label: '已分配',
    }]))
    planRequirementVersion.mockResolvedValue(response({ lock_version: 4 }))
    unplanRequirementVersion.mockResolvedValue(response({ lock_version: 4 }))
  })

  it('renders all seven version statuses in workflow order', () => {
    const wrapper = mount(VersionProgress, {
      props: { statusCode: 'IN_TESTING' },
    })
    const labels = wrapper.findAll('[data-testid="version-progress-label"]')
      .map((item) => item.text())

    expect(labels).toEqual([
      '草稿',
      '已计划',
      '开发中',
      '测试中',
      '待发布',
      '已发布',
      '已归档',
    ])
  })

  it('renders every blocking gate with counts and target links', () => {
    const wrapper = mount(ReleaseGatePanel, {
      props: {
        projectId: 9,
        versionId: 42,
        result: {
          passed: false,
          blocking: [
            {
              code: 'tasks_completed',
              label: '范围任务全部完成',
              details: { incomplete_count: 3 },
            },
            {
              code: 'severe_defects_closed',
              label: '严重缺陷全部关闭',
              details: { open_count: 2 },
            },
          ],
          checks: [],
        },
      },
    })

    expect(wrapper.text()).toContain('范围任务全部完成')
    expect(wrapper.text()).toContain('3')
    expect(wrapper.text()).toContain('严重缺陷全部关闭')
    expect(wrapper.text()).toContain('2')
    expect(wrapper.get('[data-testid="gate-link-tasks_completed"]').attributes('href'))
      .toContain('/tasks?')
    expect(wrapper.get('[data-testid="gate-link-severe_defects_closed"]').attributes('href'))
      .toContain('/defects?')
  })

  it('plans a requirement with the target version, lock, and testing reason', async () => {
    const wrapper = mount(RequirementPlanner, {
      props: { version: baseVersion() },
      global: { stubs: componentStubs() },
    })
    await flushPromises()

    await wrapper.get('[data-testid="planning-reason"]').setValue('补充回归范围')
    await wrapper.get('[data-testid="plan-requirement-27"]').trigger('click')
    await flushPromises()

    expect(planRequirementVersion).toHaveBeenCalledWith(27, 9, {
      project_version_id: 42,
      lock_version: 3,
      reason: '补充回归范围',
    })
  })

  it('requires a reason before testing-stage scope changes', async () => {
    const wrapper = mount(RequirementPlanner, {
      props: { version: baseVersion() },
      global: { stubs: componentStubs() },
    })
    await flushPromises()

    await wrapper.get('[data-testid="plan-requirement-27"]').trigger('click')

    expect(planRequirementVersion).not.toHaveBeenCalled()
    expect(messageError).toHaveBeenCalledWith('测试阶段调整范围必须填写原因')
  })

  it('renders released scope read-only with no planning controls', async () => {
    const wrapper = mount(RequirementPlanner, {
      props: {
        version: baseVersion({
          status: 6,
          status_code: 'RELEASED',
          status_label: '已发布',
          allowed_actions: [],
          release_snapshot: {
            requirement_scope: [{
              requirement_project_id: 81,
              requirement_id: 18,
              project_id: 9,
            }],
          },
        }),
      },
      global: { stubs: componentStubs() },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('版本范围需求')
    expect(wrapper.text()).toContain('只读')
    expect(wrapper.find('[data-testid="plan-requirement-27"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="unplan-requirement-18"]').exists()).toBe(false)
  })

  it('keeps normal release free of a force reason field', () => {
    const wrapper = mount(ReleaseDialog, {
      props: {
        modelValue: true,
        mode: 'release',
        version: baseVersion({ status: 5, status_code: 'READY_TO_RELEASE' }),
      },
      global: { stubs: { ElButton, ElInput } },
    })

    expect(wrapper.text()).toContain('确认发布')
    expect(wrapper.find('[data-testid="force-reason"]').exists()).toBe(false)
  })

  it('requires a non-empty reason for a forced release', async () => {
    const wrapper = mount(ReleaseDialog, {
      props: {
        modelValue: true,
        mode: 'force',
        version: baseVersion(),
      },
      global: { stubs: { ElButton, ElInput } },
    })

    await wrapper.get('[data-testid="confirm-release-command"]').trigger('click')
    expect(wrapper.emitted('confirm')).toBeUndefined()

    await wrapper.get('[data-testid="force-reason"]').setValue('生产紧急修复')
    await wrapper.get('[data-testid="confirm-release-command"]').trigger('click')

    expect(wrapper.emitted('confirm')?.[0]?.[0]).toMatchObject({
      force: true,
      force_reason: '生产紧急修复',
      lock_version: 3,
    })
  })
})

describe('ProjectVersionDetail command guards and recovery', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.isSuperAdmin = false
    getProjectVersion.mockResolvedValue(response(baseVersion()))
    checkProjectVersionGate.mockResolvedValue(response(baseVersion().gate_result))
    listProjectVersionHistory.mockResolvedValue(pageResponse([]))
    transitionProjectVersion.mockResolvedValue(response(baseVersion({ status: 5 })))
    releaseProjectVersion.mockResolvedValue(response(baseVersion({ status: 6 })))
  })

  function mountDetail() {
    return shallowMount(ProjectVersionDetail, {
      global: {
        stubs: {
          AsyncState: { template: '<div><slot /></div>' },
          VersionProgress: true,
          VersionStatusTag: true,
          ReleaseGatePanel: true,
          RequirementPlanner: true,
          VersionHistory: VersionHistoryStub,
          ReleaseDialog: ReleaseDialogStub,
          ElButton,
          ElTabs: { template: '<div><slot /></div>' },
          ElTabPane: { template: '<section><slot /></section>' },
          ElAlert: true,
          ElDescriptions: { template: '<dl><slot /></dl>' },
          ElDescriptionsItem: { template: '<div><slot /></div>' },
        },
      },
    })
  }

  it('loads every history page for the complete audit timeline', async () => {
    listProjectVersionHistory.mockImplementation((_id, params) => {
      if (params.page === 1) {
        return Promise.resolve(response({
          items: [{
            id: 2,
            event_type: 'status_forward',
            created_at: '2026-09-02T08:00:00Z',
          }],
          page: 1,
          page_size: 100,
          total: 2,
          total_pages: 2,
        }))
      }
      return Promise.resolve(response({
        items: [{
          id: 1,
          event_type: 'created',
          created_at: '2026-09-01T08:00:00Z',
        }],
        page: 2,
        page_size: 100,
        total: 2,
        total_pages: 2,
      }))
    })

    const wrapper = mountDetail()
    await flushPromises()

    expect(listProjectVersionHistory).toHaveBeenCalledWith('42', {
      page: 2,
      page_size: 100,
    })
    expect(wrapper.get('[data-testid="history-count"]').text()).toBe('2')
  })

  it('keeps released versions read-only', async () => {
    getProjectVersion.mockResolvedValue(response(baseVersion({
      status: 6,
      status_code: 'RELEASED',
      status_label: '已发布',
      allowed_actions: [],
      release_snapshot: {
        requirement_scope: [],
        task_count: 2,
        defect_count: 1,
        gate_result: { passed: true, checks: [], blocking: [] },
      },
    })))

    const wrapper = mountDetail()
    await flushPromises()

    expect(wrapper.attributes('data-status-code')).toBe('RELEASED')
    expect(wrapper.find('[data-testid="transition-command"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="release-command"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="force-release-command"]').exists()).toBe(false)
  })

  it('shows force release only to superadmins in testing or ready states', async () => {
    const nonAdmin = mountDetail()
    await flushPromises()
    expect(nonAdmin.find('[data-testid="force-release-command"]').exists()).toBe(false)

    authState.isSuperAdmin = true
    const superAdmin = mountDetail()
    await flushPromises()
    expect(superAdmin.find('[data-testid="force-release-command"]').exists()).toBe(true)

    getProjectVersion.mockResolvedValue(response(baseVersion({
      status: 2,
      status_code: 'PLANNED',
      allowed_actions: ['force_release'],
    })))
    const wrongStatus = mountDetail()
    await flushPromises()
    expect(wrongStatus.find('[data-testid="force-release-command"]').exists()).toBe(false)
  })

  it('closes a stale command, reloads all version data, and reports the conflict', async () => {
    authState.isSuperAdmin = true
    releaseProjectVersion.mockRejectedValue({
      response: {
        status: 409,
        data: {
          error_code: 'STALE_VERSION',
          message: '版本已被其他人更新',
          errors: { lock_version: { current: 4 } },
        },
      },
    })
    const wrapper = mountDetail()
    await flushPromises()

    await wrapper.get('[data-testid="force-release-command"]').trigger('click')
    await wrapper.findComponent(ReleaseDialogStub).vm.$emit('confirm', {
      force: true,
      force_reason: '紧急发布',
      lock_version: 3,
    })
    await flushPromises()

    expect(getProjectVersion).toHaveBeenCalledTimes(2)
    expect(checkProjectVersionGate).toHaveBeenCalledTimes(2)
    expect(listProjectVersionHistory).toHaveBeenCalledTimes(2)
    expect(messageWarning).toHaveBeenCalledWith('版本已被其他人更新')
    expect(wrapper.find('[data-testid="dialog-stub"]').exists()).toBe(false)
  })

  it('keeps a failed transition open and focuses the release gate tab', async () => {
    transitionProjectVersion.mockRejectedValue({
      response: {
        status: 409,
        data: {
          error_code: 'RELEASE_GATE_FAILED',
          message: '发布门禁未通过',
          errors: [{
            code: 'tasks_completed',
            label: '范围任务全部完成',
            details: { incomplete_count: 2 },
          }],
        },
      },
    })
    const wrapper = mountDetail()
    await flushPromises()

    await wrapper.get('[data-testid="transition-command"]').trigger('click')
    await wrapper.findComponent(ReleaseDialogStub).vm.$emit('confirm', {
      status: 5,
      lock_version: 3,
    })
    await flushPromises()

    expect(wrapper.attributes('data-active-tab')).toBe('release-gate')
    expect(wrapper.find('[data-testid="dialog-stub"]').exists()).toBe(true)
    expect(messageError).toHaveBeenCalledWith('发布门禁未通过')
  })
})
