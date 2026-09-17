import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectList from './ProjectList.vue'

const {
  archiveProject,
  createProject,
  listUsers,
  getOrgTree,
  permissions,
  deleteProject,
  listProjects,
  messageError,
  messageSuccess,
  confirm,
  routerPush,
} = vi.hoisted(() => ({
  archiveProject: vi.fn(),
  createProject: vi.fn(),
  listUsers: vi.fn(),
  getOrgTree: vi.fn(),
  permissions: { superAdmin: true },
  deleteProject: vi.fn(),
  listProjects: vi.fn(),
  messageError: vi.fn(),
  messageSuccess: vi.fn(),
  confirm: vi.fn(),
  routerPush: vi.fn(),
}))

vi.mock('@/api/project', () => ({ archiveProject, createProject, deleteProject, listProjects }))
vi.mock('@/api/user', () => ({ listUsers }))
vi.mock('@/api/organization', () => ({ getOrgTree }))
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
    isSuperAdmin: ref(permissions.superAdmin),
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
  ElInput: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
  ElDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /><slot name="footer" /></div>' },
  ElForm: { template: '<form><slot /></form>' },
  ElFormItem: { template: '<div><slot /></div>' },
  ElOption: { props: ['value', 'label'], template: '<option :value="value">{{ label }}</option>' },
  ElPagination: true,
  ElSelect: { props: ['modelValue'], emits: ['update:modelValue', 'change'], template: '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value); $emit(\'change\', $event.target.value)"><slot /></select>' },
  ElTag: { template: '<span><slot /></span>' },
  ElTooltip: { template: '<span><slot /></span>' },
  Search: true,
  RefreshLeft: true,
  View: true,
  FolderChecked: true,
  Delete: true,
}

const response = (items, pagination = {}) => ({
  data: {
    data: {
      items,
      page: pagination.page ?? 1,
      page_size: pagination.page_size ?? 20,
      total: pagination.total ?? items.length,
      total_pages: pagination.total_pages ?? 1,
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
  it('exposes project creation to superadmin', async () => {
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.find('[data-testid="create-project"]').exists()).toBe(true)
  })

  it('offers a direct project-scoped version directory entry', async () => {
    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-testid="versions-17"]').trigger('click')
    expect(routerPush).toHaveBeenCalledWith('/projects/17/versions')
  })

  beforeEach(() => {
    vi.clearAllMocks()
    permissions.superAdmin = true
    createProject.mockResolvedValue({ data: { data: { id: 31, name: 'New project' } } })
    listUsers.mockResolvedValue(response([{ id: 9, display_name: 'IT PM', user_type: 1, is_active: true, is_disabled: false, roles: [{ code: 'it_pm' }] },
      { id: 10, display_name: 'Developer', is_active: true, roles: [{ code: 'it_member' }] }]))
    getOrgTree.mockResolvedValue({ data: { data: [{ id: 2, name: 'Supplier', children: [] }] } })
    listProjects.mockResolvedValue(response([project()]))
    archiveProject.mockResolvedValue({ data: { data: project([]) } })
    deleteProject.mockResolvedValue({ data: { data: null } })
    confirm.mockResolvedValue('confirm')
  })

  it('hides project creation from non-admin roles', async () => {
    permissions.superAdmin = false
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.find('[data-testid="create-project"]').exists()).toBe(false)
    expect(listUsers).not.toHaveBeenCalled()
  })

  it('creates a project from real directory options and reloads the list', async () => {
    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-testid="create-project"]').trigger('click')
    await flushPromises()
    expect(listUsers).toHaveBeenCalledWith({ user_type: 1, page: 1, page_size: 100 })
    expect(wrapper.get('[data-testid="project-manager"]').text()).toContain('IT PM')
    expect(wrapper.get('[data-testid="project-manager"]').text()).not.toContain('Developer')
    await wrapper.get('[data-testid="project-name"]').setValue(' New project ')
    await wrapper.get('[data-testid="project-manager"]').setValue('9')
    await wrapper.get('[data-testid="save-project"]').trigger('click')
    await flushPromises()
    expect(createProject).toHaveBeenCalledWith({ name: 'New project', description: '', system_type: 2, manager_id: 9, supplier_org_id: null })
    expect(listProjects).toHaveBeenCalledTimes(2)
  })

  it('retains user input when creation fails', async () => {
    createProject.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Duplicate project' } } })
    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-testid="create-project"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="project-name"]').setValue('New project')
    await wrapper.get('[data-testid="project-manager"]').setValue('9')
    await wrapper.get('[data-testid="save-project"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="project-name"]').element.value).toBe('New project')
    expect(wrapper.text()).toContain('Duplicate project')
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

  it('reloads the last valid page when an action empties the current page', async () => {
    listProjects
      .mockResolvedValueOnce(response([project()], {
        page: 2,
        total: 21,
        total_pages: 2,
      }))
      .mockResolvedValueOnce(response([], {
        page: 2,
        total: 20,
        total_pages: 1,
      }))
      .mockResolvedValueOnce(response([project([])], {
        page: 1,
        total: 20,
        total_pages: 1,
      }))

    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-testid="delete-17"]').trigger('click')
    await flushPromises()

    expect(listProjects).toHaveBeenNthCalledWith(2, { page: 2, page_size: 20 })
    expect(listProjects).toHaveBeenNthCalledWith(3, { page: 1, page_size: 20 })
  })

  it('hides state-changing commands absent from the server action list', async () => {
    listProjects.mockResolvedValueOnce(response([project([])]))
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.find('[data-testid="archive-17"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="delete-17"]').exists()).toBe(false)
  })
})
