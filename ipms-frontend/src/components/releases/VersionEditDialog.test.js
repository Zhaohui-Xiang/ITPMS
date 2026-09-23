import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import ProjectVersionDetail from '@/views/releases/ProjectVersionDetail.vue'

const api = vi.hoisted(() => ({
  getProjectVersion: vi.fn(),
  updateProjectVersion: vi.fn(),
  checkProjectVersionGate: vi.fn(),
  listProjectVersionHistory: vi.fn(),
  getProject: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  warning: vi.fn(),
}))
vi.mock('@/api/projectVersion', () => ({
  ...api,
  transitionProjectVersion: vi.fn(),
  releaseProjectVersion: vi.fn(),
  listUnplannedRequirements: vi.fn(),
  planRequirementVersion: vi.fn(),
  unplanRequirementVersion: vi.fn(),
}))
vi.mock('@/api/project', () => ({ getProject: api.getProject }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isSuperAdmin: false }) }))
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
  planned_start_date: '2026-09-01', planned_release_date: '2026-09-30',
  release_notes: 'Original notes', allowed_actions: ['edit'],
  gate_result: { passed: false, checks: [], blocking: [] },
  counts: {}, history: [], scope: [], release_snapshot: null,
  ...overrides,
})
const wrappers = []
function mountDetail() {
  const wrapper = mount(ProjectVersionDetail, {
    global: {
      plugins: [ElementPlus],
      stubs: {
        AsyncState: { template: '<div><slot /></div>' },
        VersionProgress: true, VersionStatusTag: true, ReleaseGatePanel: true,
        RequirementPlanner: true, VersionHistory: true, ReleaseDialog: true,
        ElDialog: {
          props: ['modelValue'],
          template: '<section v-if="modelValue" role="dialog"><slot /><slot name="footer" /></section>',
        },
        ElTabs: { template: '<div><slot /></div>' },
        ElTabPane: { template: '<div><slot /></div>' },
        ElDescriptions: { template: '<div><slot /></div>' },
        ElDescriptionsItem: { template: '<div><slot /></div>' },
      },
    },
  })
  wrappers.push(wrapper)
  return wrapper
}
function field(wrapper, name) {
  const locator = wrapper.get(`[data-testid="edit-${name}"]`)
  return locator.element.matches('input, textarea') ? locator : locator.get('input, textarea')
}
async function openEditor() {
  const wrapper = mountDetail()
  await flushPromises()
  await wrapper.get('[data-testid="edit-version-command"]').trigger('click')
  await flushPromises()
  return wrapper
}
const save = async (wrapper) => {
  await wrapper.get('[data-testid="save-version-info"]').trigger('click')
  await flushPromises()
}

