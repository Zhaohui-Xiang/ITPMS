import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectVersionsView from './ProjectVersionsView.vue'

const api = vi.hoisted(() => ({
  getProject: vi.fn(),
  listProjectVersions: vi.fn(),
  createProjectVersion: vi.fn(),
  messageError: vi.fn(),
  messageSuccess: vi.fn(),
}))
vi.mock('@/api/project', () => ({ getProject: api.getProject }))
vi.mock('@/api/projectVersion', () => ({
  listProjectVersions: api.listProjectVersions,
  createProjectVersion: api.createProjectVersion,
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => ({ params: { projectId: '9' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
}))
vi.mock('element-plus', () => ({
  ElMessage: { error: api.messageError, success: api.messageSuccess },
}))

const response = (data) => ({ data: { data } })
const emptyPage = response({ items: [], page: 1, page_size: 20, total: 0, total_pages: 1 })
const project = (allowedActions) => ({
  id: 9,
  name: '核心业务平台',
  manager: { id: 7, display_name: 'IT PM' },
  allowed_actions: allowedActions,
})

const render = () => mount(ProjectVersionsView, {
  global: {
    stubs: {
      AsyncState: { props: ['loading', 'error', 'empty'], template: '<div><slot v-if="!loading && !error" /></div>' },
      VersionListPanel: true,
      ElDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /><slot name="footer" /></div>' },
      ElForm: { template: '<form><slot /></form>' },
      ElFormItem: { template: '<div><slot /></div>' },
      ElInput: true,
      ElSelect: true,
      ElOption: true,
      ElDatePicker: true,
      ElButton: { template: '<button><slot /></button>' },
      ElIcon: true,
      ElEmpty: true,
      ElSkeleton: true,
      ElPagination: true,
      ArrowLeft: true,
      Plus: true,
      RefreshLeft: true,
      Search: true,
    },
  },
})

describe('ProjectVersionsView create entry', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.listProjectVersions.mockResolvedValue(emptyPage)
  })

  it('shows the create-version entry in the header when allowed', async () => {
    api.getProject.mockResolvedValue(response(project(['create_version'])))
    const wrapper = render()
    await flushPromises()

    const button = wrapper.findAll('button').find((item) => item.text().includes('新建版本'))
    expect(button).toBeDefined()
  })

  it('hides the create-version entry without the create_version action', async () => {
    api.getProject.mockResolvedValue(response(project([])))
    const wrapper = render()
    await flushPromises()

    const button = wrapper.findAll('button').find((item) => item.text().includes('新建版本'))
    expect(button).toBeUndefined()
  })
})
