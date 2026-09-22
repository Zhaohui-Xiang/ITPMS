import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RequirementDetail from './RequirementDetail.vue'
import ExecutionOwnerEditor from '@/components/requirements/ExecutionOwnerEditor.vue'

const api = vi.hoisted(() => ({
  getRequirement: vi.fn(), getRequirementVersions: vi.fn(), listTasks: vi.fn(), listDefects: vi.fn(),
  listExecutionOwnerOptions: vi.fn(), updateRequirement: vi.fn(),
}))
vi.mock('@/api/requirement', () => ({ ...api, reviewRequirement: vi.fn(), transitionRequirement: vi.fn() }))
vi.mock('@/api/task', () => ({ listTasks: api.listTasks }))
vi.mock('@/api/defect', () => ({ listDefects: api.listDefects }))
vi.mock('vue-router', async importOriginal => ({
  ...await importOriginal(),
  useRoute: () => ({ params: { id: '10' } }),
  useRouter: () => ({ back: vi.fn() }),
}))
vi.mock('element-plus', () => ({ ElMessage: { success: vi.fn(), error: vi.fn() }, ElMessageBox: { confirm: vi.fn() } }))
const response = data => ({ data: { data } })
const requirement = { id: 10, title: 'Owner assignment', version: 3, dev_lead_id: 2, dev_lead: { id: 2, display_name: 'Current owner' }, allowed_actions: ['assign_owner'] }
const render = (overrides = {}) => shallowMount(RequirementDetail, { global: { stubs: {
  AsyncState: false,
  ElSkeleton: true,
  WarningFilled: true,
  ElIcon: true,
  ElEmpty: true,
  ElDescriptions: { template: '<div><slot /></div>' },
  ElDescriptionsItem: { template: '<div><slot /></div>' },
  ElButton: { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
  ElDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /><slot name="footer" /></div>' },
  ElSelect: { props: ['modelValue'], emits: ['update:modelValue'], template: '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>' },
  ElOption: { props: ['value', 'label'], template: '<option :value="value">{{ label }}</option>' },
  ElFormItem: { template: '<div><slot /></div>' },
  ElInput: true,
  ...overrides,
} } })

beforeEach(() => {
  vi.clearAllMocks()
  api.listExecutionOwnerOptions.mockResolvedValue(response([
    { id: 2, display_name: 'Current owner' }, { id: 7, display_name: 'New owner' },
  ]))
  api.updateRequirement.mockResolvedValue(response({}))
  api.getRequirement.mockResolvedValue(response(requirement))
  api.getRequirementVersions.mockResolvedValue(response([]))
  api.listTasks.mockResolvedValue(response({ items: [] }))
  api.listDefects.mockResolvedValue(response({ items: [] }))
})

describe('requirement execution owner integration', () => {
  it.each(['updated', 'stale'])('reloads persisted owner and revision data after %s', async event => {
    const wrapper = render()
    await flushPromises()
    const editor = wrapper.findComponent(ExecutionOwnerEditor)
    expect(editor.exists()).toBe(true)
    expect(editor.props('requirement')).toEqual(requirement)
    const refreshed = { ...requirement, version: 4, dev_lead_id: 7, dev_lead: { id: 7, display_name: 'New owner' } }
    api.getRequirement.mockResolvedValue(response(refreshed))
    editor.vm.$emit(event)
    await flushPromises()
    expect(api.getRequirement).toHaveBeenCalledTimes(2)
    expect(api.getRequirementVersions).toHaveBeenCalledTimes(2)
    expect(wrapper.findComponent(ExecutionOwnerEditor).props('requirement')).toEqual(refreshed)
  })
  it('passes server permissions unchanged for the read-only owner display', async () => {
    api.getRequirement.mockResolvedValue(response({ ...requirement, allowed_actions: ['edit'] }))
    const wrapper = render()
    await flushPromises()
    expect(wrapper.findComponent(ExecutionOwnerEditor).exists()).toBe(true)
    expect(wrapper.findComponent(ExecutionOwnerEditor).props('requirement').allowed_actions).toEqual(['edit'])
  })
  it('waits for explicit stale reload and requires a new selection with the latest revision', async () => {
    api.updateRequirement.mockRejectedValueOnce({ response: { status: 409, data: { error_code: 'STALE_VERSION', message: 'Conflict' } } })
    const wrapper = render({ ExecutionOwnerEditor: false })
    await flushPromises()
    await wrapper.get('[data-testid="edit-execution-owner"]').trigger('click')
    await flushPromises()
    await wrapper.get('select').setValue('7')
    await wrapper.get('[data-testid="save-execution-owner"]').trigger('click')
    await flushPromises()
    expect(api.getRequirement).toHaveBeenCalledTimes(1)
    expect(wrapper.get('select').element.value).toBe('7')
    api.getRequirement.mockResolvedValue(response({ ...requirement, version: 4 }))
    await wrapper.get('[data-testid="reload-execution-owner"]').trigger('click')
    await flushPromises()
    expect(api.getRequirement).toHaveBeenCalledTimes(2)
    expect(api.getRequirementVersions).toHaveBeenCalledTimes(2)
    expect(api.updateRequirement).toHaveBeenCalledTimes(1)
    expect(wrapper.find('select').exists()).toBe(false)
    await wrapper.get('[data-testid="edit-execution-owner"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('select').element.value).toBe('2')
    await wrapper.get('select').setValue('7')
    await wrapper.get('[data-testid="save-execution-owner"]').trigger('click')
    await flushPromises()
    expect(api.updateRequirement).toHaveBeenNthCalledWith(2, 10, { dev_lead_id: 7, version: 4 })
  })
  it('keeps assignment unavailable after reload failure and refreshes permissions on retry', async () => {
    api.updateRequirement.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Conflict' } } })
    const wrapper = render({ ExecutionOwnerEditor: false })
    await flushPromises()
    await wrapper.get('[data-testid="edit-execution-owner"]').trigger('click')
    await flushPromises()
    await wrapper.get('select').setValue('7')
    await wrapper.get('[data-testid="save-execution-owner"]').trigger('click')
    await flushPromises()
    api.getRequirement.mockRejectedValueOnce(new Error('Reload unavailable'))
    await wrapper.get('[data-testid="reload-execution-owner"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Reload unavailable')
    expect(wrapper.findComponent(ExecutionOwnerEditor).exists()).toBe(false)
    expect(api.updateRequirement).toHaveBeenCalledTimes(1)
    api.getRequirement.mockResolvedValue(response({ ...requirement, version: 4, allowed_actions: [] }))
    await wrapper.get('[data-testid="retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Current owner')
    expect(wrapper.find('[data-testid="edit-execution-owner"]').exists()).toBe(false)
    expect(api.updateRequirement).toHaveBeenCalledTimes(1)
    expect(wrapper.findComponent(ExecutionOwnerEditor).props('requirement').version).toBe(4)
  })
})
