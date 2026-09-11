import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ElementPlus from 'element-plus'
import DocumentView from './DocumentView.vue'
import PaginatedTable from '@/components/common/PaginatedTable.vue'
import FilterBar from '@/components/common/FilterBar.vue'

const api = vi.hoisted(() => ({
  listProjects: vi.fn(), listDocuments: vi.fn(), uploadDocument: vi.fn(),
  createFolder: vi.fn(), downloadDocument: vi.fn(), deleteDocument: vi.fn(),
  listApiDocuments: vi.fn(), getApiDocument: vi.fn(), createApiDocument: vi.fn(),
  updateApiDocument: vi.fn(), exportApiDocument: vi.fn(), getApiDocumentVersions: vi.fn(),
  confirm: vi.fn(), success: vi.fn(), error: vi.fn(), permissions: [],
}))
vi.mock('@/api/project', () => ({ listProjects: api.listProjects }))
vi.mock('@/api/document', () => api)
vi.mock('@/api/apiDocument', () => api)
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ permissions: api.permissions }) }))
vi.mock('element-plus', async (original) => ({
  ...await original(),
  ElMessage: { success: api.success, error: api.error, warning: vi.fn() },
  ElMessageBox: { confirm: api.confirm },
}))

const response = data => ({ data: { code: 200, message: 'success', data } })
const page = (items, extra = {}) => response({
  items, page: 1, page_size: 20, total: items.length, total_pages: 1, ...extra,
})
const file = (id = 11) => ({
  id, title: 'report-' + id + '.pdf', file_size: 2048, version: 3,
  uploader: { display_name: 'Uploader' }, updated_at: '2026-09-08T06:00:00Z',
})
const folder = () => ({
  id: 21, name: 'Design', level: 0, parent_id: null, documents: [file(12)],
  children: [{ id: 22, name: 'Nested', level: 1, parent_id: 21, children: [] }],
})
const apiDoc = () => ({
  id: 31, api_name: 'Live endpoint', request_method: 'GET', request_path: '/live',
  auth_type: 'bearer', request_params: [{ name: 'cursor', type: 'string', required: false }],
  response_params: { 200: { description: 'OK' } }, rich_text_body: 'Live notes',
  folder_id: 21, requirement_id: 41, version: 4, updater: { display_name: 'Editor' },
})
const deferred = () => {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
let wrapper
function render() {
  wrapper = mount(DocumentView, {
    attachTo: document.body,
    global: { plugins: [ElementPlus], stubs: { transition: false, WarningFilled: true } },
  })
  return wrapper
}
async function ready() { render(); await flushPromises(); return wrapper }
async function apiTab() {
  await wrapper.get('[data-testid="tab-api"]').trigger('click')
  await flushPromises()
}
beforeEach(() => {
  vi.clearAllMocks()
  api.permissions = ['document.create']
  api.listProjects.mockResolvedValue(page([{ id: 7, name: 'Cloud project' }, { id: 8, name: 'Other project' }]))
  api.listDocuments.mockResolvedValue(response({ folders: [folder()], root_documents: [file()] }))
  api.listApiDocuments.mockResolvedValue(page([apiDoc()]))
  api.getApiDocument.mockResolvedValue(response(apiDoc()))
  api.getApiDocumentVersions.mockResolvedValue(response([{ id: 51, version_number: 4, snapshot: apiDoc() }]))
  api.createFolder.mockResolvedValue(response({ id: 23, name: 'New folder' }))
  api.uploadDocument.mockResolvedValue(response(file(13)))
  api.deleteDocument.mockResolvedValue(response(null))
  api.createApiDocument.mockResolvedValue(response(apiDoc()))
  api.updateApiDocument.mockResolvedValue(response({ ...apiDoc(), version: 5 }))
  api.exportApiDocument.mockResolvedValue(response({ openapi: '3.0.3', paths: { '/live': {} } }))
  api.confirm.mockResolvedValue('confirm')
  vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
  URL.createObjectURL = vi.fn(() => 'blob:document-test')
  URL.revokeObjectURL = vi.fn()
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); vi.unstubAllGlobals() })

