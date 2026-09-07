import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import ProfileDialog from './ProfileDialog.vue'

const { changePassword, fetchUser, login, logout, routerPush, updateProfile } = vi.hoisted(() => ({
  changePassword: vi.fn(),
  fetchUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  routerPush: vi.fn(),
  updateProfile: vi.fn(),
}))

vi.mock('@/api/user', () => ({ changePassword, updateProfile }))
vi.mock('@/api/auth', () => ({ fetchUser, login, logout }))
vi.mock('@/router', () => ({ default: { push: routerPush } }))
vi.mock('element-plus', () => ({
  ElMessage: {
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
  },
}))

const ElDialog = defineComponent({
  name: 'ElDialog',
  props: {
    modelValue: Boolean,
    showClose: Boolean,
    closeOnClickModal: Boolean,
    closeOnPressEscape: Boolean,
  },
  emits: ['update:modelValue'],
  template: '<section v-if="modelValue" data-testid="dialog" :data-show-close="String(showClose)" :data-close-on-modal="String(closeOnClickModal)" :data-close-on-escape="String(closeOnPressEscape)"><slot /><slot name="footer" /></section>',
})

const ElTabs = defineComponent({
  name: 'ElTabs',
  props: { modelValue: String },
  emits: ['update:modelValue'],
  template: '<div data-testid="tabs" :data-active-tab="modelValue"><slot /></div>',
})

const ElForm = defineComponent({
  name: 'ElForm',
  setup(_props, { expose, slots }) {
    expose({ validate: () => Promise.resolve(true) })
    return () => h('form', slots.default?.())
  },
})

const ElInput = defineComponent({
  name: 'ElInput',
  inheritAttrs: false,
  props: { modelValue: { type: String, default: '' } },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('input', {
      ...attrs,
      value: props.modelValue,
      onInput: (event) => emit('update:modelValue', event.target.value),
    })
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
  ElAlert: { template: '<div><slot /></div>' },
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem: { template: '<div><slot /></div>' },
  ElInput,
  ElTabPane: { template: '<div><slot /></div>' },
  ElTabs,
}

const makeUser = (overrides = {}) => ({
  id: 7,
  username: 'zhangsan',
  display_name: '张三',
  email: 'zhangsan@example.test',
  phone: '13800000000',
  roles: ['it_pm'],
  user_type: 1,
  must_change_password: false,
  ...overrides,
})

function mountDialog(props = {}) {
  return mount(ProfileDialog, {
    props: {
      modelValue: true,
      ...props,
    },
    global: { stubs },
  })
}

describe('ProfileDialog', () => {
  let auth

  beforeEach(() => {
    setActivePinia(createPinia())
    auth = useAuthStore()
    auth.user = makeUser()
    vi.clearAllMocks()
  })

  it('opens and lets a user cancel a normal profile edit', async () => {
    const wrapper = mountDialog({ modelValue: false })

    expect(wrapper.find('[data-testid="dialog"]').exists()).toBe(false)

    await wrapper.setProps({ modelValue: true })
    expect(wrapper.find('[data-testid="dialog"]').exists()).toBe(true)

    await wrapper.get('[data-testid="profile-cancel"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('preserves entered values and server validation details after a failed save', async () => {
    updateProfile.mockRejectedValueOnce({
      response: {
        data: {
          message: '资料校验失败',
          errors: { email: ['邮箱已被使用'] },
        },
      },
    })
    const wrapper = mountDialog()
    const email = wrapper.get('[data-testid="profile-email"]')

    await email.setValue('duplicate@example.test')
    await wrapper.get('[data-testid="profile-save"]').trigger('click')
    await flushPromises()

    expect(email.element.value).toBe('duplicate@example.test')
    expect(wrapper.text()).toContain('邮箱已被使用')
    expect(wrapper.find('[data-testid="dialog"]').exists()).toBe(true)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('merges a successful profile response into auth state and closes', async () => {
    updateProfile.mockResolvedValueOnce({
      data: {
        data: {
          display_name: '李四',
          email: 'lisi@example.test',
          phone: '13900000000',
        },
      },
    })
    const wrapper = mountDialog()

    await wrapper.get('[data-testid="profile-name"]').setValue('李四')
    await wrapper.get('[data-testid="profile-save"]').trigger('click')
    await flushPromises()

    expect(updateProfile).toHaveBeenCalledWith({
      display_name: '李四',
      email: 'zhangsan@example.test',
      phone: '13800000000',
    })
    expect(auth.user.display_name).toBe('李四')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('keeps first-login password change non-dismissable until refreshed user state clears the flag', async () => {
    let resolveFetch
    fetchUser.mockReturnValueOnce(new Promise((resolve) => {
      resolveFetch = resolve
    }))
    changePassword.mockResolvedValueOnce({ data: { data: null } })
    auth.user = makeUser({ must_change_password: true })
    const wrapper = mountDialog({
      modelValue: false,
      forcePasswordChange: true,
    })

    const dialog = wrapper.get('[data-testid="dialog"]')
    expect(wrapper.get('[data-testid="tabs"]').attributes('data-active-tab')).toBe('password')
    expect(dialog.attributes('data-show-close')).toBe('false')
    expect(dialog.attributes('data-close-on-modal')).toBe('false')
    expect(dialog.attributes('data-close-on-escape')).toBe('false')
    expect(wrapper.find('[data-testid="profile-cancel"]').exists()).toBe(false)

    await wrapper.get('[data-testid="current-password"]').setValue('Secret123')
    await wrapper.get('[data-testid="new-password"]').setValue('NewSecret123')
    await wrapper.get('[data-testid="confirm-password"]').setValue('NewSecret123')
    await wrapper.get('[data-testid="password-save"]').trigger('click')
    await flushPromises()

    expect(changePassword).toHaveBeenCalledOnce()
    expect(wrapper.find('[data-testid="dialog"]').exists()).toBe(true)
    expect(auth.mustChangePassword).toBe(true)

    resolveFetch({
      data: {
        data: {
          user: makeUser({ must_change_password: false }),
        },
      },
    })
    await flushPromises()

    expect(auth.mustChangePassword).toBe(false)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('keeps the forced lock and retries user refresh without changing the password twice', async () => {
    changePassword.mockResolvedValueOnce({ data: { data: null } })
    fetchUser
      .mockRejectedValueOnce(new Error('network unavailable'))
      .mockResolvedValueOnce({
        data: {
          data: {
            user: makeUser({ must_change_password: false }),
          },
        },
      })
    auth.user = makeUser({ must_change_password: true })
    const wrapper = mountDialog({
      modelValue: false,
      forcePasswordChange: true,
    })

    await wrapper.get('[data-testid="current-password"]').setValue('Secret123')
    await wrapper.get('[data-testid="new-password"]').setValue('NewSecret123')
    await wrapper.get('[data-testid="confirm-password"]').setValue('NewSecret123')
    await wrapper.get('[data-testid="password-save"]').trigger('click')
    await flushPromises()

    expect(changePassword).toHaveBeenCalledOnce()
    expect(auth.mustChangePassword).toBe(true)
    expect(wrapper.find('[data-testid="dialog"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="password-refresh"]').exists()).toBe(true)

    await wrapper.get('[data-testid="password-refresh"]').trigger('click')
    await flushPromises()

    expect(changePassword).toHaveBeenCalledOnce()
    expect(fetchUser).toHaveBeenCalledTimes(2)
    expect(auth.mustChangePassword).toBe(false)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })
})