describe('pre-release version metadata editing', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    api.getProjectVersion.mockResolvedValue(response(version()))
    api.getProject.mockResolvedValue(response({ id: 9, manager: { id: 7, display_name: 'IT PM' } }))
    api.updateProjectVersion.mockResolvedValue(response(version({ lock_version: 4 })))
    api.checkProjectVersionGate.mockResolvedValue(response(version().gate_result))
    api.listProjectVersionHistory.mockResolvedValue(response({ items: [], total_pages: 1 }))
  })
  afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

  it.each(['DRAFT', 'PLANNED', 'IN_DEVELOPMENT', 'IN_TESTING'])('offers edit in permitted %s', async (status_code) => {
    api.getProjectVersion.mockResolvedValue(response(version({ status_code })))
    const wrapper = mountDetail()
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(true)
  })

  it.each(['READY_TO_RELEASE', 'RELEASED', 'ARCHIVED', 'UNKNOWN'])('never edits %s, even with an edit action', async (status_code) => {
    api.getProjectVersion.mockResolvedValue(response(version({ status_code })))
    const wrapper = mountDetail()
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
  })

  it('hides editing without the resource edit action', async () => {
    api.getProjectVersion.mockResolvedValue(response(version({ allowed_actions: [] })))
    const wrapper = mountDetail()
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
  })

  it('prefills metadata and saves notes before READY with the loaded integer lock', async () => {
    const wrapper = await openEditor()
    for (const key of ['code', 'name', 'description', 'release_notes']) {
      expect(field(wrapper, key).element.value).toBe(version()[key])
    }
    expect(wrapper.findAll('.el-date-editor input').map((input) => input.element.value)).toEqual([
      version().planned_start_date, version().planned_release_date,
    ])
    expect(field(wrapper, 'release_notes').attributes('maxlength')).toBe('10000')
    await field(wrapper, 'name').setValue('Updated name')
    await field(wrapper, 'release_notes').setValue('Ready for review')
    await save(wrapper)
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(1)
    expect(api.updateProjectVersion).toHaveBeenCalledWith('42', {
      lock_version: 3, code: '2026.9', name: 'Updated name', description: 'Original description',
      planned_start_date: '2026-09-01', planned_release_date: '2026-09-30',
      release_notes: 'Ready for review',
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(api.getProjectVersion).toHaveBeenCalledTimes(2)
    expect(api.checkProjectVersionGate).toHaveBeenCalledTimes(2)
    expect(api.listProjectVersionHistory).toHaveBeenCalledTimes(2)
  })

  it('edits a separate draft without mutating the loaded resource or snapshot', async () => {
    const snapshot = Object.freeze({
      release_notes: 'Published notes', requirement_scope: Object.freeze([]),
      task_count: 3, defect_count: 0,
    })
    const resource = Object.freeze(version({ release_snapshot: snapshot }))
    api.getProjectVersion.mockResolvedValue(response(resource))
    const wrapper = await openEditor()
    await field(wrapper, 'release_notes').setValue('Draft notes')
    await field(wrapper, 'name').setValue('Draft name')
    expect(resource.release_notes).toBe('Original notes')
    expect(resource.name).toBe('September')
    expect(resource.release_snapshot).toBe(snapshot)
    await save(wrapper)
    const payload = api.updateProjectVersion.mock.calls[0][1]
    expect(payload).toMatchObject({ release_notes: 'Draft notes', name: 'Draft name' })
    expect(payload).not.toHaveProperty('release_snapshot')
    expect(payload).not.toHaveProperty('status')
    expect(payload).not.toHaveProperty('scope')
    expect(snapshot.release_notes).toBe('Published notes')
    expect(resource.release_notes).toBe('Original notes')
  })

  it('only offers the assigned IT PM and omits unchanged legacy ownership', async () => {
    api.getProjectVersion.mockResolvedValue(response(version({ owner: { id: 8, display_name: 'Previous owner' } })))
    const wrapper = await openEditor()
    expect(api.getProject).toHaveBeenCalledWith(9)
    const options = wrapper.findAllComponents({ name: 'ElOption' })
    expect(options.map((option) => ({
      value: option.props('value'), disabled: option.props('disabled'),
    }))).toEqual([
      { value: 8, disabled: true },
      { value: 7, disabled: false },
    ])
    expect(options.filter((option) => !option.props('disabled'))
      .map((option) => option.props('value'))).toEqual([7])
    await save(wrapper)
    expect(api.updateProjectVersion.mock.calls[0][1]).not.toHaveProperty('owner_id')
  })

  it('submits the assigned IT PM as an integer when ownership changes', async () => {
    api.getProjectVersion.mockResolvedValue(response(version({ owner: null })))
    const wrapper = await openEditor()
    wrapper.findComponent({ name: 'ElSelect' }).vm.$emit('update:modelValue', 7)
    await save(wrapper)
    expect(api.updateProjectVersion.mock.calls[0][1].owner_id).toBe(7)
  })

  it.each([
    { id: 7, display_name: 'IT PM' },
    { id: 8, display_name: 'Previous owner' },
  ])('clears owner $id to explicit null through the select clear control', async (owner) => {
    api.getProjectVersion.mockResolvedValue(response(version({ owner })))
    const wrapper = await openEditor()
    const select = wrapper.findComponent({ name: 'ElSelect' })
    await select.trigger('mouseenter')
    await select.get('.el-select__clear').trigger('click')
    await save(wrapper)
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(1)
    expect(api.updateProjectVersion.mock.calls[0][1]).toMatchObject({
      lock_version: 3, owner_id: null,
    })
  })

  it('omits ownership when an unassigned owner remains unchanged', async () => {
    api.getProjectVersion.mockResolvedValue(response(version({ owner: null })))
    const wrapper = await openEditor()
    await save(wrapper)
    expect(api.updateProjectVersion.mock.calls[0][1]).not.toHaveProperty('owner_id')
  })

  it('retains all input on validation failure and allows a deliberate retry', async () => {
    api.updateProjectVersion.mockRejectedValueOnce({ response: { status: 422, data: {
      message: 'Validation failed', errors: { code: ['Code already exists'] },
    } } })
    const wrapper = await openEditor()
    await field(wrapper, 'code').setValue('duplicate')
    await field(wrapper, 'release_notes').setValue('Unsaved notes')
    await save(wrapper)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(field(wrapper, 'code').element.value).toBe('duplicate')
    expect(field(wrapper, 'release_notes').element.value).toBe('Unsaved notes')
    expect(wrapper.text()).toContain('Code already exists')
    expect(api.getProjectVersion).toHaveBeenCalledTimes(1)
    await field(wrapper, 'code').setValue('unique')
    await save(wrapper)
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(2)
  })

  it('retains input on a network error', async () => {
    api.updateProjectVersion.mockRejectedValue(new Error('Network unavailable'))
    const wrapper = await openEditor()
    await field(wrapper, 'release_notes').setValue('Keep this draft')
    await save(wrapper)
    expect(field(wrapper, 'release_notes').element.value).toBe('Keep this draft')
    expect(wrapper.text()).toContain('Network unavailable')
  })

  it.each([undefined, null, '3', 0, 3.5])('rejects invalid lock_version %s without a request', async (lock_version) => {
    api.getProjectVersion.mockResolvedValue(response(version({ lock_version })))
    const wrapper = await openEditor()
    await save(wrapper)
    expect(api.updateProjectVersion).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it.each([8, '7'])('rejects an owner value %s that is not the integer assigned PM', async (owner_id) => {
    const wrapper = await openEditor()
    wrapper.findComponent({ name: 'ElSelect' }).vm.$emit('update:modelValue', owner_id)
    await save(wrapper)
    expect(api.updateProjectVersion).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('preserves ownership and permits other metadata when loading the PM fails', async () => {
    api.getProject.mockRejectedValue(new Error('Owner lookup unavailable'))
    const wrapper = await openEditor()
    expect(wrapper.findComponent({ name: 'ElSelect' }).props('disabled')).toBe(true)
    await vi.waitFor(() => expect(wrapper.text()).toContain('Owner lookup unavailable'))
    await field(wrapper, 'description').setValue('Changed description')
    await save(wrapper)
    expect(api.updateProjectVersion.mock.calls[0][1]).toMatchObject({ description: 'Changed description' })
    expect(api.updateProjectVersion.mock.calls[0][1]).not.toHaveProperty('owner_id')
  })

  it('blocks double submission and closing while a save is pending', async () => {
    let resolve
    api.updateProjectVersion.mockReturnValue(new Promise((done) => { resolve = done }))
    const wrapper = await openEditor()
    const button = wrapper.get('[data-testid="save-version-info"]')
    await button.trigger('click')
    await button.trigger('click')
    wrapper.findComponent({ name: 'VersionEditDialog' }).vm.$emit('confirm', { lock_version: 3 })
    await flushPromises()
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(1)
    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="cancel-version-edit"]').attributes('disabled')).toBeDefined()
    resolve(response(version({ lock_version: 4 })))
    await flushPromises()
  })

  it.each(['STALE_VERSION', undefined])('requires explicit reload/review after 409 (%s), without overwriting or retrying', async (error_code) => {
    api.updateProjectVersion.mockRejectedValueOnce({ response: { status: 409, data: {
      error_code, message: 'Changed by another user',
    } } })
    const wrapper = await openEditor()
    await field(wrapper, 'release_notes').setValue('My draft')
    await save(wrapper)
    expect(field(wrapper, 'release_notes').element.value).toBe('My draft')
    expect(wrapper.get('[data-testid="save-version-info"]').attributes('disabled')).toBeDefined()
    wrapper.findComponent({ name: 'VersionEditDialog' }).vm.$emit('confirm', { lock_version: 3 })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(api.getProjectVersion).toHaveBeenCalledTimes(1)
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(1)
    api.getProjectVersion.mockResolvedValue(response(version({ lock_version: 8, release_notes: 'Latest server notes' })))
    await wrapper.get('[data-testid="reload-version-info"]').trigger('click')
    await flushPromises()
    expect(field(wrapper, 'release_notes').element.value).toBe('Latest server notes')
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(1)
    await field(wrapper, 'release_notes').setValue('Reviewed latest notes')
    await save(wrapper)
    expect(api.updateProjectVersion.mock.calls[1][1]).toMatchObject({ lock_version: 8, release_notes: 'Reviewed latest notes' })
  })

  it('keeps the stale draft blocked when reloading fails', async () => {
    api.updateProjectVersion.mockRejectedValue({ response: { status: 409, data: { error_code: 'STALE_VERSION' } } })
    const wrapper = await openEditor()
    await field(wrapper, 'release_notes').setValue('Retained draft')
    await save(wrapper)
    api.getProjectVersion.mockRejectedValue(new Error('Reload failed'))
    await wrapper.get('[data-testid="reload-version-info"]').trigger('click')
    await flushPromises()
    expect(field(wrapper, 'release_notes').element.value).toBe('Retained draft')
    expect(wrapper.get('[data-testid="save-version-info"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Reload failed')
  })

  it.each([
    { status_code: 'READY_TO_RELEASE' },
    { status_code: 'RELEASED' },
    { status_code: 'ARCHIVED' },
    { allowed_actions: [] },
  ])('keeps metadata read-only after a stale reload with %j', async (overrides) => {
    api.updateProjectVersion.mockRejectedValueOnce({ response: { status: 409, data: {
      error_code: 'STALE_VERSION',
    } } })
    const wrapper = await openEditor()
    await save(wrapper)
    api.getProjectVersion.mockResolvedValue(response(version({ lock_version: 8, ...overrides })))
    await wrapper.get('[data-testid="reload-version-info"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="edit-version-command"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="save-version-info"]').attributes('disabled')).toBeDefined()
    expect(field(wrapper, 'release_notes').attributes('disabled')).toBeDefined()
    wrapper.findComponent({ name: 'VersionEditDialog' }).vm.$emit('confirm', { lock_version: 8 })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(api.updateProjectVersion).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['code', 'invalid code'], ['name', '   '], ['release_notes', 'x'.repeat(10001)],
    ['planned_release_date', '2026-08-31'],
  ])('validates %s before saving', async (key, value) => {
    const wrapper = await openEditor()
    if (key === 'planned_release_date') {
      wrapper.findAllComponents({ name: 'ElDatePicker' })[1].vm.$emit('update:modelValue', value)
    } else {
      await field(wrapper, key).setValue(value)
    }
    await save(wrapper)
    expect(api.updateProjectVersion).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})

describe('gate blocker resolution guidance', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    const blockedGate = {
      passed: false,
      target_status_code: 'READY_TO_RELEASE',
      checks: [{
        code: 'release_notes_present',
        label: 'Release notes are present',
        passed: false,
        blocking: true,
        details: { applicable: true, present: false },
      }],
      blocking: [],
    }
    api.getProjectVersion.mockResolvedValue(response(version({ gate_result: blockedGate })))
    api.checkProjectVersionGate.mockResolvedValue(response(blockedGate))
    api.getProject.mockResolvedValue(response({ id: 9, manager: { id: 7, display_name: 'IT PM' } }))
    api.updateProjectVersion.mockResolvedValue(response(version({ lock_version: 4 })))
    api.listProjectVersionHistory.mockResolvedValue(response({ items: [], total_pages: 1 }))
  })
  afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

  it('opens the editor focused on the missing field from a gate blocker', async () => {
    const wrapper = mount(ProjectVersionDetail, {
      attachTo: document.body,
      global: {
        plugins: [ElementPlus],
        stubs: {
          AsyncState: { template: '<div><slot /></div>' },
          VersionProgress: true, VersionStatusTag: true,
          RequirementPlanner: true, VersionHistory: true, ReleaseDialog: true,
          ElDialog: {
            props: ['modelValue'],
            template: '<section v-if="modelValue" role="dialog"><slot /><slot name="footer" /></section>',
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

    await wrapper.get('[data-testid="gate-resolve-release_notes_present"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    const notes = field(wrapper, 'release_notes')
    expect(document.activeElement).toBe(notes.element)
  })
})
