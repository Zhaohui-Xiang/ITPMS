<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Search,
  Folder,
  Document,
  FolderOpened,
  Plus,
  Upload,
  UploadFilled,
  Delete,
  Download,
  View,
  Edit,
  RefreshRight,
  Loading,
  Setting,
  Clock,
  InfoFilled,
  HomeFilled,
  CirclePlus,
  Remove,
  User
} from '@element-plus/icons-vue'
import {
  listDocuments,
  uploadDocument,
  createFolder,
  downloadDocument,
  deleteDocument,
} from '@/api/document'
import {
  listApiDocuments,
  getApiDocument,
  createApiDocument,
  updateApiDocument,
  exportApiDocument
} from '@/api/apiDocument'

// =================================================================
// 项目选择
// =================================================================
const selectedProject = ref('SAP B1')

// =================================================================
// Mock 目录树数据
// =================================================================
const directoryTree = ref([
  {
    id: 'folder-1',
    label: '接口文档',
    type: 'folder',
    children: [
      { id: 'doc-11', name: '订单API', label: '订单API', type: 'api', version: 'v2.1', updater: '张三', updatedAt: '2026-08-02 14:30', size: '128KB' },
      { id: 'doc-12', name: '库存API', label: '库存API', type: 'api', version: 'v1.0', updater: '李四', updatedAt: '2026-07-28 10:15', size: '96KB' },
      { id: 'doc-13', name: '报表API', label: '报表API', type: 'api', version: 'v1.3', updater: '王五', updatedAt: '2026-07-15 09:00', size: '156KB' },
      { id: 'doc-14', name: '用户管理API', label: '用户管理API', type: 'api', version: 'v2.0', updater: '赵六', updatedAt: '2026-08-01 11:20', size: '112KB' }
    ]
  },
  {
    id: 'folder-2',
    label: '设计文档',
    type: 'folder',
    children: [
      { id: 'doc-21', name: '架构设计文档', label: '架构设计文档', type: 'file', version: 'v2.0', updater: '张三', updatedAt: '2026-07-20 16:00', size: '2.3MB' },
      { id: 'doc-22', name: '数据库设计文档', label: '数据库设计文档', type: 'file', version: 'v1.5', updater: '李四', updatedAt: '2026-07-15 10:30', size: '1.8MB' },
      { id: 'doc-23', name: '接口规范文档', label: '接口规范文档', type: 'file', version: 'v3.1', updater: '王五', updatedAt: '2026-06-28 08:45', size: '980KB' }
    ]
  },
  {
    id: 'folder-3',
    label: '会议纪要',
    type: 'folder',
    children: [
      { id: 'doc-31', name: '周例会 2026-07-28', label: '周例会 2026-07-28', type: 'file', version: 'v1.0', updater: '张三', updatedAt: '2026-07-28 17:00', size: '45KB' },
      { id: 'doc-32', name: '需求评审 2026-07-20', label: '需求评审 2026-07-20', type: 'file', version: 'v1.0', updater: '李四', updatedAt: '2026-07-20 15:30', size: '62KB' },
      { id: 'doc-33', name: '技术方案评审 2026-07-10', label: '技术方案评审 2026-07-10', type: 'file', version: 'v1.1', updater: '王五', updatedAt: '2026-07-10 11:00', size: '78KB' }
    ]
  },
  {
    id: 'folder-4',
    label: '技术方案',
    type: 'folder',
    children: []
  },
  {
    id: 'folder-5',
    label: '测试报告',
    type: 'folder',
    children: [
      { id: 'doc-51', name: 'SIT测试报告 v1.0', label: 'SIT测试报告 v1.0', type: 'file', version: 'v1.0', updater: '赵六', updatedAt: '2026-08-01 09:00', size: '1.2MB' },
      { id: 'doc-52', name: 'UAT测试报告 第一期', label: 'UAT测试报告 第一期', type: 'file', version: 'v1.0', updater: '钱七', updatedAt: '2026-07-25 14:20', size: '890KB' }
    ]
  }
])

// =================================================================
// 搜索 & 导航
// =================================================================
const searchKeyword = ref('')
const treeFilterText = ref('')

const currentFolder = ref({
  id: 'folder-1',
  label: '接口文档',
  type: 'folder'
})

const currentFiles = ref([
  { id: 'doc-11', name: '订单API', type: 'api', version: 'v2.1', updater: '张三', updatedAt: '2026-08-02 14:30', size: '128KB' },
  { id: 'doc-12', name: '库存API', type: 'api', version: 'v1.0', updater: '李四', updatedAt: '2026-07-28 10:15', size: '96KB' },
  { id: 'doc-13', name: '报表API', type: 'api', version: 'v1.3', updater: '王五', updatedAt: '2026-07-15 09:00', size: '156KB' },
  { id: 'doc-14', name: '用户管理API', type: 'api', version: 'v2.0', updater: '赵六', updatedAt: '2026-08-01 11:20', size: '112KB' }
])

const treeLoading = ref(false)
const filesLoading = ref(false)

// 面包屑路径
const breadcrumbPath = computed(() => {
  // 简单实现：返回当前文件夹名
  return [{ label: '全部文档' }, { label: currentFolder.value.label }]
})

