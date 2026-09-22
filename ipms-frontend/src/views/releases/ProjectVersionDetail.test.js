import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import ProjectVersionDetail from './ProjectVersionDetail.vue'
import VersionEditDialog from '@/components/releases/VersionEditDialog.vue'

const api = vi.hoisted(() => ({
  getProjectVersion: vi.fn(),
  updateProjectVersion: vi.fn(),
  checkProjectVersionGate: vi.fn(),
  listProjectVersionHistory: vi.fn(),
  listUnplannedRequirements: vi.fn(),
  planRequirementVersion: vi.fn(),
  unplanRequirementVersion: vi.fn(),
  transitionProjectVersion: vi.fn(),
  releaseProjectVersion: vi.fn(),
  getProject: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  warning: vi.fn(),
}))
vi.mock('@/api/projectVersion', () => api)
vi.mock('@/api/project', () => ({ getProject: api.getProject }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isSuperAdmin: true }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' } }),
  useRouter: () => ({ push: vi.fn() }),
}))
vi.mock('element-plus', async (original) => ({
  ...await original(),
  ElMessage: { success: api.success, error: api.error, warning: api.warning },
}))

const response = (data) => ({ data: { data } })
const version = (overrides = {}) => ({
  id: 42, code: '2026.9', name: 'September', description: 'Original description',
  status: 4, status_code: 'IN_TESTING', status_label: 'Testing', lock_version: 3,
  project: { id: 9, name: 'Core' }, owner: { id: 7, display_name: 'IT PM' },
  release_notes: 'Original notes',
  allowed_actions: ['edit', 'plan_requirements', 'transition', 'force_release'],
  gate_result: { passed: false, checks: [], blocking: [] },
  counts: {}, history: [], release_snapshot: null,
  scope: [{ requirement_id: 18, requirement: { id: 18, title: 'Planned requirement' } }],
  ...overrides,
})
function deferred() {
  let resolve
  let reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
const wrappers = []
async function mountDetail() {
  const wrapper = mount(ProjectVersionDetail, {
    global: {
      plugins: [ElementPlus],
      stubs: {
        WarningFilled: true,
        VersionProgress: true, VersionStatusTag: true, ReleaseGatePanel: true,
        VersionHistory: true,
        ElDialog: {
          props: ['modelValue'],
          template: '<section v-if="modelValue" data-testid="metadata-dialog"><slot /><slot name="footer" /></section>',
        },
        ElTabs: { template: '<div><slot /></div>' },
        ElTabPane: { template: '<div><slot /></div>' },
        ElDescriptions: { template: '<div><slot /></div>' },
        ElDescriptionsItem: { template: '<div><slot /></div>' },
      },
    },
  })
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}
function notes(wrapper) {
  const field = wrapper.get('[data-testid="edit-release_notes"]')
  return field.element.matches('textarea') ? field : field.get('textarea')
}
async function openEditor(wrapper) {
  await wrapper.get('[data-testid="edit-version-command"]').trigger('click')
  await flushPromises()
  await notes(wrapper).setValue('Unsaved metadata draft')
}
async function startScopeMutation(wrapper, operation) {
  const pending = deferred()
  const mutation = operation === 'add' ? api.planRequirementVersion : api.unplanRequirementVersion
  mutation.mockReturnValueOnce(pending.promise)
  await wrapper.get('[data-testid="planning-reason"]').setValue('Scope correction')
  const testId = operation === 'add' ? 'plan-requirement-27' : 'unplan-requirement-18'
  await wrapper.get(`[data-testid="${testId}"]`).trigger('click')
  expect(mutation).toHaveBeenCalledTimes(1)
  return pending
}

describe('version metadata and in-flight workspace mutations', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    api.getProjectVersion.mockResolvedValue(response(version()))
    api.getProject.mockResolvedValue(response({ id: 9, manager: { id: 7, display_name: 'IT PM' } }))
    api.checkProjectVersionGate.mockResolvedValue(response(version().gate_result))
    api.listProjectVersionHistory.mockResolvedValue(response({ items: [], total_pages: 1 }))
    api.listUnplannedRequirements.mockResolvedValue(response({ items: [{ id: 27, title: 'Unplanned' }] }))
    api.updateProjectVersion.mockResolvedValue(response(version({ lock_version: 5 })))
  })
  afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

  it.each([
    ['add', 'success'], ['remove', 'success'], ['add', 'stale'], ['remove', 'stale'],
  ])('waits for scope %s (%s) and its complete refresh before opening metadata', async (operation, outcome) => {
    const wrapper = await mountDetail()
    const pending = await startScopeMutation(wrapper, operation)
    const detail = deferred()
    const gate = deferred()
    api.getProjectVersion.mockReturnValueOnce(detail.promise)
    api.checkProjectVersionGate.mockReturnValueOnce(gate.promise)

    for (const selector of [
      '[data-testid="edit-version-command"]', '[data-testid="transition-command"]',
      '[data-testid="force-release-command"]', '.command-bar button:first-child',
      '[data-testid="plan-requirement-27"]', '[data-testid="unplan-requirement-18"]',
    ]) {
      expect(wrapper.get(selector).attributes('disabled')).toBeDefined()
      wrapper.getComponent(selector).vm.$emit('click', new MouseEvent('click'))
    }
    await flushPromises()
    expect(wrapper.find('[data-testid="metadata-dialog"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="confirm-release-command"]').exists()).toBe(false)
    expect(api.getProjectVersion).toHaveBeenCalledTimes(1)
    expect(api.planRequirementVersion.mock.calls.length + api.unplanRequirementVersion.mock.calls.length).toBe(1)

    if (outcome === 'stale') {
      pending.reject({ response: { status: 409, data: { error_code: 'STALE_VERSION', message: 'Scope changed' } } })
    } else {
      pending.resolve(response({ lock_version: 4 }))
    }
    await flushPromises()
    expect(api.getProjectVersion).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-state="loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
    detail.resolve(response(version({ lock_version: 4, release_notes: 'Fresh server notes' })))
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
    gate.resolve(response(version().gate_result))
    await flushPromises()
    if (outcome === 'stale') expect(api.warning).toHaveBeenCalledWith('Scope changed')

    await openEditor(wrapper)
    expect(notes(wrapper).element.value).toBe('Unsaved metadata draft')
    expect(wrapper.getComponent(VersionEditDialog).props('version').lock_version).toBe(4)
    await wrapper.get('[data-testid="save-version-info"]').trigger('click')
    await flushPromises()
    expect(api.updateProjectVersion).toHaveBeenCalledWith('42', expect.objectContaining({
      lock_version: 4, release_notes: 'Unsaved metadata draft',
    }))
    expect(wrapper.find('[data-testid="metadata-dialog"]').exists()).toBe(false)
    expect(api.checkProjectVersionGate).toHaveBeenCalledTimes(3)
    expect(api.listProjectVersionHistory).toHaveBeenCalledTimes(3)
    expect(wrapper.get('[data-testid="plan-requirement-27"]').attributes('disabled')).toBeUndefined()
  })

  it('blocks scope, lifecycle, repeat-open and refresh actions while metadata is open', async () => {
    const wrapper = await mountDetail()
    await openEditor(wrapper)
    for (const selector of [
      '[data-testid="edit-version-command"]', '[data-testid="transition-command"]',
      '[data-testid="force-release-command"]', '.command-bar button:first-child',
      '[data-testid="plan-requirement-27"]', '[data-testid="unplan-requirement-18"]',
    ]) {
      expect(wrapper.get(selector).attributes('disabled')).toBeDefined()
      wrapper.getComponent(selector).vm.$emit('click', new MouseEvent('click'))
    }
    await flushPromises()
    expect(wrapper.find('[data-testid="confirm-release-command"]').exists()).toBe(false)
    expect(api.planRequirementVersion).not.toHaveBeenCalled()
    expect(api.unplanRequirementVersion).not.toHaveBeenCalled()
    expect(api.getProjectVersion).toHaveBeenCalledTimes(1)
    expect(notes(wrapper).element.value).toBe('Unsaved metadata draft')
    expect(wrapper.getComponent(VersionEditDialog).props('version').lock_version).toBe(3)
    await wrapper.get('[data-testid="cancel-version-edit"]').trigger('click')
    expect(wrapper.get('[data-testid="plan-requirement-27"]').attributes('disabled')).toBeUndefined()
    const pending = await startScopeMutation(wrapper, 'add')
    pending.resolve(response({ lock_version: 4 }))
    await flushPromises()
    expect(api.getProjectVersion).toHaveBeenCalledTimes(2)
  })

  it.each(['add', 'remove'])('releases the scope %s guard after a network error without refreshing', async (operation) => {
    const wrapper = await mountDetail()
    const pending = await startScopeMutation(wrapper, operation)
    expect(wrapper.get('[data-testid="edit-version-command"]').attributes('disabled')).toBeDefined()
    pending.reject(new Error('Scope unavailable'))
    await flushPromises()
    expect(api.error).toHaveBeenCalledTimes(1)
    expect(api.getProjectVersion).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="edit-version-command"]').attributes('disabled')).toBeUndefined()
    await openEditor(wrapper)
    expect(wrapper.getComponent(VersionEditDialog).props('version').lock_version).toBe(3)
  })

  it('requires a successful retry after the post-scope detail refresh fails', async () => {
    const wrapper = await mountDetail()
    const pending = await startScopeMutation(wrapper, 'add')
    api.getProjectVersion.mockRejectedValueOnce(new Error('Detail unavailable'))
    pending.resolve(response({ lock_version: 4 }))
    await flushPromises()
    expect(wrapper.find('[data-state="error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
    api.getProjectVersion.mockResolvedValue(response(version({ lock_version: 4 })))
    await wrapper.get('[data-testid="retry"]').trigger('click')
    await flushPromises()
    await openEditor(wrapper)
    expect(wrapper.getComponent(VersionEditDialog).props('version').lock_version).toBe(4)
  })

  it.each(['transition', 'force-release', 'release'])('excludes scope and metadata during a %s command and its refresh', async (command) => {
    if (command === 'release') {
      api.getProjectVersion.mockResolvedValue(response(version({
        status: 5, status_code: 'READY_TO_RELEASE', allowed_actions: ['edit', 'release'],
      })))
    }
    const wrapper = await mountDetail()
    const pending = deferred()
    const mutation = command === 'transition' ? api.transitionProjectVersion : api.releaseProjectVersion
    mutation.mockReturnValueOnce(pending.promise)
    await wrapper.findAll(`[data-testid="${command}-command"]`).at(-1).trigger('click')
    const edit = wrapper.find('[data-testid="edit-version-command"]')
    if (edit.exists()) {
      expect(edit.attributes('disabled')).toBeDefined()
      wrapper.getComponent('[data-testid="edit-version-command"]').vm.$emit('click', new MouseEvent('click'))
    }
    expect(wrapper.get('.command-bar button:first-child').attributes('disabled')).toBeDefined()
    wrapper.getComponent('.command-bar button:first-child').vm.$emit('click', new MouseEvent('click'))
    if (command !== 'release') {
      expect(wrapper.get('[data-testid="plan-requirement-27"]').attributes('disabled')).toBeDefined()
      wrapper.getComponent('[data-testid="plan-requirement-27"]').vm.$emit('click', new MouseEvent('click'))
    }
    if (command === 'force-release') await wrapper.get('[data-testid="force-reason"]').setValue('Urgent release')
    await wrapper.get('[data-testid="confirm-release-command"]').trigger('click')
    wrapper.getComponent({ name: 'ReleaseDialog' }).vm.$emit('confirm', { lock_version: 3 })
    await flushPromises()
    expect(mutation).toHaveBeenCalledTimes(1)
    expect(api.planRequirementVersion).not.toHaveBeenCalled()
    expect(api.getProjectVersion).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="metadata-dialog"]').exists()).toBe(false)
    const detail = deferred()
    api.getProjectVersion.mockReturnValueOnce(detail.promise)
    pending.resolve(response(version({ lock_version: 4 })))
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
    detail.resolve(response(version({ status: 5, status_code: 'READY_TO_RELEASE', lock_version: 4 })))
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="metadata-dialog"]').exists()).toBe(false)
  })
})
