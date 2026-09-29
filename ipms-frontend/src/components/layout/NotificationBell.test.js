import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationBell from './NotificationBell.vue'

const api = vi.hoisted(() => ({
  getUnreadCount: vi.fn(),
  listInbox: vi.fn(),
  markNotificationRead: vi.fn(),
  markAllNotificationsRead: vi.fn(),
  push: vi.fn(),
}))
vi.mock('@/api/notification', () => ({
  getUnreadCount: api.getUnreadCount,
  listInbox: api.listInbox,
  markNotificationRead: api.markNotificationRead,
  markAllNotificationsRead: api.markAllNotificationsRead,
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal(),
  useRouter: () => ({ push: api.push }),
}))
vi.mock('element-plus', () => ({
  ElMessage: { error: vi.fn(), success: vi.fn() },
}))

const stubs = {
  ElBadge: { props: ['value', 'hidden'], template: '<span class="badge" :data-value="value" :data-hidden="hidden"><slot /></span>' },
  ElButton: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
  ElDropdown: {
    emits: ['visible-change'],
    template: '<div class="dropdown"><slot /><slot name="dropdown" /></div>',
  },
  ElIcon: { template: '<i><slot /></i>' },
  Bell: true,
}

const item = (id, read = false) => ({
  id,
  title: `通知 ${id}`,
  body: '缺陷已分配给你',
  target_url: `/defects/${id}`,
  read_at: read ? '2026-09-20T08:00:00Z' : null,
  created_at: '2026-09-20T07:00:00Z',
})

describe('NotificationBell', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getUnreadCount.mockResolvedValue({ data: { data: { unread_count: 2 } } })
    api.listInbox.mockResolvedValue({ data: { data: { items: [item(1), item(2, true)], page: 1, total: 2 } } })
    api.markNotificationRead.mockResolvedValue({})
    api.markAllNotificationsRead.mockResolvedValue({})
  })

  it('shows the unread count on the badge', async () => {
    const wrapper = mount(NotificationBell, { global: { stubs } })
    await flushPromises()

    expect(wrapper.get('.badge').attributes('data-value')).toBe('2')
    expect(wrapper.get('.badge').attributes('data-hidden')).toBe('false')
  })

  it('loads inbox on open and navigates with mark-read on click', async () => {
    const wrapper = mount(NotificationBell, { global: { stubs } })
    await flushPromises()

    wrapper.findComponent(stubs.ElDropdown).vm.$emit('visible-change', true)
    await flushPromises()
    expect(api.listInbox).toHaveBeenCalledWith({ page: 1, page_size: 10 })
    expect(wrapper.text()).toContain('通知 1')

    await wrapper.get('[data-testid="notification-1"] .inbox-link').trigger('click')
    await flushPromises()
    expect(api.markNotificationRead).toHaveBeenCalledWith(1)
    expect(api.push).toHaveBeenCalledWith('/defects/1')
  })

  it('marks all as read and clears the badge', async () => {
    const wrapper = mount(NotificationBell, { global: { stubs } })
    await flushPromises()

    wrapper.findComponent(stubs.ElDropdown).vm.$emit('visible-change', true)
    await flushPromises()
    await wrapper.get('[data-testid="read-all"]').trigger('click')
    await flushPromises()

    expect(api.markAllNotificationsRead).toHaveBeenCalled()
    expect(wrapper.get('.badge').attributes('data-value')).toBe('0')
  })
})
