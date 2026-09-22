import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectDetail from './ProjectDetail.vue'
import ProjectMembers from '@/components/projects/ProjectMembers.vue'
const route = vi.hoisted(() => ({ params: { id: '12' }, query: {} }))

const {
  getProject,
  listRequirements,
  listDefects,
  routerPush,
  routerReplace,
  messageError,
} = vi.hoisted(() => ({
  getProject: vi.fn(),
  listRequirements: vi.fn(),
  listDefects: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
  messageError: vi.fn(),
}))

vi.mock('@/api/project', () => ({ getProject }))
vi.mock('@/api/requirement', () => ({ listRequirements }))
vi.mock('@/api/defect', () => ({ listDefects }))
vi.mock('element-plus', () => ({
  ElMessage: { error: messageError },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => route,
  useRouter: () => ({ push: routerPush, replace: routerReplace }),
}))

const paginated = (items) => ({
  data: {
    data: {
      items,
      page: 1,
      page_size: 10,
      total: items.length,
      total_pages: 1,
    },
  },
})

describe('ProjectDetail live project workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route.query = {}
    getProject.mockResolvedValue({
      data: {
        data: {
          id: 12,
          name: '数据库项目',
          system_type: 2,
          status: 1,
          status_label: '活跃',
          description: '来自项目接口',
          manager: { id: 7, display_name: '项目经理' },
          supplier_org: { id: 5, name: '交付供应商' },
          counts: { requirements: 1, tasks: 2, defects: 1, versions: 3 },
          version_counts: { total: 3, by_status: { DRAFT: 1, RELEASED: 2 } },
          allowed_actions: ['edit', 'create_version'],
          created_at: '2026-09-01T08:00:00Z',
        },
      },
    })
    listRequirements.mockResolvedValue(paginated([{
      id: 81,
      title: '真实需求',
      status_code: 'IN_DEVELOPMENT',
      status_label: '开发中',
    }]))
    listDefects.mockResolvedValue(paginated([{
      id: 91,
      title: '真实缺陷',
      status_code: 'CONFIRMED',
      status_label: '已确认',
    }]))
  })

  it('loads project, requirement, and defect data without static fallbacks', async () => {
    const wrapper = shallowMount(ProjectDetail, {
      global: {
        stubs: {
          ElButton: {
            template: '<button><slot /></button>',
          },
        },
      },
    })
    await flushPromises()

    expect(getProject).toHaveBeenCalledWith('12')
    expect(listRequirements).toHaveBeenCalledWith({
      project_id: '12',
      page: 1,
      page_size: 10,
    })
    expect(listDefects).toHaveBeenCalledWith({
      project_id: '12',
      page: 1,
      page_size: 10,
    })
    expect(wrapper.text()).toContain('数据库项目')
    expect(wrapper.text()).not.toContain('SAP B1')
  })
  it('shows the membership tab and renders the scoped panel for managers', async () => {
    const project = { id: 12, name: 'Managed project', allowed_actions: ['manage_members'] }
    getProject.mockResolvedValue({ data: { data: project } })
    route.query = { tab: 'members' }
    const wrapper = shallowMount(ProjectDetail, { global: { stubs: {
      ElButton: { template: '<button><slot /></button>' },
      AsyncState: { template: '<div><slot /></div>' },
    } } })
    await flushPromises()
    const tab = wrapper.findAll('nav button').find(button => button.text() === '项目成员')
    expect(tab).toBeDefined()
    expect(wrapper.findComponent(ProjectMembers).props('project')).toEqual(project)
    await tab.trigger('click')
    expect(routerReplace).toHaveBeenCalledWith({ name: 'ProjectDetail', params: { id: '12' }, query: { tab: 'members' } })
  })
  it('does not expose membership via a forged tab query without manage_members', async () => {
    route.query = { tab: 'members' }
    const wrapper = shallowMount(ProjectDetail, { global: { stubs: {
      ElButton: { template: '<button><slot /></button>' },
      AsyncState: { template: '<div><slot /></div>' },
    } } })
    await flushPromises()
    expect(wrapper.findComponent(ProjectMembers).exists()).toBe(false)
    expect(wrapper.findAll('nav button').some(button => button.text() === '项目成员')).toBe(false)
    expect(wrapper.find('.overview-layout').exists()).toBe(true)
  })
})
