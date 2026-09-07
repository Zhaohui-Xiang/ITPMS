import { defineComponent } from 'vue'
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Sidebar from './Sidebar.vue'

const { routerPush, sidebarStore } = vi.hoisted(() => ({
  routerPush: vi.fn(),
  sidebarStore: {
    activeMenu: '',
    collapsed: true,
    setActiveMenu: vi.fn(),
    toggleCollapse: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ path: '/projects/3/versions' }),
  useRouter: () => ({ push: routerPush }),
}))
vi.mock('@/stores/sidebar', () => ({ useSidebarStore: () => sidebarStore }))
vi.mock('@/composables/usePermission', () => ({
  usePermission: () => ({
    visibleMenuItems: [
      { index: '/dashboard', title: '首页', icon: 'HomeFilled' },
      { index: '/projects', title: '项目管理', icon: 'Folder' },
    ],
  }),
}))

const BrandMark = defineComponent({
  name: 'BrandMark',
  props: { compact: Boolean },
  template: '<div data-testid="brand-mark" />',
})

const ElMenu = defineComponent({
  name: 'ElMenu',
  props: { defaultActive: String },
  template: '<nav><slot /></nav>',
})

const stubs = {
  BrandMark,
  ElIcon: { template: '<i><slot /></i>' },
  ElMenu,
  ElMenuItem: { template: '<div><slot /><slot name="title" /></div>' },
  ElTooltip: { template: '<div><slot /></div>' },
  Expand: true,
  Fold: true,
}

describe('Sidebar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    sidebarStore.collapsed = true
  })

  it('uses the compact Voltage V mark in the collapsed shell', () => {
    const wrapper = shallowMount(Sidebar, { global: { stubs } })

    expect(wrapper.findComponent(BrandMark).props('compact')).toBe(true)
    expect(wrapper.findComponent(ElMenu).props('defaultActive')).toBe('/projects')
  })
})
