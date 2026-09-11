import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'
import AuditLogView from './audit/AuditLogView.vue'
import OrganizationView from './organizations/OrganizationView.vue'

const api = vi.hoisted(() => ({
  listAuditLogs: vi.fn(), exportAuditLogs: vi.fn(), getOrgTree: vi.fn(),
  createOrgNode: vi.fn(), updateOrgNode: vi.fn(), deleteOrgNode: vi.fn(), addOrgUser: vi.fn(), removeOrgUser: vi.fn(),
  listUsers: vi.fn(),
}))
vi.mock('@/api/auditLog', () => api)
vi.mock('@/api/organization', () => api)
vi.mock('@/api/user', () => api)
const options = { global: { plugins: [ElementPlus], stubs: { WarningFilled: true } } }
beforeEach(() => {
  vi.clearAllMocks()
  api.listAuditLogs.mockResolvedValue({ data: { data: {
    items: [{ id: 91, user_display_name: '真实操作人', target_name: '数据库中的审计对象', module: 2, action_type: 1, created_at: '2026-09-08' }],
    page: 1, page_size: 20, total: 1, total_pages: 1,
  } } })
  api.getOrgTree.mockResolvedValue({ data: { data: [
    { id: 11, name: '数据库组织', org_type: 1, children: [], users: [{ id: 7, display_name: '数据库成员', username: 'member7' }] },
  ] } })
  api.listUsers.mockResolvedValue({ data: { data: { items: [], total_pages: 0 } } })
})
describe('administrative database views', () => {
  it('renders audit API rows and does not retain rows after a failed refresh', async () => {
    const wrapper = mount(AuditLogView, options)
    await flushPromises()
    expect(api.listAuditLogs).toHaveBeenCalledWith({ page: 1, page_size: 20 })
    expect(wrapper.text()).toContain('数据库中的审计对象')
    api.listAuditLogs.mockRejectedValueOnce({ response: { status: 403, data: { message: '权限失效' } } })
    await wrapper.get('[data-testid="reset-filters"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('数据库中的审计对象')
    expect(wrapper.text()).toContain('权限失效')
    wrapper.unmount()
  })
  it('loads numeric organization types and members from the actual tree contract', async () => {
    const wrapper = mount(OrganizationView, options)
    await flushPromises()
    expect(api.getOrgTree).toHaveBeenCalledWith({ org_type: 1 })
    expect(wrapper.text()).toContain('数据库组织')
    expect(wrapper.text()).toContain('数据库成员')
    wrapper.unmount()
  })
})
