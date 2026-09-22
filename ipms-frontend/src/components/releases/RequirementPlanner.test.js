import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import RequirementPlanner from './RequirementPlanner.vue'

const api = vi.hoisted(() => ({
  listUnplannedRequirements: vi.fn(),
  planRequirementVersion: vi.fn(),
  unplanRequirementVersion: vi.fn(),
  error: vi.fn(),
  success: vi.fn(),
}))
vi.mock('@/api/projectVersion', () => api)
vi.mock('element-plus', () => ({ ElMessage: { error: api.error, success: api.success } }))

const response = (data) => ({ data: { data } })
const version = {
  id: 42, lock_version: 3, status_code: 'PLANNED', project: { id: 9 },
  allowed_actions: ['plan_requirements'],
  scope: [{ requirement_id: 18, requirement: { id: 18, title: 'Planned' } }],
}
function deferred() {
  let resolve
  let reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
const wrappers = []
async function mountPlanner(props = {}) {
  const wrapper = mount(RequirementPlanner, {
    props: { version, ...props },
    global: {
      stubs: {
        ElButton: {
          props: ['disabled', 'loading'], emits: ['click'],
          template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>',
        },
        ElInput: true,
        ElTooltip: { template: '<span><slot /></span>' },
      },
    },
  })
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}

describe('scope mutation exclusion', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    api.listUnplannedRequirements.mockResolvedValue(response({ items: [{ id: 27, title: 'Unplanned' }] }))
  })
  afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

  it.each([
    ['plan', 'success'], ['unplan', 'success'],
    ['plan', 'stale'], ['unplan', 'stale'],
    ['plan', 'network'], ['unplan', 'network'],
  ])('signals busy synchronously and excludes duplicates for %s (%s)', async (operation, outcome) => {
    const pending = deferred()
    const mutation = operation === 'plan' ? api.planRequirementVersion : api.unplanRequirementVersion
    const onBusy = vi.fn((busy) => {
      if (busy) expect(mutation).not.toHaveBeenCalled()
    })
    const wrapper = await mountPlanner({ onBusy })
    mutation.mockReturnValueOnce(pending.promise)
    const plan = wrapper.getComponent('[data-testid="plan-requirement-27"]')
    const unplan = wrapper.getComponent('[data-testid="unplan-requirement-18"]')
    const refresh = wrapper.getComponent('[aria-label="刷新未规划需求"]')
    const selected = operation === 'plan' ? plan : unplan
    selected.vm.$emit('click')
    expect(onBusy).toHaveBeenCalledWith(true)
    // No render tick may be needed to exclude another mutation or pool refresh.
    plan.vm.$emit('click')
    unplan.vm.$emit('click')
    refresh.vm.$emit('click')
    expect(api.planRequirementVersion.mock.calls.length + api.unplanRequirementVersion.mock.calls.length).toBe(1)
    expect(api.listUnplannedRequirements).toHaveBeenCalledTimes(1)
    await flushPromises()
    for (const button of [plan, unplan, refresh]) expect(button.attributes('disabled')).toBeDefined()

    if (outcome === 'success') pending.resolve(response({ lock_version: 4 }))
    else if (outcome === 'stale') {
      pending.reject({ response: { data: { error_code: 'STALE_VERSION', message: 'Scope changed' } } })
    } else pending.reject(new Error('Network unavailable'))
    await flushPromises()
    expect(wrapper.emitted('busy')).toEqual([[true], [false]])
    expect(onBusy).toHaveBeenLastCalledWith(false)
    expect(wrapper.emitted('changed')?.length ?? 0).toBe(outcome === 'success' ? 1 : 0)
    expect(wrapper.emitted('stale')?.length ?? 0).toBe(outcome === 'stale' ? 1 : 0)
    expect(api.error).toHaveBeenCalledTimes(outcome === 'network' ? 1 : 0)
    expect(wrapper.get('[aria-label="刷新未规划需求"]').attributes('disabled')).toBeUndefined()
  })

  it('guards handlers as well as buttons while the workspace is disabled', async () => {
    const wrapper = await mountPlanner()
    await wrapper.setProps({ disabled: true })
    for (const selector of [
      '[data-testid="plan-requirement-27"]', '[data-testid="unplan-requirement-18"]',
      '[aria-label="刷新未规划需求"]',
    ]) {
      expect(wrapper.get(selector).attributes('disabled')).toBeDefined()
      wrapper.getComponent(selector).vm.$emit('click')
    }
    expect(api.planRequirementVersion).not.toHaveBeenCalled()
    expect(api.unplanRequirementVersion).not.toHaveBeenCalled()
    expect(api.listUnplannedRequirements).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('busy')).toBeUndefined()
    await wrapper.setProps({ disabled: false })
    api.unplanRequirementVersion.mockResolvedValueOnce(response({ lock_version: 4 }))
    await wrapper.get('[data-testid="unplan-requirement-18"]').trigger('click')
    await flushPromises()
    expect(api.unplanRequirementVersion).toHaveBeenCalledTimes(1)
  })

  it('loads the pool when remounted before the parent finishes its command cleanup', async () => {
    const wrapper = await mountPlanner({ disabled: true })
    expect(api.listUnplannedRequirements).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="plan-requirement-27"]').attributes('disabled')).toBeDefined()
    await wrapper.setProps({ disabled: false })
    api.planRequirementVersion.mockResolvedValueOnce(response({ lock_version: 4 }))
    await wrapper.get('[data-testid="plan-requirement-27"]').trigger('click')
    await flushPromises()
    expect(api.planRequirementVersion).toHaveBeenCalledTimes(1)
  })
})