describe('DocumentView cloud API workflows', () => {
  it('loads every project page and real file metadata without static records', async () => {
    api.listProjects
      .mockResolvedValueOnce(page([{ id: 7, name: 'Cloud project' }], { total_pages: 2, total: 2 }))
      .mockResolvedValueOnce(page([{ id: 8, name: 'Other project' }], { page: 2, total_pages: 2, total: 2 }))
    await ready()
    expect(api.listProjects).toHaveBeenNthCalledWith(2, { page: 2, page_size: 100 })
    expect(api.listDocuments).toHaveBeenCalledWith(7)
    expect(wrapper.text()).toContain('report-11.pdf')
    expect(wrapper.text()).toContain('Uploader')
    expect(wrapper.text()).not.toContain('SAP B1')
    expect(wrapper.findComponent(FilterBar).exists()).toBe(true)
    expect(wrapper.findComponent(PaginatedTable).props('total')).toBe(2)
  })

  it('clears failed lists and retries without fake fallback data', async () => {
    api.listDocuments.mockRejectedValueOnce({ response: { status: 403, data: { message: 'Access denied' } } })
    await ready()
    expect(wrapper.text()).toContain('Access denied')
    expect(wrapper.text()).not.toContain('report-11.pdf')
    expect(wrapper.get('[data-testid="upload-open"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('report-11.pdf')
  })

  it('shows project loading, failure/retry and an authoritative empty state', async () => {
    const pending = deferred()
    api.listProjects.mockReturnValueOnce(pending.promise)
    render()
    await flushPromises()
    expect(wrapper.find('[data-state="loading"]').exists()).toBe(true)
    pending.reject(new Error('Project service unavailable'))
    await flushPromises()
    expect(api.listDocuments).not.toHaveBeenCalled()
    api.listProjects.mockResolvedValueOnce(page([]))
    await wrapper.get('[data-testid="retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-state="empty"]').exists()).toBe(true)
    expect(api.listDocuments).not.toHaveBeenCalled()
  })

  it('ignores an old project response after project navigation', async () => {
    const old = deferred()
    api.listDocuments.mockReturnValueOnce(old.promise).mockResolvedValueOnce(response({ folders: [], root_documents: [file(88)] }))
    await ready()
    wrapper.vm.selectedProject = 8
    await flushPromises()
    old.resolve(response({ folders: [folder()], root_documents: [file()] }))
    await flushPromises()
    expect(wrapper.text()).toContain('report-88.pdf')
    expect(wrapper.text()).not.toContain('report-11.pdf')
  })

  it('navigates real folders and does not call an absent nested listing empty', async () => {
    await ready()
    await wrapper.get('[data-testid="folder-21"]').trigger('click')
    expect(wrapper.text()).toContain('report-12.pdf')
    await wrapper.get('[data-testid="folder-22"]').trigger('click')
    expect(wrapper.get('[data-testid="nested-documents-warning"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="upload-open"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="folder-root"]').trigger('click')
    expect(wrapper.text()).toContain('report-11.pdf')
  })

  it('paginates and filters the unpaginated file response locally', async () => {
    api.listDocuments.mockResolvedValue(response({ folders: [], root_documents: Array.from({ length: 25 }, (_, i) => file(i + 1)) }))
    await ready()
    wrapper.findComponent(PaginatedTable).vm.$emit('page-change', 2)
    await flushPromises()
    expect(wrapper.text()).toContain('report-25.pdf')
    expect(wrapper.text()).not.toContain('report-1.pdf')
    await wrapper.get('[data-testid="keyword"]').setValue('report-1.pdf')
    wrapper.findComponent(FilterBar).vm.$emit('search')
    await flushPromises()
    expect(wrapper.findComponent(PaginatedTable).props('page')).toBe(1)
    expect(wrapper.findComponent(PaginatedTable).props('total')).toBe(1)
  })

  it('creates folders with the real parent id and preserves failed form input', async () => {
    await ready()
    await wrapper.get('[data-testid="folder-21"]').trigger('click')
    await wrapper.get('[data-testid="folder-create-open"]').trigger('click')
    await wrapper.get('[data-testid="folder-name"]').setValue(' New folder ')
    api.createFolder.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Invalid folder', errors: { name: ['Name rejected'] } } } })
    await wrapper.get('[data-testid="folder-save"]').trigger('click')
    await flushPromises()
    expect(api.createFolder).toHaveBeenCalledWith(7, { name: 'New folder', parent_id: 21 })
    expect(wrapper.text()).toContain('Name rejected')
    expect(api.success).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="folder-save"]').trigger('click')
    await flushPromises()
    expect(api.listDocuments).toHaveBeenCalledTimes(2)
  })

  it('uploads real multipart files and retains only failures for retry', async () => {
    await ready()
    await wrapper.get('[data-testid="folder-21"]').trigger('click')
    await wrapper.get('[data-testid="upload-open"]').trigger('click')
    const first = new File(['first'], 'first.pdf', { type: 'application/pdf' })
    const second = new File(['second'], 'second.pdf', { type: 'application/pdf' })
    wrapper.vm.uploadFiles = [{ uid: 1, name: first.name, raw: first }, { uid: 2, name: second.name, raw: second }]
    api.uploadDocument.mockResolvedValueOnce(response(file(13))).mockRejectedValueOnce(new Error('Upload interrupted'))
    await wrapper.get('[data-testid="upload-save"]').trigger('click')
    await flushPromises()
    const [projectId, body] = api.uploadDocument.mock.calls[0]
    expect(projectId).toBe(7)
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('folder_id')).toBe('21')
    expect(body.get('file')).toBe(first)
    expect(wrapper.vm.uploadFiles.map(item => item.uid)).toEqual([2])
    expect(wrapper.text()).toContain('Upload interrupted')
    await wrapper.get('[data-testid="upload-save"]').trigger('click')
    await flushPromises()
    expect(api.uploadDocument).toHaveBeenCalledTimes(3)
  })

  it('rejects oversized uploads before making requests', async () => {
    await ready()
    await wrapper.get('[data-testid="upload-open"]').trigger('click')
    const large = new File(['x'], 'large.pdf')
    Object.defineProperty(large, 'size', { value: 20 * 1024 * 1024 + 1 })
    wrapper.vm.uploadFiles = [{ uid: 1, name: large.name, raw: large }]
    await wrapper.get('[data-testid="upload-save"]').trigger('click')
    await flushPromises()
    expect(api.uploadDocument).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('20 MB')
  })

  it('downloads a real blob and does not treat blob error responses as files', async () => {
    await ready()
    const blob = new Blob(['pdf'], { type: 'application/pdf' })
    api.downloadDocument.mockResolvedValueOnce({ data: blob })
    await wrapper.get('[data-testid="download-11"]').trigger('click')
    await flushPromises()
    expect(api.downloadDocument).toHaveBeenCalledWith(11)
    expect(URL.createObjectURL).toHaveBeenCalledWith(blob)
    api.downloadDocument.mockRejectedValueOnce({ response: { status: 404, data: { message: 'File missing' } } })
    await wrapper.get('[data-testid="download-11"]').trigger('click')
    await flushPromises()
    expect(api.error).toHaveBeenCalledWith('File missing')
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
  })

  it('confirms file deletion, keeps failures, and never offers unsupported deletes', async () => {
    await ready()
    expect(wrapper.find('[data-testid="delete-folder-21"]').exists()).toBe(false)
    api.deleteDocument.mockRejectedValueOnce(new Error('Delete failed'))
    await wrapper.get('[data-testid="delete-11"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalled()
    expect(wrapper.text()).toContain('report-11.pdf')
    expect(api.success).not.toHaveBeenCalled()
    api.listDocuments.mockResolvedValueOnce(response({ folders: [], root_documents: [] }))
    await wrapper.get('[data-testid="delete-11"]').trigger('click')
    await flushPromises()
    expect(api.deleteDocument).toHaveBeenCalledWith(11)
    expect(wrapper.text()).not.toContain('report-11.pdf')
    await apiTab()
    expect(wrapper.find('[data-testid="delete-api-31"]').exists()).toBe(false)
  })

  it('uses backend API-doc filters, pagination, and real detail payloads', async () => {
    await ready()
    await apiTab()
    expect(api.listApiDocuments).toHaveBeenLastCalledWith(7, { page: 1, page_size: 20 })
    wrapper.findComponent(PaginatedTable).vm.$emit('page-size-change', 10)
    await flushPromises()
    expect(api.listApiDocuments).toHaveBeenLastCalledWith(7, { page: 1, page_size: 10 })
    await wrapper.get('[data-testid="api-edit-31"]').trigger('click')
    await flushPromises()
    expect(api.getApiDocument).toHaveBeenCalledWith(31)
    expect(wrapper.get('[data-testid="api-name"]').element.value).toBe('Live endpoint')
    await wrapper.get('[data-testid="api-name"]').setValue('Renamed endpoint')
    await wrapper.get('[data-testid="api-save"]').trigger('click')
    await flushPromises()
    expect(api.updateApiDocument).toHaveBeenCalledWith(31, expect.objectContaining({
      api_name: 'Renamed endpoint', request_path: '/live', request_method: 'GET',
      response_params: { 200: { description: 'OK' } }, rich_text_body: 'Live notes', requirement_id: 41,
    }))
    expect(api.updateApiDocument.mock.calls[0][1]).not.toHaveProperty('folder_id')
    expect(api.updateApiDocument.mock.calls[0][1]).not.toHaveProperty('version')
  })

  it('requires the exact create permission while allowing project-authorized editing', async () => {
    api.permissions = ['document.edit_api']
    await ready()
    await apiTab()
    expect(wrapper.find('[data-testid="api-create-open"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="api-edit-31"]').exists()).toBe(true)
  })

  it('creates API docs with the chosen folder and prevents invalid JSON or duplicate saves', async () => {
    await ready()
    await wrapper.get('[data-testid="folder-21"]').trigger('click')
    await apiTab()
    await wrapper.get('[data-testid="api-create-open"]').trigger('click')
    await wrapper.get('[data-testid="api-name"]').setValue('New endpoint')
    await wrapper.get('[data-testid="api-path"]').setValue('/new')
    await wrapper.get('[data-testid="request-params"]').setValue('invalid')
    await wrapper.get('[data-testid="api-save"]').trigger('click')
    await flushPromises()
    expect(api.createApiDocument).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="request-params"]').setValue('[]')
    const pending = deferred()
    api.createApiDocument.mockReturnValueOnce(pending.promise)
    await wrapper.get('[data-testid="api-save"]').trigger('click')
    await wrapper.get('[data-testid="api-save"]').trigger('click')
    expect(api.createApiDocument).toHaveBeenCalledTimes(1)
    expect(api.createApiDocument).toHaveBeenCalledWith(7, expect.objectContaining({ folder_id: 21, api_name: 'New endpoint' }))
    pending.resolve(response(apiDoc()))
    await flushPromises()
  })

  it('exports server OpenAPI JSON and shows real version snapshots', async () => {
    await ready()
    await apiTab()
    await wrapper.get('[data-testid="api-export-31"]').trigger('click')
    await flushPromises()
    expect(api.exportApiDocument).toHaveBeenCalledWith(31)
    expect(URL.createObjectURL).toHaveBeenCalledWith(expect.any(Blob))
    await wrapper.get('[data-testid="api-versions-31"]').trigger('click')
    await flushPromises()
    expect(api.getApiDocumentVersions).toHaveBeenCalledWith(31)
    expect(wrapper.text()).toContain('Live notes')
  })
})
