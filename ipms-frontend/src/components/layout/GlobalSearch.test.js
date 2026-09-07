import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import GlobalSearch from './GlobalSearch.vue'

const { listProjects, listRequirements, routerPush } = vi.hoisted(() => ({
  listProjects: vi.fn(),
  listRequirements: vi.fn(),
  routerPush: vi.fn(),
}))

vi.mock('@/api/project', () => ({ listProjects }))
vi.mock('@/api/requirement', () => ({ listRequirements }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: routerPush }) }))

const ElInput = defineComponent({
  name: 'ElInput',
  inheritAttrs: false,
  props: { modelValue: { type: String, default: '' } },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit, slots }) {
    return () => h('label', [
      h('input', {
        ...attrs,
        value: props.modelValue,
        onBlur: attrs.onBlur,
        onFocus: attrs.onFocus,
        onInput: (event) => emit('update:modelValue', event.target.value),
        onKeydown: attrs.onKeydown,
      }),
      slots.prefix?.(),
      slots.suffix?.(),
    ])
  },
})

const stubs = {
  ElIcon: { template: '<i><slot /></i>' },
  ElInput,
  Loading: true,
  Search: true,
}

const response = (items) => ({
  data: {
    data: {
      items,
      page: 1,
      page_size: 5,
      total: items.length,
      total_pages: 1,
    },
  },
})

function deferred() {
  let resolve
  const promise = new Promise((resolvePromise) => {
    resolve = resolvePromise
  })
  return { promise, resolve }
}

function mountSearch() {
  return mount(GlobalSearch, { global: { stubs } })
}

describe('GlobalSearch', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    listProjects.mockResolvedValue(response([]))
    listRequirements.mockResolvedValue(response([]))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('waits for two characters and debounces scoped list requests for 250ms', async () => {
    const wrapper = mountSearch()
    const input = wrapper.get('[data-testid="global-search-input"]')

    await input.setValue('a')
    await vi.advanceTimersByTimeAsync(300)
    expect(listProjects).not.toHaveBeenCalled()
    expect(listRequirements).not.toHaveBeenCalled()

    await input.setValue('ab')
    await vi.advanceTimersByTimeAsync(249)
    expect(listProjects).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()

    expect(listProjects).toHaveBeenCalledWith({ keyword: 'ab', page_size: 5 })
    expect(listRequirements).toHaveBeenCalledWith({ keyword: 'ab', page_size: 5 })
  })

  it('groups only records returned by the scoped project and requirement APIs', async () => {
    listProjects.mockResolvedValueOnce(response([{ id: 1, name: 'ERP 升级' }]))
    listRequirements.mockResolvedValueOnce(response([{ id: 2, title: '采购审批' }]))
    const wrapper = mountSearch()

    await wrapper.get('[data-testid="global-search-input"]').setValue('采购')
    await vi.advanceTimersByTimeAsync(250)
    await flushPromises()

    expect(wrapper.text()).toContain('项目')
    expect(wrapper.text()).toContain('ERP 升级')
    expect(wrapper.text()).toContain('需求')
    expect(wrapper.text()).toContain('采购审批')
    expect(wrapper.text()).not.toContain('示例')
  })

  it('suppresses stale responses that finish after a newer query', async () => {
    const firstProjects = deferred()
    const firstRequirements = deferred()
    const secondProjects = deferred()
    const secondRequirements = deferred()
    listProjects.mockImplementation(({ keyword }) => keyword === 'ab' ? firstProjects.promise : secondProjects.promise)
    listRequirements.mockImplementation(({ keyword }) => keyword === 'ab' ? firstRequirements.promise : secondRequirements.promise)
    const wrapper = mountSearch()
    const input = wrapper.get('[data-testid="global-search-input"]')

    await input.setValue('ab')
    await vi.advanceTimersByTimeAsync(250)
    await input.setValue('abc')
    await vi.advanceTimersByTimeAsync(250)
    secondProjects.resolve(response([{ id: 20, name: 'New project' }]))
    secondRequirements.resolve(response([{ id: 21, title: 'New requirement' }]))
    await flushPromises()
    expect(wrapper.text()).toContain('New project')

    firstProjects.resolve(response([{ id: 10, name: 'Stale project' }]))
    firstRequirements.resolve(response([{ id: 11, title: 'Stale requirement' }]))
    await flushPromises()
    expect(wrapper.text()).toContain('New project')
    expect(wrapper.text()).not.toContain('Stale project')
  })

  it('navigates to the keyboard-selected record', async () => {
    listProjects.mockResolvedValueOnce(response([{ id: 1, name: 'Project one' }]))
    listRequirements.mockResolvedValueOnce(response([{ id: 2, title: 'Requirement two' }]))
    const wrapper = mountSearch()
    const input = wrapper.get('[data-testid="global-search-input"]')

    await input.setValue('work')
    await vi.advanceTimersByTimeAsync(250)
    await flushPromises()
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })

    expect(routerPush).toHaveBeenCalledWith('/requirements/2')
  })
})
