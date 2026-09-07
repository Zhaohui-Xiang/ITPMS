import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AsyncState from './AsyncState.vue'

const ElButton = {
  emits: ['click'],
  template: '<button type="button" @click="$emit(\'click\')"><slot /></button>',
}

function mountState(props = {}) {
  return mount(AsyncState, {
    props,
    slots: { default: '<div data-testid="content">loaded content</div>' },
    global: {
      stubs: {
        ElButton,
        ElEmpty: { template: '<div><slot /></div>' },
        ElIcon: { template: '<i><slot /></i>' },
        ElSkeleton: { template: '<div class="skeleton" />' },
        WarningFilled: true,
      },
    },
  })
}

describe('AsyncState', () => {
  it('renders only the loading state while loading', () => {
    const wrapper = mountState({
      loading: true,
      error: 'request failed',
      empty: true,
    })

    expect(wrapper.find('[data-state="loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-state="error"]').exists()).toBe(false)
    expect(wrapper.find('[data-state="empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="content"]').exists()).toBe(false)
  })

  it('renders an API error and emits retry', async () => {
    const wrapper = mountState({ error: { message: '服务暂不可用' }, empty: true })

    expect(wrapper.find('[data-state="error"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('服务暂不可用')
    expect(wrapper.find('[data-state="empty"]').exists()).toBe(false)

    await wrapper.get('[data-testid="retry"]').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('renders only the empty state when the request has no records', () => {
    const wrapper = mountState({ empty: true, emptyTitle: '暂无项目' })

    expect(wrapper.find('[data-state="empty"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('暂无项目')
    expect(wrapper.find('[data-testid="content"]').exists()).toBe(false)
  })

  it('renders content only after loading, error, and empty states are clear', () => {
    const wrapper = mountState()

    expect(wrapper.find('[data-testid="content"]').exists()).toBe(true)
    expect(wrapper.find('[data-state]').exists()).toBe(false)
  })
})