// =================================================================
// 对话框状态
// =================================================================
const uploadDialogVisible = ref(false)
const apiDocDialogVisible = ref(false)
const recycleBinDialogVisible = ref(false)
const createFolderDialogVisible = ref(false)
const newFolderName = ref('')
const uploadTargetFolder = ref('')

// =================================================================
// 上传对话框
// =================================================================
const uploadFileList = ref([])
const uploadRef = ref(null)

// =================================================================
// 接口文档编辑器
// =================================================================
const apiDocMode = ref('create') // 'create' | 'edit'
const editingDocId = ref(null)
const apiDocForm = reactive({
  name: '',
  path: '',
  method: 'GET',
  authType: 'none',
  notes: '',
  requestParams: [],
  responseParams: []
})

const methodOptions = [
  { label: 'GET', value: 'GET' },
  { label: 'POST', value: 'POST' },
  { label: 'PUT', value: 'PUT' },
  { label: 'DELETE', value: 'DELETE' },
  { label: 'PATCH', value: 'PATCH' }
]

const authTypeOptions = [
  { label: '无认证', value: 'none' },
  { label: 'Bearer Token', value: 'bearer' },
  { label: 'Basic Auth', value: 'basic' },
  { label: 'API Key', value: 'api_key' },
  { label: 'OAuth 2.0', value: 'oauth2' }
]

const paramTypeOptions = [
  { label: 'String', value: 'string' },
  { label: 'Number', value: 'number' },
  { label: 'Boolean', value: 'boolean' },
  { label: 'Array', value: 'array' },
  { label: 'Object', value: 'object' }
]

// =================================================================
// 回收站
// =================================================================
const trashFiles = ref([])
const trashLoading = ref(false)

// =================================================================
// 文件夹过滤（用于树搜索）
// =================================================================
function filterTreeNode(value, data) {
  if (!value) return true
  return data.label.toLowerCase().includes(value.toLowerCase())
}

// 监听搜索文本变化
watch(treeFilterText, (val) => {
  treeRef.value?.filter(val)
})

// =================================================================
// 事件处理
// =================================================================

const treeRef = ref(null)

function handleNodeClick(data) {
  if (data.type === 'folder') {
    currentFolder.value = {
      id: data.id,
      label: data.label,
      type: 'folder'
    }
    // 将子节点映射为文件列表格式
    currentFiles.value = (data.children || []).map(child => ({
      id: child.id,
      name: child.name || child.label,
      type: child.type || 'file',
      version: child.version || 'v1.0',
      updater: child.updater || '-',
      updatedAt: child.updatedAt || '-',
      size: child.size || '-'
    }))
  } else {
    // 点击文件节点，直接查看
    handleViewFile(data)
  }
}

function handleNodeExpand(data, node) {
  // 可在此加载子节点数据
}

function handleNodeCollapse(data, node) {
  // no-op
}

// ---- 工具栏 ----
function handleCreateApiDoc() {
  apiDocMode.value = 'create'
  editingDocId.value = null
  resetApiDocForm()
  apiDocDialogVisible.value = true
}

function handleUploadFile() {
  uploadFileList.value = []
  uploadTargetFolder.value = currentFolder.value.id
  uploadDialogVisible.value = true
}

function handleCreateFolder() {
  createFolderDialogVisible.value = true
  newFolderName.value = ''
}

function confirmCreateFolder() {
  const name = newFolderName.value.trim()
  if (!name) {
    ElMessage.warning('请输入文件夹名称')
    return
  }
  // 检查同名
  if (currentFiles.value.some(f => f.name === name && f.type === 'folder')) {
    ElMessage.warning('文件夹名称已存在')
    return
  }
  ElMessage.success(`文件夹 "${name}" 创建成功`)
  createFolderDialogVisible.value = false
  newFolderName.value = ''
}

// ---- 文件操作 ----
function handleViewFile(file) {
  if (file.type === 'api') {
    // 打开 API 文档详情
    apiDocMode.value = 'edit'
    editingDocId.value = file.id
    // Mock 填充表单数据
    Object.assign(apiDocForm, {
      name: file.name,
      path: '/api/' + (file.name.includes('订单') ? 'orders' : file.name.includes('库存') ? 'inventory' : file.name.includes('报表') ? 'reports' : 'users'),
      method: 'GET',
      authType: 'bearer',
      notes: '',
      requestParams: [
        { name: 'page', type: 'number', required: true, description: '页码' },
        { name: 'pageSize', type: 'number', required: false, description: '每页条数' }
      ],
      responseParams: [
        { name: 'code', type: 'number', description: '响应码' },
        { name: 'data', type: 'array', description: '数据列表' },
        { name: 'message', type: 'string', description: '提示信息' }
      ]
    })
    apiDocDialogVisible.value = true
  } else {
    ElMessage.info(`查看文件：${file.name}`)
  }
}

function handleEditFile(file) {
  if (file.type === 'api') {
    handleViewFile(file)
  } else {
    ElMessage.info(`编辑文件：${file.name}`)
  }
}

function handleDownloadFile(file) {
  ElMessage.success(`开始下载：${file.name}`)
  // 实际调用: downloadDocument(file.id)
}

