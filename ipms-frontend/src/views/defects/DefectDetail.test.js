import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DefectDetail from './DefectDetail.vue'

const api = vi.hoisted(() => ({
  getDefect: vi.fn(),
  confirmDefect: vi.fn(),
  resolveDefect: vi.fn(),
  verifyDefect: vi.fn(),
  reopenDefect: vi.fn(),
  uploadDefectAttachment: vi.fn(),
  downloadDefectAttachment: vi.fn(),
  deleteDefectAttachment: vi.fn(),
  listAuditLogs: vi.fn(),
  messageSuccess: vi.fn(),
  messageError: vi.fn(),
  confirm: vi.fn(),
  prompt: vi.fn(),
}))
vi.mock('@/api/defect', () => ({
  getDefect: api.getDefect,
  confirmDefect: api.confirmDefect,
  resolveDefect: api.resolveDefect,
  verifyDefect: api.verifyDefect,
  reopenDefect: api.reopenDefect,
  uploadDefectAttachment: api.uploadDefectAttachment,
  downloadDefectAttachment: api.downloadDefectAttachment,
  deleteDefectAttachment: api.deleteDefectAttachment,
}))
vi.mock('@/api/auditLog', () => ({ listAuditLogs: api.listAuditLogs }))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRoute: () => ({ params: { id: '61' } }),
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
const defect = (overrides = {}) => ({
  id: 61,
  title: '权限校验失败',
  description: '长描述'.repeat(50),
  status: 4,
  status_code: 'PENDING_RETEST',
  status_label: '待复测',
  severity: 2,
  severity_label: '严重(P1)',
  defect_type: 1,
  discovery_phase: 2,
  discovered_at: '2026-09-08T08:00:00Z',
  requirement: { id: 41, title: '数据库迁移需求' },
  project: { id: 7, name: 'ERP 升级' },
  reporter: { id: 3, display_name: '测试人员' },
  assignee: { id: 8, display_name: '开发人员' },
  fix_description: '已补充鉴权拦截',
  screenshot: null,
  closed_at: null,
  attachments: [
    {
      id: 90,
      filename: '复现步骤.png',
      file_size: 2048,
      file_type: 'image/png',
      uploaded_by: { id: 3, display_name: '测试人员' },
      uploaded_at: '2026-09-08T09:00:00Z',
    },
  ],
  allowed_actions: ['verify'],
  created_at: '2026-09-08T08:00:00Z',
  updated_at: '2026-09-08T08:00:00Z',
  ...overrides,
})
const historyPage = (items) => response({ items, page: 1, page_size: 50, total: items.length, total_pages: 1 })

const render = () => shallowMount(DefectDetail, {
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

describe('DefectDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getDefect.mockResolvedValue(response(defect()))
    api.listAuditLogs.mockResolvedValue(historyPage([
      {
        id: 1,
        user_display_name: '开发人员',
        action_type: 4,
        detail: { from_status: 3, to_status: 4 },
        created_at: '2026-09-09T01:00:00Z',
      },
    ]))
    api.confirm.mockResolvedValue('confirm')
    api.prompt.mockResolvedValue({ value: '复测通过' })
    api.verifyDefect.mockResolvedValue({})
  })

  it('renders full description, fix notes, attachments and history', async () => {
    const wrapper = render()
    await flushPromises()

    expect(api.getDefect).toHaveBeenCalledWith('61')
    expect(api.listAuditLogs).toHaveBeenCalledWith(expect.objectContaining({
      module: 4,
      target_type: 'defect',
      target_id: 61,
    }))
    expect(wrapper.text()).toContain('权限校验失败')
    expect(wrapper.text()).toContain('已补充鉴权拦截')
    expect(wrapper.text()).toContain('复现步骤.png')
    expect(wrapper.text()).toContain('2.0 KB')
    expect(wrapper.text()).toContain('修复中 → 待复测')
    expect(wrapper.get('a[href="/requirements/41"]').text()).toContain('数据库迁移需求')
  })

  it('runs the verify flow from the actions area', async () => {
    const wrapper = render()
    await flushPromises()

    expect(wrapper.find('[data-testid="verify-pass-defect"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="resolve-defect"]').exists()).toBe(false)

    await wrapper.get('[data-testid="verify-pass-defect"]').trigger('click')
    await flushPromises()
    expect(api.prompt).toHaveBeenCalled()
    expect(api.verifyDefect).toHaveBeenCalledWith(61, { result: 'pass', comment: '复测通过' })
    expect(api.getDefect).toHaveBeenCalledTimes(2)
  })

  it('removes an attachment after confirmation', async () => {
    api.deleteDefectAttachment.mockResolvedValue({})
    const wrapper = render()
    await flushPromises()

    await wrapper.get('[data-testid="delete-attachment-90"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalled()
    expect(api.deleteDefectAttachment).toHaveBeenCalledWith(90)
  })

  it('localizes the 404 error instead of exposing backend text', async () => {
    api.getDefect.mockRejectedValueOnce({
      response: { status: 404, data: { message: 'No query results for model [App\\Models\\Defect] 61' } },
    })
    api.listAuditLogs.mockResolvedValue(historyPage([]))
    const wrapper = render()
    await flushPromises()

    expect(wrapper.text()).toContain('缺陷不存在或已被删除')
    expect(wrapper.text()).not.toContain('No query results')
  })
})
