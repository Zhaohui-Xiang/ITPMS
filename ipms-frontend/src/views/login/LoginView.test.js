import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LoginView from './LoginView.vue'

const { authLogin, changePassword, routerPush } = vi.hoisted(() => ({
  authLogin: vi.fn(),
  changePassword: vi.fn(),
  routerPush: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ login: authLogin }),
}))
vi.mock('@/api/auth', () => ({ changePassword }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: routerPush }),
}))
vi.mock('element-plus', () => ({
  ElMessage: {
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
  },
}))

const ElForm = defineComponent({
  name: 'ElForm',
  props: { rules: Object },
  setup(_props, { expose, slots }) {
    expose({ validate: () => Promise.resolve(true) })
    return () => h('form', slots.default?.())
  },
})

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
  ElButton,
  ElCheckbox: true,
  ElDialog: {
    props: ['modelValue'],
    template: '<section v-if="modelValue"><slot /><slot name="footer" /></section>',
  },
  ElForm,
  ElFormItem: { template: '<div><slot /></div>' },
  ElIcon: { template: '<i><slot /></i>' },
  ElInput: true,
}

describe('LoginView shell handoff', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('enters the authenticated shell when the server requires a password change', async () => {
    authLogin.mockResolvedValueOnce({
      data: {
        user: {
          id: 1,
          must_change_password: true,
        },
      },
    })
    const wrapper = mount(LoginView, { global: { stubs } })

    await wrapper.get('.login-btn').trigger('click')
    await flushPromises()

    expect(routerPush).toHaveBeenCalledWith('/dashboard')
    expect(changePassword).not.toHaveBeenCalled()
    expect(wrapper.find('.password-dialog').exists()).toBe(false)
  })

  it('does not reject a backend-valid password length before login', () => {
    const wrapper = mount(LoginView, { global: { stubs } })
    const passwordRules = wrapper.findComponent(ElForm).props('rules').password

    expect(passwordRules).toEqual([
      expect.objectContaining({ required: true }),
    ])
  })
})
