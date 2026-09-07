import { defineComponent, nextTick } from 'vue'
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HeaderBar from './HeaderBar.vue'

const { authStore, routerPush, sidebarStore } = vi.hoisted(() => ({
  authStore: {
    currentRole: 'it_pm',
    mustChangePassword: false,
    userName: '张三',
    logout: vi.fn(),
  },
  routerPush: vi.fn(),
  sidebarStore: {
    collapsed: false,
    toggleCollapse: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => authStore }))
vi.mock('@/stores/sidebar', () => ({ useSidebarStore: () => sidebarStore }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: routerPush }) }))

const ElBadge = defineComponent({
  name: 'ElBadge',
  props: {
    value: Number,
    showZero: Boolean,
  },
  template: '<div><slot /></div>',
})

const ElDropdown = defineComponent({
  name: 'ElDropdown',
  emits: ['command'],
  template: '<div><slot /><slot name="dropdown" /></div>',
})

const ProfileDialog = defineComponent({
  name: 'ProfileDialog',
  props: {
    modelValue: Boolean,
    forcePasswordChange: Boolean,
  },
  emits: ['update:modelValue'],
  template: '<div data-testid="profile-dialog" />',
})

const GlobalSearch = defineComponent({
  name: 'GlobalSearch',
  template: '<div data-testid="global-search" />',
})

const stubs = {
  ArrowDown: true,
  Bell: true,
  ElAvatar: true,
  ElBadge,
  ElButton: { template: '<button><slot /></button>' },
  ElDropdown,
  ElDropdownItem: { template: '<div><slot /></div>' },
  ElDropdownMenu: { template: '<div><slot /></div>' },
  ElIcon: { template: '<i><slot /></i>' },
  ElTooltip: { template: '<div><slot /></div>' },
  GlobalSearch,
  ProfileDialog,
  SwitchButton: true,
  User: true,
}

describe('HeaderBar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authStore.mustChangePassword = false
  })

  it('shows global search and a real zero notification state', () => {
    const wrapper = shallowMount(HeaderBar, { global: { stubs } })

    expect(wrapper.findComponent(GlobalSearch).exists()).toBe(true)
    expect(wrapper.findComponent(ElBadge).props()).toMatchObject({
      value: 0,
      showZero: true,
    })
  })

  it('opens ProfileDialog instead of navigating to an invalid profile route', async () => {
    const wrapper = shallowMount(HeaderBar, { global: { stubs } })
    const dropdown = wrapper.findComponent(ElDropdown)

    dropdown.vm.$emit('command', 'profile')
    await nextTick()

    expect(wrapper.findComponent(ProfileDialog).props('modelValue')).toBe(true)
    expect(routerPush).not.toHaveBeenCalledWith('/profile')
  })

  it('forces the password dialog from current authenticated user state', () => {
    authStore.mustChangePassword = true

    const wrapper = shallowMount(HeaderBar, { global: { stubs } })

    expect(wrapper.findComponent(ProfileDialog).props('forcePasswordChange')).toBe(true)
  })
})