function handleDeleteFile(file) {
  ElMessageBox.confirm(
    `确定要将 "${file.name}" 移入回收站吗？`,
    '删除确认',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }
  ).then(() => {
    ElMessage.success(`"${file.name}" 已移入回收站`)
    // 从文件列表中移除
    currentFiles.value = currentFiles.value.filter(f => f.id !== file.id)
  }).catch(() => {})
}

// ---- 上传 ----
function handleUploadChange(file, uploadFiles) {
  uploadFileList.value = uploadFiles
}

function handleUploadRemove(file, uploadFiles) {
  uploadFileList.value = uploadFiles
}

function submitUpload() {
  if (uploadFileList.value.length === 0) {
    ElMessage.warning('请选择要上传的文件')
    return
  }
  uploadFileList.value.forEach(file => {
    // 将上传的文件添加到当前文件夹的文件列表中
    const newFile = {
      id: 'doc-new-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
      name: file.name,
      type: 'file',
      version: 'v1.0',
      updater: '当前用户',
      updatedAt: new Date().toLocaleString('zh-CN', { hour12: false }).replace(/\//g, '-'),
      size: formatFileSize(file.size || 0)
    }
    currentFiles.value.push(newFile)
  })
  ElMessage.success(`成功上传 ${uploadFileList.value.length} 个文件`)
  uploadDialogVisible.value = false
}

// ---- 接口文档保存 ----
const apiDocFormRef = ref(null)

function resetApiDocForm() {
  apiDocForm.name = ''
  apiDocForm.path = ''
  apiDocForm.method = 'GET'
  apiDocForm.authType = 'none'
  apiDocForm.notes = ''
  apiDocForm.requestParams = []
  apiDocForm.responseParams = []
}

function addRequestParam() {
  apiDocForm.requestParams.push({
    name: '',
    type: 'string',
    required: false,
    description: ''
  })
}

function removeRequestParam(index) {
  apiDocForm.requestParams.splice(index, 1)
}

function addResponseParam() {
  apiDocForm.responseParams.push({
    name: '',
    type: 'string',
    description: ''
  })
}

function removeResponseParam(index) {
  apiDocForm.responseParams.splice(index, 1)
}

function saveApiDocument() {
  if (!apiDocForm.name.trim()) {
    ElMessage.warning('请输入接口名称')
    return
  }
  if (!apiDocForm.path.trim()) {
    ElMessage.warning('请输入接口路径')
    return
  }

  if (apiDocMode.value === 'create') {
    // 创建新文档
    const newDoc = {
      id: 'doc-api-' + Date.now(),
      name: apiDocForm.name.trim(),
      type: 'api',
      version: 'v1.0',
      updater: '当前用户',
      updatedAt: new Date().toLocaleString('zh-CN', { hour12: false }).replace(/\//g, '-'),
      size: '-'
    }
    currentFiles.value.push(newDoc)
    ElMessage.success('接口文档创建成功')
  } else {
    // 更新已有文档
    const idx = currentFiles.value.findIndex(f => f.id === editingDocId.value)
    if (idx > -1) {
      currentFiles.value[idx].name = apiDocForm.name.trim()
      currentFiles.value[idx].updater = '当前用户'
      currentFiles.value[idx].updatedAt = new Date().toLocaleString('zh-CN', { hour12: false }).replace(/\//g, '-')
      const versionNum = parseFloat(currentFiles.value[idx].version.replace('v', ''))
      currentFiles.value[idx].version = 'v' + (versionNum + 0.1).toFixed(1)
    }
    ElMessage.success('接口文档保存成功')
  }

  apiDocDialogVisible.value = false
  resetApiDocForm()
}

// ---- 回收站 ----
function openRecycleBin() {
  recycleBinDialogVisible.value = true
  trashLoading.value = true
  // 模拟加载回收站数据
  setTimeout(() => {
    trashFiles.value = [
      { id: 'trash-1', name: '旧版本API文档.docx', type: 'file', version: 'v0.9', updater: '张三', updatedAt: '2026-07-01 10:00', size: '45KB', deletedAt: '2026-08-01 09:00' },
      { id: 'trash-2', name: '已废弃接口文档.md', type: 'api', version: 'v1.0', updater: '李四', updatedAt: '2026-06-15 14:00', size: '12KB', deletedAt: '2026-07-28 16:30' }
    ]
    trashLoading.value = false
  }, 500)
}

function handleRestoreTrash(file) {
  ElMessageBox.confirm(
    `确定要恢复 "${file.name}" 吗？`,
    '恢复确认',
    { confirmButtonText: '确定', cancelButtonText: '取消', type: 'info' }
  ).then(() => {
    trashFiles.value = trashFiles.value.filter(f => f.id !== file.id)
    ElMessage.success(`"${file.name}" 已恢复`)
  }).catch(() => {})
}

function handleForceDelete(file) {
  ElMessageBox.confirm(
    `确定要永久删除 "${file.name}" 吗？此操作不可恢复。`,
    '永久删除确认',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'error' }
  ).then(() => {
    trashFiles.value = trashFiles.value.filter(f => f.id !== file.id)
    ElMessage.success(`"${file.name}" 已永久删除`)
  }).catch(() => {})
}

function handleClearTrash() {
  if (trashFiles.value.length === 0) {
    ElMessage.info('回收站已为空')
    return
  }
  ElMessageBox.confirm(
    '确定要清空回收站吗？所有文件将被永久删除且不可恢复。',
    '清空回收站',
    { confirmButtonText: '确定清空', cancelButtonText: '取消', type: 'error' }
  ).then(() => {
    trashFiles.value = []
    ElMessage.success('回收站已清空')
  }).catch(() => {})
}

// ---- 项目切换 ----
function handleProjectChange(projectId) {
  ElMessage.info(`切换到项目：${projectId}`)
  // 模拟加载新项目的目录树和文件
  // 实际场景下调用: loadTreeData(projectId)
}

// =================================================================
// 工具函数
// =================================================================
function formatFileSize(bytes) {
  if (!bytes || bytes === 0) return '-'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let size = bytes
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024
    i++
  }
  return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i]
}

