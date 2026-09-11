<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Clock, Delete, Download, Edit, Folder, FolderOpened, Plus, Refresh, Search, Upload } from '@element-plus/icons-vue'
import AsyncState from '@/components/common/AsyncState.vue'
import FilterBar from '@/components/common/FilterBar.vue'
import PaginatedTable from '@/components/common/PaginatedTable.vue'
import { usePagination } from '@/composables/usePagination'
import { mapApiError } from '@/composables/useApiError'
import { useAuthStore } from '@/stores/auth'
import { listProjects } from '@/api/project'
import { listDocuments, uploadDocument, createFolder, downloadDocument, deleteDocument } from '@/api/document'
import { listApiDocuments, getApiDocument, createApiDocument, updateApiDocument, exportApiDocument, getApiDocumentVersions } from '@/api/apiDocument'

const auth = useAuthStore()
const projects = ref([])
const projectsLoading = ref(false)
const projectsError = ref(null)
const selectedProject = ref(null)
const activeTab = ref('files')
const folderId = ref(null)
const folders = ref([])
const rootDocuments = ref([])
const filesLoading = ref(false)
const filesError = ref(null)
const filesReady = ref(false)
const apiRows = ref([])
const apiLoading = ref(false)
const apiError = ref(null)
const apiReady = ref(false)
const keyword = ref('')
const method = ref('')
const appliedKeyword = ref('')
const appliedMethod = ref('')
const busy = ref('')
const filePagination = usePagination()
const apiPagination = usePagination()
const methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
let projectRequest = 0
let fileRequest = 0
let apiRequest = 0
let dialogRequest = 0

const flatFolders = computed(() => {
  const result = []
  function visit(items, parents = []) {
    for (const item of items) {
      const path = [...parents, { id: item.id, name: item.name }]
      result.push({ ...item, path, label: path.map(part => part.name).join(' / ') })
      visit(item.children ?? [], path)
    }
  }
  visit(folders.value)
  return result
})
const currentFolder = computed(() => flatFolders.value.find(item => item.id === folderId.value))
const breadcrumbs = computed(() => currentFolder.value?.path ?? [])
const incompleteFolder = computed(() => Boolean(currentFolder.value && !Array.isArray(currentFolder.value.documents)))
const canManageFiles = computed(() => filesReady.value && !filesLoading.value && !filesError.value)
const canUpload = computed(() => canManageFiles.value && !incompleteFolder.value)
const canCreateFolder = computed(() => canManageFiles.value && (!currentFolder.value || currentFolder.value.path.length < 3))
// StoreApiDocumentRequest checks the actual permission, without a super-admin bypass.
const canCreateApi = computed(() => apiReady.value && !apiError.value && auth.permissions.includes('document.create'))
const currentEntries = computed(() => {
  const childFolders = currentFolder.value ? currentFolder.value.children ?? [] : folders.value
  const documents = currentFolder.value ? currentFolder.value.documents ?? [] : rootDocuments.value
  const rows = [
    ...childFolders.map(item => ({ ...item, kind: 'folder', title: item.name })),
    ...documents.map(item => ({ ...item, kind: 'file' })),
  ]
  const query = appliedKeyword.value.toLocaleLowerCase()
  return rows.filter(item => item.title.toLocaleLowerCase().includes(query))
})
const fileRows = computed(() => {
  const start = (filePagination.page.value - 1) * filePagination.pageSize.value
  return currentEntries.value.slice(start, start + filePagination.pageSize.value)
})
watch(() => currentEntries.value.length, total => {
  const totalPages = Math.ceil(total / filePagination.pageSize.value)
  filePagination.applyPagination({ total, total_pages: totalPages, page: Math.min(filePagination.page.value, Math.max(1, totalPages)) })
})
const tableRows = computed(() => activeTab.value === 'files' ? fileRows.value : apiRows.value)
const pagination = computed(() => activeTab.value === 'files' ? filePagination : apiPagination)

