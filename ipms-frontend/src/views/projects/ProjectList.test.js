import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectList from './ProjectList.vue'

const {
  archiveProject,
  deleteProject,
  listProjects,
  messageError,
  messageSuccess,
  confirm,
  routerPush,
} = vi.hoisted(() => ({
  archiveProject: vi.fn(),
  deleteProject: vi.fn(),
  listProjects: vi.fn(),
  messageError: vi.fn(),
  messageSuccess: vi.fn(),
  confirm: vi.fn(),
  routerPush: vi.fn(),
}))

vi.mock('@/api/project', () => ({ archiveProject, deleteProject, listProjects }))
vi.mock('element-plus', () => ({
  ElMessage: { error: messageError, success: messageSuccess },
  ElMessageBox: { confirm },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRouter: () => ({ push: routerPush }),
}))
vi.mock('@/composables/usePermission', () => ({
  usePermission: () => ({
    isSuperAdmin: ref(true),
    canDelete: () => true,
    canPerform: (resource, action, localAllowed = true) => (
      localAllowed && resource.allowed_actions?.includes(action)
    ),
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

const stubs = {
  AsyncState: {
    props: ['loading', 'error', 'empty'],
    emits: ['retry'],
    template: '<div><slot v-if="!loading && !error && !empty" /><button v-if="error" data-testid="retry" @click="$emit(\'retry\')">retry</button><span v-if="empty">empty</span></div>',
  },
  ElButton,
  ElIcon: { template: '<i><slot /></i>' },
  ElInput: true,
  ElOption: true,
  ElPagination: true,
  ElSelect: true,
  ElTag: { template: '<span><slot /></span>' },
  ElTooltip: { template: '<span><slot /></span>' },
  Search: true,
  RefreshLeft: true,
  View: true,
  FolderChecked: true,
  Delete: true,
}

const response = (items) => ({
  data: {
    data: {
      items,
      page: 1,
      page_size: 20,
      total: items.length,
      total_pages: 1,
    },
  },
})

const project = (allowedActions = ['archive', 'delete']) => ({
  id: 17,
  name: '真实项目',
  system_type: 1,
  status_label: '进行中',
  manager: { display_name: '项目经理' },
  supplier_org: { name: '供应商组织' },
  counts: { requirements: 3, tasks: 4, defects: 1, versions: 2 },
  allowed_actions: allowedActions,
  updated_at: '2026-09-08T06:00:00Z',
})

function mountList() {
  return mount(ProjectList, { global: { stubs } })
}

describe('ProjectList real data workflow', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    listProjects.mockResolvedValue(response([project()]))
    archiveProject.mockResolvedValue({ data: { data: project([]) } })
    deleteProject.mockResolvedValue({ data: { data: null } })
    confirm.mockResolvedValue('confirm')
  })

  it('loads only the scoped project API and renders normalized records', async () => {
    const wrapper = mountList()
    await flushPromises()

    expect(listProjects).toHaveBeenCalledWith({ page: 1, page_size: 20 })
    expect(wrapper.text()).toContain('真实项目')
    expect(wrapper.text()).toContain('项目经理')
    expect(wrapper.text()).not.toContain('SAP B1')
  })

  it('uses allowed_actions for real archive and delete commands', async () => {
    const wrapper = mountList()
    await flushPromises()

    await wrapper.get('[data-testid="archive-17"]').trigger('click')
    await flushPromises()
    expect(archiveProject).toHaveBeenCalledWith(17)

    await wrapper.get('[data-testid="delete-17"]').trigger('click')
    await flushPromises()
    expect(deleteProject).toHaveBeenCalledWith(17)
    expect(listProjects).toHaveBeenCalledTimes(3)
  })

  it('hides state-changing commands absent from the server action list', async () => {
    listProjects.mockResolvedValueOnce(response([project([])]))
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.find('[data-testid="archive-17"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="delete-17"]').exists()).toBe(false)
  })
})