function getMethodTagType(method) {
  const map = {
    GET: '',
    POST: 'success',
    PUT: 'warning',
    DELETE: 'danger',
    PATCH: 'info'
  }
  return map[method] || ''
}

function getFileIcon(file) {
  if (file.type === 'api') return Document
  return Document
}

// 文件类型标签
function getFileTypeLabel(type) {
  return type === 'api' ? 'API' : '文件'
}

function getFileTypeColor(type) {
  return type === 'api' ? '#409EFF' : '#67C23A'
}
</script>

<template>
  <div class="page-container">
    <!-- 页面标题 -->
    <div class="page-header">
      <h2 class="page-title">文档管理</h2>
      <p class="page-description">管理项目文档与接口文档，支持版本控制与回收站</p>
    </div>

    <!-- 项目选择栏 -->
    <div class="filter-bar">
      <span class="filter-label">项目选择：</span>
      <el-select
        v-model="selectedProject"
        style="width: 240px"
        @change="handleProjectChange"
        placeholder="请选择项目"
      >
        <el-option label="SAP B1 企业资源管理系统" value="SAP B1" />
        <el-option label="VPMS 车辆生产管理系统" value="VPMS" />
        <el-option label="Weaver OA 办公自动化系统" value="Weaver OA" />
      </el-select>
    </div>

    <!-- 主内容区：左树 + 右内容 -->
    <div class="doc-layout">
      <!-- ====== 左侧目录树 ====== -->
      <div class="doc-sidebar">
        <!-- 搜索框 -->
        <div class="doc-tree-header">
          <el-input
            v-model="treeFilterText"
            placeholder="搜索文件或文件夹..."
            :prefix-icon="Search"
            size="small"
            clearable
          />
        </div>

        <!-- 目录树 -->
        <div class="doc-tree-body">
          <el-tree
            ref="treeRef"
            :data="directoryTree"
            node-key="id"
            :filter-node-method="filterTreeNode"
            default-expand-all
            highlight-current
            :expand-on-click-node="true"
            @node-click="handleNodeClick"
            @node-expand="handleNodeExpand"
            @node-collapse="handleNodeCollapse"
          >
            <template #default="{ node, data }">
              <span class="tree-node">
                <el-icon v-if="data.type === 'folder'" class="tree-icon folder">
                  <Folder />
                </el-icon>
                <el-icon v-else-if="data.type === 'api'" class="tree-icon api">
                  <Setting />
                </el-icon>
                <el-icon v-else class="tree-icon file">
                  <Document />
                </el-icon>
                <span class="tree-label">{{ node.label }}</span>
                <el-tag
                  v-if="data.type !== 'folder'"
                  :type="data.type === 'api' ? '' : 'success'"
                  size="small"
                  class="tree-tag"
                >
                  {{ getFileTypeLabel(data.type) }}
                </el-tag>
              </span>
            </template>
          </el-tree>
        </div>

        <!-- 底部操作栏 -->
        <div class="doc-tree-footer">
          <div class="tree-footer-actions">
            <el-button text size="small" :icon="Plus" @click="handleCreateFolder">
              新建文件夹
            </el-button>
            <el-button text size="small" :icon="Upload" @click="handleUploadFile">
              上传
            </el-button>
          </div>
          <div class="tree-footer-bottom">
            <el-button
              text
              size="small"
              :icon="Delete"
              type="info"
              class="trash-link"
              @click="openRecycleBin"
            >
              回收站
            </el-button>
          </div>
        </div>
      </div>

      <!-- ====== 右侧内容区 ====== -->
      <div class="doc-content">
        <!-- 内容头部 -->
        <div class="doc-content-header flex-between">
          <div class="header-left">
            <el-breadcrumb separator="/">
              <el-breadcrumb-item :to="{ path: '/' }">
                <el-icon><HomeFilled /></el-icon>
              </el-breadcrumb-item>
              <el-breadcrumb-item
                v-for="item in breadcrumbPath"
                :key="item.label"
              >
                {{ item.label }}
              </el-breadcrumb-item>
            </el-breadcrumb>
            <span class="file-count" v-if="currentFiles.length > 0">
              共 {{ currentFiles.length }} 个文件
            </span>
          </div>
          <div class="header-actions">
            <el-button type="primary" size="small" :icon="Plus" @click="handleCreateApiDoc">
              新建接口文档
            </el-button>
            <el-button size="small" :icon="Upload" @click="handleUploadFile">
              上传文件
            </el-button>
            <el-button size="small" :icon="FolderOpened" @click="handleCreateFolder">
              新建文件夹
            </el-button>
          </div>
        </div>

        <!-- 文件列表 -->
        <div class="doc-file-list" v-if="currentFiles.length > 0">
          <div
            v-for="file in currentFiles"
            :key="file.id"
            class="file-card"
          >
            <div class="file-card-body">
              <div class="file-card-main">
                <!-- 文件图标 -->
                <div class="file-icon-wrap">
                  <el-icon v-if="file.type === 'api'" :size="28" color="#409EFF">
                    <Setting />
                  </el-icon>
                  <el-icon v-else :size="28" color="#67C23A">
                    <Document />
                  </el-icon>
                </div>

                <!-- 文件信息 -->
                <div class="file-info">
                  <div class="file-name-row">
                    <span class="file-name">{{ file.name }}</span>
                    <span
                      class="file-type-badge"
                      :style="{ backgroundColor: getFileTypeColor(file.type) }"
                    >
                      {{ getFileTypeLabel(file.type) }}
                    </span>
                    <span class="file-version">{{ file.version }}</span>
                  </div>
                  <div class="file-meta-row">
                    <span class="file-meta-item" :title="'更新人'">
                      <el-icon :size="12"><User /></el-icon>
                      {{ file.updater }}
                    </span>
                    <span class="file-meta-item" :title="'更新时间'">
                      <el-icon :size="12"><Clock /></el-icon>
                      {{ file.updatedAt }}
                    </span>
                    <span class="file-meta-item" :title="'文件大小'">
                      <el-icon :size="12"><InfoFilled /></el-icon>
                      {{ file.size }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- 操作按钮 -->
              <div class="file-actions">
                <el-tooltip content="查看" placement="top">
                  <el-button text size="small" :icon="View" @click="handleViewFile(file)">
                    查看
                  </el-button>
                </el-tooltip>
                <el-tooltip content="编辑" placement="top">
                  <el-button text size="small" :icon="Edit" type="primary" @click="handleEditFile(file)">
                    编辑
                  </el-button>
                </el-tooltip>
                <el-tooltip content="下载" placement="top">
                  <el-button text size="small" :icon="Download" type="success" @click="handleDownloadFile(file)">
                    下载
                  </el-button>
                </el-tooltip>
                <el-tooltip content="删除" placement="top">
                  <el-button text size="small" :icon="Delete" type="danger" @click="handleDeleteFile(file)">
                    删除
                  </el-button>
                </el-tooltip>
              </div>
            </div>
          </div>
        </div>

        <!-- 空状态 -->
        <div class="doc-empty" v-else>
          <div class="empty-placeholder">
            <el-icon :size="64" class="empty-icon">
              <FolderOpened />
            </el-icon>
            <p class="empty-text">此文件夹为空</p>
            <p class="empty-hint">
              点击上方"新建接口文档"或"上传文件"按钮添加内容
            </p>
            <div class="empty-actions">
              <el-button type="primary" :icon="Plus" @click="handleCreateApiDoc">
                新建接口文档
              </el-button>
              <el-button :icon="Upload" @click="handleUploadFile">
                上传文件
              </el-button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- =============================================================== -->
    <!-- 上传文件对话框 -->
    <!-- =============================================================== -->
    <el-dialog
      v-model="uploadDialogVisible"
      title="上传文件"
      width="520px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form label-width="100px">
        <el-form-item label="目标文件夹">
          <el-select
            v-model="uploadTargetFolder"
            style="width: 100%"
            placeholder="选择目标文件夹"
          >
            <el-option
              v-for="folder in directoryTree.filter(n => n.type === 'folder')"
              :key="folder.id"
              :label="folder.label"
              :value="folder.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="选择文件">
          <el-upload
            ref="uploadRef"
            class="upload-drag-area"
            drag
            multiple
            :auto-upload="false"
            :on-change="handleUploadChange"
            :on-remove="handleUploadRemove"
          >
            <el-icon class="el-icon--upload" :size="48">
              <UploadFilled />
            </el-icon>
            <div class="el-upload__text">
              将文件拖到此处，或 <em>点击上传</em>
            </div>
            <template #tip>
              <div class="el-upload__tip">
                支持 PDF、Word、Excel、Markdown 等格式，单个文件不超过 50MB
              </div>
            </template>
          </el-upload>
        </el-form-item>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="uploadDialogVisible = false">取消</el-button>
          <el-button type="primary" @click="submitUpload" :disabled="uploadFileList.length === 0">
            开始上传（{{ uploadFileList.length }}）
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- =============================================================== -->
    <!-- 接口文档编辑对话框 -->
    <!-- =============================================================== -->
    <el-dialog
      v-model="apiDocDialogVisible"
      :title="apiDocMode === 'create' ? '新建接口文档' : '编辑接口文档'"
      width="800px"
      :close-on-click-modal="false"
      destroy-on-close
      top="5vh"
    >
      <el-form
        ref="apiDocFormRef"
        :model="apiDocForm"
        label-width="90px"
        class="api-doc-form"
      >
        <!-- 基本信息 -->
        <div class="form-section">
          <h4 class="form-section-title">基本信息</h4>
          <el-row :gutter="16">
            <el-col :span="14">
              <el-form-item label="接口名称" required>
                <el-input v-model="apiDocForm.name" placeholder="例如：获取订单列表" />
              </el-form-item>
            </el-col>
            <el-col :span="10">
              <el-form-item label="请求方法" required>
                <el-select v-model="apiDocForm.method" style="width: 100%">
                  <el-option
                    v-for="opt in methodOptions"
                    :key="opt.value"
                    :label="opt.label"
                    :value="opt.value"
                  />
                </el-select>
              </el-form-item>
            </el-col>
          </el-row>

          <el-row :gutter="16">
            <el-col :span="14">
              <el-form-item label="接口路径" required>
                <el-input v-model="apiDocForm.path" placeholder="例如：/api/v1/orders" />
              </el-form-item>
            </el-col>
            <el-col :span="10">
              <el-form-item label="认证方式">
                <el-select v-model="apiDocForm.authType" style="width: 100%">
                  <el-option
                    v-for="opt in authTypeOptions"
                    :key="opt.value"
                    :label="opt.label"
                    :value="opt.value"
                  />
                </el-select>
              </el-form-item>
            </el-col>
          </el-row>
        </div>

        <!-- 请求参数 -->
        <div class="form-section">
          <div class="form-section-header">
            <h4 class="form-section-title">请求参数</h4>
            <el-button size="small" type="primary" text :icon="CirclePlus" @click="addRequestParam">
              添加参数
            </el-button>
          </div>
          <div class="params-table" v-if="apiDocForm.requestParams.length > 0">
            <div class="params-table-header">
              <span class="col-name">参数名</span>
              <span class="col-type">类型</span>
              <span class="col-required">必填</span>
              <span class="col-desc">说明</span>
              <span class="col-action">操作</span>
            </div>
            <div
              v-for="(param, index) in apiDocForm.requestParams"
              :key="'req-' + index"
              class="params-table-row"
            >
              <span class="col-name">
                <el-input v-model="param.name" size="small" placeholder="参数名" />
              </span>
              <span class="col-type">
                <el-select v-model="param.type" size="small" style="width: 100%">
                  <el-option
                    v-for="opt in paramTypeOptions"
                    :key="opt.value"
                    :label="opt.label"
                    :value="opt.value"
                  />
                </el-select>
              </span>
              <span class="col-required">
                <el-checkbox v-model="param.required" />
              </span>
              <span class="col-desc">
                <el-input v-model="param.description" size="small" placeholder="参数说明" />
              </span>
              <span class="col-action">
                <el-button text size="small" type="danger" :icon="Remove" @click="removeRequestParam(index)" />
              </span>
            </div>
          </div>
          <div v-else class="params-empty">
            暂无请求参数，点击"添加参数"按钮新增
          </div>
        </div>

        <!-- 响应参数 -->
        <div class="form-section">
          <div class="form-section-header">
            <h4 class="form-section-title">响应参数</h4>
            <el-button size="small" type="primary" text :icon="CirclePlus" @click="addResponseParam">
              添加参数
            </el-button>
          </div>
          <div class="params-table" v-if="apiDocForm.responseParams.length > 0">
            <div class="params-table-header">
              <span class="col-name">参数名</span>
              <span class="col-type">类型</span>
              <span class="col-desc-wide">说明</span>
              <span class="col-action">操作</span>
            </div>
            <div
              v-for="(param, index) in apiDocForm.responseParams"
              :key="'res-' + index"
              class="params-table-row"
            >
              <span class="col-name">
                <el-input v-model="param.name" size="small" placeholder="参数名" />
              </span>
              <span class="col-type">
                <el-select v-model="param.type" size="small" style="width: 100%">
                  <el-option
                    v-for="opt in paramTypeOptions"
                    :key="opt.value"
                    :label="opt.label"
                    :value="opt.value"
                  />
                </el-select>
              </span>
              <span class="col-desc-wide">
                <el-input v-model="param.description" size="small" placeholder="参数说明" />
              </span>
              <span class="col-action">
                <el-button text size="small" type="danger" :icon="Remove" @click="removeResponseParam(index)" />
              </span>
            </div>
          </div>
          <div v-else class="params-empty">
            暂无响应参数，点击"添加参数"按钮新增
          </div>
        </div>

        <!-- 备注 / JSON 示例 -->
        <div class="form-section">
          <h4 class="form-section-title">备注与示例</h4>
          <el-form-item label="备注" label-width="60px">
            <el-input
              v-model="apiDocForm.notes"
              type="textarea"
              :rows="8"
              placeholder="请输入备注信息，例如 JSON 请求/响应示例：
{
  &quot;code&quot;: 200,
  &quot;message&quot;: &quot;success&quot;,
  &quot;data&quot;: {
    &quot;list&quot;: [],
    &quot;total&quot;: 0
  }
}"
            />
          </el-form-item>
        </div>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="apiDocDialogVisible = false">取消</el-button>
          <el-button type="primary" @click="saveApiDocument">
            {{ apiDocMode === 'create' ? '创建' : '保存' }}
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- =============================================================== -->
    <!-- 新建文件夹对话框 -->
    <!-- =============================================================== -->
    <el-dialog
      v-model="createFolderDialogVisible"
      title="新建文件夹"
      width="420px"
      :close-on-click-modal="false"
    >
      <el-form label-width="80px">
        <el-form-item label="文件夹名称" required>
          <el-input
            v-model="newFolderName"
            placeholder="请输入文件夹名称"
            maxlength="50"
            show-word-limit
            @keyup.enter="confirmCreateFolder"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="createFolderDialogVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmCreateFolder">创建</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- =============================================================== -->
    <!-- 回收站对话框 -->
    <!-- =============================================================== -->
    <el-dialog
      v-model="recycleBinDialogVisible"
      title="回收站"
      width="640px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <!-- 工具栏 -->
      <div class="trash-toolbar" v-if="trashFiles.length > 0">
        <el-button size="small" type="danger" text :icon="Delete" @click="handleClearTrash">
          清空回收站
        </el-button>
      </div>

      <!-- 加载中 -->
      <div v-if="trashLoading" class="trash-loading">
        <el-icon class="is-loading" :size="32"><Loading /></el-icon>
        <p>加载中...</p>
      </div>

      <!-- 回收站文件列表 -->
      <div v-else-if="trashFiles.length > 0" class="trash-list">
        <div
          v-for="file in trashFiles"
          :key="file.id"
          class="trash-file-item"
        >
          <div class="trash-file-info">
            <el-icon :size="20" :color="file.type === 'api' ? '#409EFF' : '#67C23A'">
              <Setting v-if="file.type === 'api'" />
              <Document v-else />
            </el-icon>
            <div class="trash-file-details">
              <span class="trash-file-name">{{ file.name }}</span>
              <span class="trash-file-meta">
                删除于 {{ file.deletedAt }} | {{ file.size }}
              </span>
            </div>
          </div>
          <div class="trash-file-actions">
            <el-button text size="small" type="primary" :icon="RefreshRight" @click="handleRestoreTrash(file)">
              恢复
            </el-button>
            <el-button text size="small" type="danger" :icon="Delete" @click="handleForceDelete(file)">
              彻底删除
            </el-button>
          </div>
        </div>
      </div>

      <!-- 回收站为空 -->
      <div v-else class="empty-placeholder trash-empty">
        <el-icon :size="48" class="empty-icon"><Delete /></el-icon>
        <p class="empty-text">回收站为空</p>
        <p class="empty-hint">被删除的文件将显示在这里</p>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// ================================================================
// 变量
// ================================================================
$sidebar-width: 280px;
$card-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);