async function loadProjects() {
  const requestId = ++projectRequest
  projectsLoading.value = true
  projectsError.value = null
  projects.value = []
  try {
    const all = []
    let page = 1
    let lastPage = 1
    do {
      const { data } = await listProjects({ page, page_size: 100 })
      if (requestId !== projectRequest) return
      if (!Array.isArray(data.data?.items)) throw new Error('项目列表响应格式不正确')
      all.push(...data.data.items)
      lastPage = data.data.total_pages
      page++
    } while (page <= lastPage)
    projects.value = all
    selectedProject.value = all[0]?.id ?? null
  } catch (error) {
    if (requestId === projectRequest) projectsError.value = mapApiError(error)
  } finally {
    if (requestId === projectRequest) projectsLoading.value = false
  }
}

async function loadFiles() {
  const projectId = selectedProject.value
  const requestId = ++fileRequest
  if (!projectId) return
  filesLoading.value = true
  filesError.value = null
  filesReady.value = false
  try {
    const { data } = await listDocuments(projectId)
    if (requestId !== fileRequest || projectId !== selectedProject.value) return
    if (!Array.isArray(data.data?.folders) || !Array.isArray(data.data?.root_documents)) {
      throw new Error('文档列表响应格式不正确')
    }
    folders.value = data.data.folders
    rootDocuments.value = data.data.root_documents
    if (folderId.value && !currentFolder.value) folderId.value = null
    filesReady.value = true
  } catch (error) {
    if (requestId !== fileRequest || projectId !== selectedProject.value) return
    folders.value = []
    rootDocuments.value = []
    filesError.value = mapApiError(error)
  } finally {
    if (requestId === fileRequest) filesLoading.value = false
  }
}

async function loadApis() {
  const projectId = selectedProject.value
  const requestId = ++apiRequest
  if (!projectId) return
  apiLoading.value = true
  apiError.value = null
  apiReady.value = false
  try {
    const params = { ...apiPagination.requestParams.value }
    if (folderId.value) params.folder_id = folderId.value
    if (appliedKeyword.value) params.keyword = appliedKeyword.value
    if (appliedMethod.value) params.request_method = appliedMethod.value
    const { data } = await listApiDocuments(projectId, params)
    if (requestId !== apiRequest || projectId !== selectedProject.value) return
    const payload = data.data
    if (!Array.isArray(payload?.items)) throw new Error('接口文档列表响应格式不正确')
    apiPagination.applyPagination(payload)
    if (payload.page > Math.max(1, payload.total_pages)) {
      apiPagination.setPage(Math.max(1, payload.total_pages))
      return await loadApis()
    }
    apiRows.value = payload.items
    apiReady.value = true
  } catch (error) {
    if (requestId !== apiRequest || projectId !== selectedProject.value) return
    apiRows.value = []
    apiError.value = mapApiError(error)
  } finally {
    if (requestId === apiRequest) apiLoading.value = false
  }
}

watch(selectedProject, () => {
  fileRequest++
  apiRequest++
  dialogRequest++
  folders.value = []
  rootDocuments.value = []
  apiRows.value = []
  filesReady.value = false
  apiReady.value = false
  filesError.value = null
  apiError.value = null
  folderId.value = null
  filePagination.resetPage()
  apiPagination.resetPage()
  uploadVisible.value = folderVisible.value = apiVisible.value = versionsVisible.value = false
  loadFiles()
  if (activeTab.value === 'api') loadApis()
})
watch(activeTab, () => { if (activeTab.value === 'api') loadApis() })
function navigateFolder(id) {
  if (busy.value) return
  folderId.value = id
  filePagination.resetPage()
  apiPagination.resetPage()
  if (activeTab.value === 'api') loadApis()
}
function search() {
  appliedKeyword.value = keyword.value.trim()
  appliedMethod.value = method.value
  filePagination.resetPage()
  apiPagination.resetPage()
  if (activeTab.value === 'api') loadApis()
}
function resetFilters() { keyword.value = ''; method.value = ''; search() }
function setPage(page) {
  pagination.value.setPage(page)
  if (activeTab.value === 'api') loadApis()
}
function setPageSize(size) {
  pagination.value.setPageSize(size)
  if (activeTab.value === 'api') loadApis()
}
function retryTable() { return activeTab.value === 'files' ? loadFiles() : loadApis() }
function allowed(row, action) {
  return !Array.isArray(row.allowed_actions) || row.allowed_actions.includes(action)
}
function errorText(error) {
  const mapped = mapApiError(error)
  const fields = Object.values(mapped.fieldErrors).flat().join('；')
  return fields ? mapped.message + '：' + fields : mapped.message
}
async function actionError(error) {
  if (error?.response?.data instanceof Blob) {
    try {
      const data = JSON.parse(await error.response.data.text())
      return errorText({ response: { status: error.response.status, data } })
    } catch { /* Non-JSON download failures still retain their HTTP status. */ }
  }
  return errorText(error)
}

const folderVisible = ref(false)
const folderName = ref('')
const folderError = ref('')
function openFolder() {
  folderName.value = ''
  folderError.value = ''
  folderVisible.value = true
}
async function saveFolder() {
  if (busy.value || !canCreateFolder.value) return
  const name = folderName.value.trim()
  if (!name || name.length > 100) { folderError.value = '请输入 1 至 100 字的文件夹名称'; return }
  const projectId = selectedProject.value
  busy.value = 'folder'
  folderError.value = ''
  try {
    await createFolder(projectId, { name, parent_id: folderId.value })
    if (projectId !== selectedProject.value) return
    folderVisible.value = false
    ElMessage.success('文件夹创建成功')
    await loadFiles()
  } catch (error) {
    if (projectId === selectedProject.value) folderError.value = errorText(error)
  } finally { busy.value = '' }
}

const uploadVisible = ref(false)
const uploadFiles = ref([])
const uploadFolder = ref(null)
const uploadError = ref('')
const uploadTargets = computed(() => flatFolders.value.filter(item => Array.isArray(item.documents)))
function openUpload() {
  uploadFiles.value = []
  uploadFolder.value = folderId.value
  uploadError.value = ''
  uploadVisible.value = true
}
async function submitUpload() {
  if (busy.value || !canUpload.value) return
  if (!uploadFiles.value.length) { uploadError.value = '请选择文件'; return }
  if (uploadFolder.value && !uploadTargets.value.some(item => item.id === uploadFolder.value)) {
    uploadError.value = '目标文件夹不可用'; return
  }
  if (uploadFiles.value.some(item => !item.raw || item.raw.size > 20 * 1024 * 1024)) {
    uploadError.value = '单个文件不能超过 20 MB'; return
  }
  const projectId = selectedProject.value
  const targetId = uploadFolder.value
  busy.value = 'upload'
  uploadError.value = ''
  const failures = []
  let uploaded = 0
  try {
    for (const file of [...uploadFiles.value]) {
      if (projectId !== selectedProject.value) break
      const body = new FormData()
      body.append('file', file.raw)
      if (targetId) body.append('folder_id', String(targetId))
      try {
        await uploadDocument(projectId, body)
        uploaded++
        uploadFiles.value = uploadFiles.value.filter(item => item.uid !== file.uid)
      } catch (error) { failures.push(file.name + '：' + errorText(error)) }
    }
    if (projectId !== selectedProject.value) return
    uploadError.value = failures.join('；')
    if (uploaded) {
      ElMessage.success('已上传 ' + uploaded + ' 个文件')
      await loadFiles()
    }
    if (!uploadFiles.value.length) uploadVisible.value = false
  } finally { busy.value = '' }
}