// ================================================================
// 筛选栏
// ================================================================
.filter-label {
  font-size: $font-size-body;
  color: $gray-700;
  margin-right: 8px;
  white-space: nowrap;
}

// ================================================================
// 主布局
// ================================================================
.doc-layout {
  display: flex;
  gap: 16px;
  min-height: calc(100vh - 240px);
}

// ================================================================
// 左侧边栏
// ================================================================
.doc-sidebar {
  width: $sidebar-width;
  flex-shrink: 0;
  background: #fff;
  border-radius: $border-radius-md;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.doc-tree-header {
  padding: 12px;
  border-bottom: 1px solid $gray-200;
}

.doc-tree-body {
  flex: 1;
  overflow-y: auto;
  padding: 8px 4px;

  :deep(.el-tree) {
    background: transparent;

    .el-tree-node__content {
      height: 36px;
      border-radius: $border-radius-sm;
      padding-right: 8px;

      &:hover {
        background-color: $gray-100;
      }
    }

    .el-tree-node.is-current > .el-tree-node__content {
      background-color: #ecf5ff;
      color: $color-primary;
    }
  }
}

.tree-node {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: 1;
  min-width: 0;
  font-size: $font-size-body;

  .tree-icon {
    flex-shrink: 0;
    font-size: 16px;

    &.folder {
      color: $color-warning;
    }
    &.api {
      color: $color-primary;
    }
    &.file {
      color: $color-success;
    }
  }

  .tree-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tree-tag {
    flex-shrink: 0;
    font-size: 10px;
    line-height: 16px;
    padding: 0 4px;
  }
}

.doc-tree-footer {
  border-top: 1px solid $gray-200;
  padding: 6px 12px;

  .tree-footer-actions {
    display: flex;
    gap: 4px;
  }

  .tree-footer-bottom {
    border-top: 1px dashed $gray-200;
    margin-top: 6px;
    padding-top: 6px;
  }

  .trash-link {
    width: 100%;
    justify-content: flex-start;
    color: $gray-500;

    &:hover {
      color: $color-danger;
    }
  }
}

// ================================================================
// 右侧内容区
// ================================================================
.doc-content {
  flex: 1;
  background: #fff;
  border-radius: $border-radius-md;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.doc-content-header {
  padding: 12px 20px;
  border-bottom: 1px solid $gray-200;
  flex-shrink: 0;

  .header-left {
    display: flex;
    align-items: center;
    gap: 16px;

    .file-count {
      font-size: $font-size-caption;
      color: $gray-500;
      background: $gray-100;
      padding: 2px 10px;
      border-radius: 10px;
    }
  }

  .header-actions {
    display: flex;
    gap: 8px;
  }
}

// ================================================================
// 文件列表
// ================================================================
.doc-file-list {
  flex: 1;
  overflow-y: auto;
  padding: 16px 20px;
}

.file-card {
  border: 1px solid $gray-200;
  border-radius: 6px;
  margin-bottom: 10px;
  background: #fff;
  transition: border-color 0.2s, box-shadow 0.2s;

  &:hover {
    border-color: $color-primary;
    box-shadow: $card-hover-shadow;
  }

  &:last-child {
    margin-bottom: 0;
  }
}

.file-card-body {
  padding: 14px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.file-card-main {
  display: flex;
  align-items: center;
  gap: 14px;
  flex: 1;
  min-width: 0;
}

.file-icon-wrap {
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: $gray-50;
  border-radius: 8px;
  flex-shrink: 0;
}

.file-info {
  flex: 1;
  min-width: 0;
}

.file-name-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}

.file-name {
  font-size: 15px;
  font-weight: 500;
  color: $gray-900;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-type-badge {
  display: inline-flex;
  align-items: center;
  font-size: 10px;
  color: #fff;
  padding: 0 6px;
  border-radius: 3px;
  line-height: 18px;
  flex-shrink: 0;
}

.file-version {
  font-size: $font-size-caption;
  color: $color-primary;
  background: #ecf5ff;
  padding: 0 6px;
  border-radius: 3px;
  line-height: 18px;
  flex-shrink: 0;
}

.file-meta-row {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

.file-meta-item {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: $font-size-caption;
  color: $gray-500;
  white-space: nowrap;
}

// 文件操作按钮
.file-actions {
  display: flex;
  gap: 2px;
  flex-shrink: 0;

  :deep(.el-button) {
    padding: 5px 8px;
    font-size: $font-size-caption;
  }
}

// ================================================================
// 空状态
// ================================================================
.doc-empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}

.empty-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin-top: 16px;
}

// ================================================================
// 上传对话框
// ================================================================
.upload-drag-area {
  width: 100%;

  :deep(.el-upload-dragger) {
    width: 100%;
    padding: 40px 20px;
  }

  :deep(.el-upload__tip) {
    text-align: center;
    margin-top: 8px;
  }
}

// ================================================================
// 接口文档编辑对话框
// ================================================================
.api-doc-form {
  max-height: 60vh;
  overflow-y: auto;
  padding-right: 4px;
}

.form-section {
  margin-bottom: 20px;
  background: $gray-50;
  border-radius: $border-radius-sm;
  padding: 14px 16px;

  &:last-child {
    margin-bottom: 0;
  }
}

.form-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.form-section-title {
  font-size: $font-size-body;
  font-weight: 600;
  color: $gray-900;
  margin: 0 0 10px 0;

  .form-section-header & {
    margin-bottom: 0;
  }
}

// 参数表格
.params-table {
  border: 1px solid $gray-300;
  border-radius: $border-radius-sm;
  overflow: hidden;
  background: #fff;
}

.params-table-header {
  display: flex;
  align-items: center;
  background: $gray-100;
  padding: 8px 12px;
  font-size: $font-size-caption;
  font-weight: 500;
  color: $gray-700;
  border-bottom: 1px solid $gray-300;
  gap: 8px;
}

.params-table-row {
  display: flex;
  align-items: center;
  padding: 6px 12px;
  gap: 8px;
  border-bottom: 1px solid $gray-200;

  &:last-child {
    border-bottom: none;
  }
}

.col-name {
  width: 140px;
  flex-shrink: 0;
}

.col-type {
  width: 110px;
  flex-shrink: 0;
}

.col-required {
  width: 44px;
  flex-shrink: 0;
  text-align: center;
}

.col-desc {
  flex: 1;
  min-width: 0;
}

.col-desc-wide {
  flex: 1;
  min-width: 0;
}

.col-action {
  width: 36px;
  flex-shrink: 0;
  text-align: center;
}

.params-empty {
  text-align: center;
  padding: 24px 0;
  color: $gray-500;
  font-size: $font-size-small;
}

// ================================================================
// 回收站
// ================================================================
.trash-toolbar {
  margin-bottom: 12px;
  display: flex;
  justify-content: flex-end;
}

.trash-loading {
  text-align: center;
  padding: 40px 0;
  color: $gray-500;

  p {
    margin-top: 8px;
    font-size: $font-size-small;
  }
}

.trash-list {
  max-height: 400px;
  overflow-y: auto;
}

.trash-file-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  border: 1px solid $gray-200;
  border-radius: 6px;
  margin-bottom: 8px;
  transition: border-color 0.2s;

  &:hover {
    border-color: $color-danger;
  }

  &:last-child {
    margin-bottom: 0;
  }
}

.trash-file-info {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  min-width: 0;
}

.trash-file-details {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.trash-file-name {
  font-size: $font-size-body;
  color: $gray-900;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.trash-file-meta {
  font-size: $font-size-caption;
  color: $gray-500;
}

.trash-file-actions {
  display: flex;
  gap: 4px;
  flex-shrink: 0;
  margin-left: 12px;
}

.trash-empty {
  padding: 40px 0;
}

// ================================================================
// Dialog 底部
// ================================================================
.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

// ================================================================
// 响应式
// ================================================================
@media (max-width: 1100px) {
  .doc-layout {
    flex-direction: column;
  }

  .doc-sidebar {
    width: 100%;
    max-height: 300px;
  }

  .file-card-body {
    flex-direction: column;
    align-items: flex-start;
  }

  .file-actions {
    width: 100%;
    justify-content: flex-end;
    padding-top: 4px;
  }

  .params-table-row {
    flex-wrap: wrap;
  }
}
</style>