function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
async function downloadFile(row) {
  if (busy.value || !canManageFiles.value || !allowed(row, 'download')) return
  busy.value = 'download-' + row.id
  try {
    const { data } = await downloadDocument(row.id)
    if (!(data instanceof Blob)) throw new Error('下载响应格式不正确')
    saveBlob(data, row.title)
  } catch (error) { ElMessage.error(await actionError(error)) }
  finally { busy.value = '' }
}
async function removeFile(row) {
  if (busy.value || !canManageFiles.value || !allowed(row, 'delete')) return
  const projectId = selectedProject.value
  busy.value = 'delete-' + row.id
  try {
    await ElMessageBox.confirm('确定将“' + row.title + '”移入回收站？', '删除确认', { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' })
    if (projectId !== selectedProject.value) return
    await deleteDocument(row.id)
    if (projectId !== selectedProject.value) return
    ElMessage.success('文件已移至回收站')
    await loadFiles()
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(await actionError(error))
  } finally { busy.value = '' }
}

const apiVisible = ref(false)
const apiDetailLoading = ref(false)
const apiDetailError = ref(null)
const apiSaveError = ref('')
const editingId = ref(null)
const apiForm = reactive({ name: '', path: '', method: 'GET', authType: '', notes: '', requestJson: '[]', responseJson: '[]', requirementId: null })
function openCreateApi() {
  if (!canCreateApi.value || busy.value) return
  editingId.value = null
  apiDetailError.value = null
  apiSaveError.value = ''
  Object.assign(apiForm, { name: '', path: '', method: 'GET', authType: '', notes: '', requestJson: '[]', responseJson: '[]', requirementId: null })
  apiVisible.value = true
}
async function editApi(row) {
  if (busy.value || !allowed(row, 'update')) return
  const requestId = ++dialogRequest
  editingId.value = row.id
  apiVisible.value = true
  apiDetailLoading.value = true
  apiDetailError.value = null
  apiSaveError.value = ''
  try {
    const { data } = await getApiDocument(row.id)
    if (requestId !== dialogRequest) return
    const doc = data.data
    Object.assign(apiForm, {
      name: doc.api_name, path: doc.request_path, method: doc.request_method,
      authType: doc.auth_type ?? '', notes: doc.rich_text_body ?? '',
      requestJson: JSON.stringify(doc.request_params ?? [], null, 2),
      responseJson: JSON.stringify(doc.response_params ?? [], null, 2), requirementId: doc.requirement_id ?? null,
    })
  } catch (error) {
    if (requestId === dialogRequest) apiDetailError.value = mapApiError(error)
  } finally {
    if (requestId === dialogRequest) apiDetailLoading.value = false
  }
}
function parseParams(text, label) {
  let value
  try { value = JSON.parse(text) } catch { throw new Error(label + '必须为有效 JSON') }
  if (value === null || typeof value !== 'object') throw new Error(label + '必须为 JSON 数组或对象')
  return value
}
async function saveApi() {
  if (busy.value || apiDetailLoading.value || apiDetailError.value || (!editingId.value && !canCreateApi.value)) return
  let payload
  try {
    if (!apiForm.name.trim() || !apiForm.path.trim()) throw new Error('接口名称和请求路径不能为空')
    payload = {
      api_name: apiForm.name.trim(), request_path: apiForm.path.trim(), request_method: apiForm.method,
      auth_type: apiForm.authType || null, rich_text_body: apiForm.notes,
      request_params: parseParams(apiForm.requestJson, '请求参数'),
      response_params: parseParams(apiForm.responseJson, '响应参数'), requirement_id: apiForm.requirementId,
    }
  } catch (error) { apiSaveError.value = error.message; return }
  const projectId = selectedProject.value
  busy.value = 'api-save'
  apiSaveError.value = ''
  try {
    if (editingId.value) await updateApiDocument(editingId.value, payload)
    else await createApiDocument(projectId, { ...payload, folder_id: folderId.value })
    if (projectId !== selectedProject.value) return
    apiVisible.value = false
    ElMessage.success('接口文档已保存')
    await loadApis()
  } catch (error) {
    if (projectId === selectedProject.value) apiSaveError.value = errorText(error)
  } finally { busy.value = '' }
}
async function exportApi(row) {
  if (busy.value || !allowed(row, 'export')) return
  busy.value = 'export-' + row.id
  try {
    const { data } = await exportApiDocument(row.id)
    if (!data.data?.openapi) throw new Error('OpenAPI 导出响应格式不正确')
    saveBlob(new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' }), row.api_name + '.openapi.json')
  } catch (error) { ElMessage.error(await actionError(error)) }
  finally { busy.value = '' }
}

const versionsVisible = ref(false)
const versionsLoading = ref(false)
const versionsError = ref(null)
const versions = ref([])
const versionDoc = ref(null)
async function showVersions(row) {
  const requestId = ++dialogRequest
  versionDoc.value = row
  versionsVisible.value = true
  versionsLoading.value = true
  versionsError.value = null
  versions.value = []
  try {
    const { data } = await getApiDocumentVersions(row.id)
    if (requestId !== dialogRequest) return
    if (!Array.isArray(data.data)) throw new Error('版本列表响应格式不正确')
    versions.value = data.data
  } catch (error) {
    if (requestId === dialogRequest) versionsError.value = mapApiError(error)
  } finally {
    if (requestId === dialogRequest) versionsLoading.value = false
  }
}
function formatSize(bytes) {
  if (bytes == null) return '-'
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}
function formatDate(value) { return value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '-' }
onMounted(loadProjects)
onBeforeUnmount(() => { projectRequest++; fileRequest++; apiRequest++; dialogRequest++ })
</script>
<template>
  <div class="page-container document-page">
    <header class="document-heading">
      <h1>文档管理</h1>
      <el-select v-model="selectedProject" :loading="projectsLoading" :disabled="Boolean(busy)" filterable aria-label="项目">
        <el-option v-for="project in projects" :key="project.id" :label="project.name" :value="project.id" />
      </el-select>
    </header>
    <AsyncState :loading="projectsLoading" :error="projectsError" :empty="!projects.length" empty-title="暂无可访问项目" @retry="loadProjects">
      <nav class="document-toolbar" aria-label="文档分类">
        <el-button data-testid="tab-files" :type="activeTab === 'files' ? 'primary' : 'default'" :icon="Folder" @click="activeTab = 'files'">项目文件</el-button>
        <el-button data-testid="tab-api" :type="activeTab === 'api' ? 'primary' : 'default'" :icon="Edit" @click="activeTab = 'api'">接口文档</el-button>
        <span class="toolbar-spacer" />
        <template v-if="activeTab === 'files'">
          <el-button data-testid="folder-create-open" :icon="Plus" :disabled="!canCreateFolder || Boolean(busy)" @click="openFolder">新建文件夹</el-button>
          <el-button data-testid="upload-open" :icon="Upload" type="primary" :disabled="!canUpload || Boolean(busy)" @click="openUpload">上传文件</el-button>
        </template>
        <el-button v-else-if="canCreateApi" data-testid="api-create-open" :icon="Plus" type="primary" :disabled="Boolean(busy)" @click="openCreateApi">新建接口</el-button>
      </nav>
      <FilterBar @search="search" @reset="resetFilters">
        <el-input v-model="keyword" data-testid="keyword" placeholder="搜索文档名称" clearable @keyup.enter="search" />
        <el-select v-if="activeTab === 'api'" v-model="method" clearable placeholder="全部请求方法">
          <el-option v-for="item in methods" :key="item" :label="item" :value="item" />
        </el-select>
      </FilterBar>
      <nav class="breadcrumbs" aria-label="文件夹路径">
        <el-button data-testid="folder-root" text @click="navigateFolder(null)">根目录</el-button>
        <template v-for="part in breadcrumbs" :key="part.id">
          <span>/</span><el-button text @click="navigateFolder(part.id)">{{ part.name }}</el-button>
        </template>
      </nav>
      <el-alert v-if="activeTab === 'files' && incompleteFolder" data-testid="nested-documents-warning" title="此文件夹的文件清单未返回，请刷新或联系管理员。" type="warning" :closable="false" />
      <PaginatedTable :rows="tableRows" :loading="activeTab === 'files' ? filesLoading : apiLoading"
        :error="activeTab === 'files' ? filesError : apiError" :page="pagination.page.value"
        :page-size="pagination.pageSize.value" :total="pagination.total.value"
        empty-title="暂无文档" @retry="retryTable" @page-change="setPage" @page-size-change="setPageSize">
        <table class="document-table">
          <thead><tr><th>名称</th><th>{{ activeTab === 'files' ? '大小' : '请求路径' }}</th><th>版本</th><th>更新人</th><th>更新时间</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="row in tableRows" :key="(row.kind || 'api') + row.id">
              <td>
                <el-button v-if="row.kind === 'folder'" :data-testid="'folder-' + row.id" text :icon="FolderOpened" @click="navigateFolder(row.id)">{{ row.name }}</el-button>
                <span v-else>{{ activeTab === 'files' ? row.title : row.api_name }}</span>
              </td>
              <td>{{ row.kind === 'folder' ? '-' : activeTab === 'files' ? formatSize(row.file_size) : row.request_method + ' ' + row.request_path }}</td>
              <td>{{ row.version ?? '-' }}</td>
              <td>{{ row.uploader?.display_name || row.updater?.display_name || '-' }}</td>
              <td>{{ formatDate(row.updated_at) }}</td>
              <td class="document-actions">
                <template v-if="row.kind === 'file'">
                  <el-button v-if="allowed(row, 'download')" :data-testid="'download-' + row.id" text :icon="Download" aria-label="下载文件" :disabled="Boolean(busy)" @click="downloadFile(row)" />
                  <el-button v-if="allowed(row, 'delete')" :data-testid="'delete-' + row.id" text type="danger" :icon="Delete" aria-label="删除文件" :disabled="Boolean(busy)" @click="removeFile(row)" />
                </template>
                <template v-else-if="activeTab === 'api'">
                  <el-button v-if="allowed(row, 'update')" :data-testid="'api-edit-' + row.id" text :icon="Edit" aria-label="编辑接口" @click="editApi(row)" />
                  <el-button :data-testid="'api-versions-' + row.id" text :icon="Clock" aria-label="历史版本" @click="showVersions(row)" />
                  <el-button v-if="allowed(row, 'export')" :data-testid="'api-export-' + row.id" text :icon="Download" aria-label="导出 OpenAPI" @click="exportApi(row)" />
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </PaginatedTable>
    </AsyncState>
    <el-dialog v-model="folderVisible" title="新建文件夹" width="min(480px, 94vw)">
      <el-input v-model="folderName" data-testid="folder-name" aria-label="文件夹名称" maxlength="100" />
      <p v-if="folderError" class="form-error">{{ folderError }}</p>
      <template #footer><el-button @click="folderVisible = false">取消</el-button><el-button data-testid="folder-save" type="primary" :loading="busy === 'folder'" @click="saveFolder">创建</el-button></template>
    </el-dialog>
    <el-dialog v-model="uploadVisible" title="上传文件" width="min(600px, 94vw)">
      <el-select v-model="uploadFolder" clearable placeholder="根目录" aria-label="目标文件夹">
        <el-option v-for="item in uploadTargets" :key="item.id" :label="item.label" :value="item.id" />
      </el-select>
      <el-upload v-model:file-list="uploadFiles" :auto-upload="false" multiple drag :disabled="Boolean(busy)">
        <el-icon :size="32"><Upload /></el-icon><div>选择文件</div>
      </el-upload>
      <p v-if="uploadError" class="form-error">{{ uploadError }}</p>
      <template #footer><el-button @click="uploadVisible = false">取消</el-button><el-button data-testid="upload-save" type="primary" :loading="busy === 'upload'" @click="submitUpload">上传</el-button></template>
    </el-dialog>
    <el-dialog v-model="apiVisible" :title="editingId ? '编辑接口文档' : '新建接口文档'" width="min(820px, 94vw)">
      <AsyncState :loading="apiDetailLoading" :error="apiDetailError" @retry="editApi({ id: editingId })">
        <el-form label-position="top">
          <div class="form-grid">
            <el-form-item label="接口名称" required><el-input v-model="apiForm.name" data-testid="api-name" maxlength="200" /></el-form-item>
            <el-form-item label="请求路径" required><el-input v-model="apiForm.path" data-testid="api-path" /></el-form-item>
            <el-form-item label="请求方法"><el-select v-model="apiForm.method"><el-option v-for="item in methods" :key="item" :label="item" :value="item" /></el-select></el-form-item>
            <el-form-item label="认证方式"><el-select v-model="apiForm.authType" clearable><el-option label="Bearer Token" value="bearer" /><el-option label="Basic Auth" value="basic" /><el-option label="API Key" value="api_key" /></el-select></el-form-item>
          </div>
          <el-form-item label="请求参数 JSON"><el-input v-model="apiForm.requestJson" data-testid="request-params" type="textarea" :rows="5" /></el-form-item>
          <el-form-item label="响应参数 JSON"><el-input v-model="apiForm.responseJson" type="textarea" :rows="5" /></el-form-item>
          <el-form-item label="说明"><el-input v-model="apiForm.notes" type="textarea" :rows="4" /></el-form-item>
        </el-form>
      </AsyncState>
      <p v-if="apiSaveError" class="form-error">{{ apiSaveError }}</p>
      <template #footer><el-button @click="apiVisible = false">取消</el-button><el-button data-testid="api-save" type="primary" :loading="busy === 'api-save'" @click="saveApi">保存</el-button></template>
    </el-dialog>
    <el-dialog v-model="versionsVisible" title="接口历史版本" width="min(820px, 94vw)">
      <AsyncState :loading="versionsLoading" :error="versionsError" :empty="!versions.length" @retry="showVersions(versionDoc)">
        <section v-for="version in versions" :key="version.id" class="revision">
          <h3>版本 {{ version.version_number || version.version }}</h3>
          <pre>{{ JSON.stringify(version.snapshot || version, null, 2) }}</pre>
        </section>
      </AsyncState>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.document-page { display: grid; gap: 16px; min-width: 0; }
.document-heading, .document-toolbar, .breadcrumbs { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.document-heading { justify-content: space-between; }
.document-heading h1 { font-size: 24px; margin: 0; }
.document-heading > .el-select { width: 260px; }
.document-toolbar { margin-bottom: 16px; }
.toolbar-spacer { flex: 1; }
.breadcrumbs { min-height: 48px; }
.document-table { width: 100%; min-width: 800px; border-collapse: collapse; }
.document-table th, .document-table td { text-align: left; padding: 12px 16px; border-bottom: 1px solid $color-border; overflow-wrap: anywhere; }
.document-table th { background: $color-canvas; font-size: 13px; color: $color-muted; }
.document-actions { white-space: nowrap; }
.document-actions .el-button + .el-button { margin: 0; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-error { color: $color-danger; overflow-wrap: anywhere; }
.revision { border-bottom: 1px solid $color-border; padding: 12px 0; }
.revision pre { white-space: pre-wrap; overflow-wrap: anywhere; }
:deep(.el-upload) { width: 100%; margin-top: 16px; }
:deep(.el-upload-dragger) { width: 100%; }
:deep(.filter-bar__fields > .el-input), :deep(.filter-bar__fields > .el-select) { width: 240px; }
@media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } .document-heading > .el-select { width: 100%; } }
</style>
