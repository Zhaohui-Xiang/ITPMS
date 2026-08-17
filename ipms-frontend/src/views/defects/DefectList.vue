<script setup>
import { ref, reactive, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search, UploadFilled, Delete } from '@element-plus/icons-vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useAuthStore } from '@/stores/auth'
import {
  listDefects,
  createDefect,
  confirmDefect,
  assignDefect,
  resolveDefect,
  verifyDefect,
  reopenDefect
} from '@/api/defect'

// ==================== Auth ====================
const authStore = useAuthStore()
const currentRole = computed(() => authStore.currentRole)

// ==================== State ====================
const loading = ref(false)
const searchKeyword = ref('')
const activeStatus = ref('')
const filterSeverity = ref('')
const filterDefectType = ref('')
const filterProject = ref('')
const currentPage = ref(1)
const pageSize = ref(10)

// ==================== Status tabs ====================
const statusTabs = [
  { key: '', label: '全部' },
  { key: 'pending', label: '待确认' },
  { key: 'confirmed', label: '已确认' },
  { key: 'fixing', label: '修复中' },
  { key: 'retesting', label: '待复测' },
  { key: 'closed', label: '已关闭' },
  { key: 'reopened', label: '重新打开' }
]

// ==================== Mock data ====================
const defects = ref([
  {
    id: 1,
    title: '登录后页面空白，无法加载任何内容',
    description: '用户使用Chrome浏览器登录系统后，Dashboard页面持续白屏，控制台报错 TypeError: Cannot read properties of undefined',
    requirement: { id: 1, title: '用户登录功能' },
    project: 'SAP B1',
    severity: '致命',
    defect_type: '功能缺陷',
    status: 'pending',
    finder: '张测试',
    found_date: '2026-08-02',
    assignee: null,
    found_stage: '测试中'
  },
  {
    id: 2,
    title: '财务报表导出为Excel时格式错乱',
    description: '导出利润表Excel后，数字列宽不够显示####，日期格式变成数字串',
    requirement: { id: 2, title: '财务报表功能' },
    project: 'SAP B1',
    severity: '严重',
    defect_type: '功能缺陷',
    status: 'pending',
    finder: '刘业务',
    found_date: '2026-08-01',
    assignee: null,
    found_stage: '测试中'
  },
  {
    id: 3,
    title: '订单列表页面加载时间超过10秒',
    description: '当订单数据超过5000条时，列表页加载极慢，用户体验差',
    requirement: { id: 3, title: '订单管理功能' },
    project: 'VPMS',
    severity: '一般',
    defect_type: '性能缺陷',
    status: 'confirmed',
    finder: '王测试',
    found_date: '2026-08-01',
    assignee: '李开发',
    found_stage: '测试中'
  },
  {
    id: 4,
    title: '按钮颜色与设计稿不一致',
    description: '提交按钮在hover状态下颜色为蓝色，设计稿要求为深蓝色 #3A8EE6',
    requirement: { id: 4, title: 'UI组件库规范' },
    project: 'Tools',
    severity: '轻微',
    defect_type: 'UI缺陷',
    status: 'fixing',
    finder: '李测试',
    found_date: '2026-07-28',
    assignee: '赵前端',
    found_stage: '测试中'
  },
  {
    id: 5,
    title: '库存盘点数量计算错误导致库存对不上',
    description: '盘点单中理论库存与实际库存存在差异，系统未自动生成盘点差异调整单',
    requirement: { id: 5, title: '库存盘点功能' },
    project: 'SAP B1',
    severity: '严重',
    defect_type: '功能缺陷',
    status: 'confirmed',
    finder: '王仓库',
    found_date: '2026-07-25',
    assignee: null,
    found_stage: '已上线后'
  },
  {
    id: 6,
    title: '搜索框在中文输入法下无法正常输入',
    description: '使用搜狗输入法时，搜索框中输入中文候选词无法上屏，回车后搜索框内容清空',
    requirement: { id: 6, title: '全局搜索功能' },
    project: 'Weaver OA',
    severity: '一般',
    defect_type: '兼容性',
    status: 'fixing',
    finder: '赵测试',
    found_date: '2026-07-20',
    assignee: '孙开发',
    found_stage: '测试中'
  },
  {
    id: 7,
    title: '附件上传后预览显示空白',
    description: '上传PDF附件后点击预览，预览窗口一直显示loading状态，实际文件已上传成功',
    requirement: { id: 7, title: '附件管理功能' },
    project: 'Salesforce',
    severity: '轻微',
    defect_type: '功能缺陷',
    status: 'retesting',
    finder: '刘市场',
    found_date: '2026-07-15',
    assignee: '钱开发',
    found_stage: '已上线后'
  },
  {
    id: 8,
    title: '权限控制失效可越权访问其他部门数据',
    description: '普通用户通过修改URL中的部门ID参数，可以查看其他部门的数据，存在数据安全隐患',
    requirement: { id: 8, title: '数据权限控制' },
    project: 'SAP B1',
    severity: '致命',
    defect_type: '功能缺陷',
    status: 'fixing',
    finder: '张安全',
    found_date: '2026-07-10',
    assignee: '周开发',
    found_stage: '已上线后'
  },
  {
    id: 9,
    title: '移动端H5页面在iOS Safari上布局错位',
    description: 'iOS 17系统下，Safari浏览器底部导航栏与系统手势条重叠，导致点击失效',
    requirement: { id: 9, title: '移动端适配' },
    project: 'VPMS',
    severity: '严重',
    defect_type: '兼容性',
    status: 'retesting',
    finder: '吴测试',
    found_date: '2026-07-08',
    assignee: '郑前端',
    found_stage: '测试中'
  },
  {
    id: 10,
    title: '文件名为空时上传无任何提示',
    description: '用户选择文件名为空的文件上传时，前端无校验，后端直接报500错误',
    requirement: { id: 7, title: '附件管理功能' },
    project: 'Salesforce',
    severity: '一般',
    defect_type: '功能缺陷',
    status: 'closed',
    finder: '陈测试',
    found_date: '2026-07-05',
    assignee: '赵前端',
    found_stage: '测试中'
  },
  {
    id: 11,
    title: '长时间未操作后系统不提示Session过期',
    description: '用户登录后30分钟无操作，Session过期后再次点击任何按钮页面无反应，无重新登录提示',
    requirement: { id: 1, title: '用户登录功能' },
    project: 'Weaver OA',
    severity: '一般',
    defect_type: '功能缺陷',
    status: 'closed',
    finder: '刘业务',
    found_date: '2026-07-01',
    assignee: '李开发',
    found_stage: '已上线后'
  },
  {
    id: 12,
    title: '数据迁移后部分历史订单状态显示异常',
    description: '老系统数据迁移到新系统后，部分已完成订单状态显示为"未知"，影响报表统计',
    requirement: { id: 3, title: '订单管理功能' },
    project: 'VPMS',
    severity: '严重',
    defect_type: '其他',
    status: 'reopened',
    finder: '王仓库',
    found_date: '2026-06-28',
    assignee: '孙开发',
    found_stage: '已上线后'
  }
])

// ==================== Computed ====================
const filteredDefects = computed(() => {
  let list = defects.value
  if (activeStatus.value) {
    list = list.filter(d => d.status === activeStatus.value)
  }
  if (searchKeyword.value) {
    const kw = searchKeyword.value.toLowerCase()
    list = list.filter(d => d.title.toLowerCase().includes(kw) || d.description.toLowerCase().includes(kw))
  }
  if (filterSeverity.value) {
    list = list.filter(d => d.severity === filterSeverity.value)
  }
  if (filterDefectType.value) {
    list = list.filter(d => d.defect_type === filterDefectType.value)
  }
  if (filterProject.value) {
    list = list.filter(d => d.project === filterProject.value)
  }
  return list
})

const statusCounts = computed(() => {
  const counts = {}
  statusTabs.forEach(tab => {
    if (tab.key === '') {
      counts[tab.key] = defects.value.length
    } else {
      counts[tab.key] = defects.value.filter(d => d.status === tab.key).length
    }
  })
  return counts
})

const pagedDefects = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredDefects.value.slice(start, start + pageSize.value)
})

const totalFiltered = computed(() => filteredDefects.value.length)

const projectOptions = computed(() => {
  const projects = [...new Set(defects.value.map(d => d.project))]
  return projects.sort()
})

// ==================== Severity helpers ====================
function getSeverityTagStyle(severity) {
  const colors = {
    '致命': '#F56C6C',
    '严重': '#FF6D00',
    '一般': '#F9AB00',
    '轻微': '#67C23A'
  }
  const color = colors[severity] || '#909399'
  return {
    backgroundColor: color,
    borderColor: color,
    color: '#ffffff'
  }
}

// ==================== Table row class ====================
function tableRowClassName({ row }) {
  if (row.severity === '致命' || row.severity === '严重') {
    return 'severity-highlight-row'
  }
  return ''
}

// ==================== Role helper ====================
function canPerformAction(actionKey) {
  const role = currentRole.value
  // super_admin and admin can do everything
  if (role === 'super_admin' || role === 'admin') return true
  // Role-specific permissions
  const roleActionMap = {
    confirm: ['tester', 'qa'],
    reject: ['tester', 'qa'],
    assign: ['pm', 'manager'],
    resolve: ['developer'],
    verify_pass: ['tester', 'qa'],
    verify_fail: ['tester', 'qa'],
    reopen: ['tester', 'qa', 'pm', 'manager'],
    reassign: ['pm', 'manager']
  }
  const allowed = roleActionMap[actionKey] || []
  return allowed.includes(role)
}

// ==================== Row actions ====================
const defectActions = {
  pending: [
    { key: 'confirm', label: '确认缺陷', type: 'primary' },
    { key: 'reject', label: '拒绝', type: 'danger' }
  ],
  confirmed: [
    { key: 'assign', label: '指派修复', type: 'primary' }
  ],
  fixing: [
    { key: 'resolve', label: '提交复测', type: 'success' }
  ],
  retesting: [
    { key: 'verify_pass', label: '复测通过', type: 'success' },
    { key: 'verify_fail', label: '复测不通过', type: 'danger' }
  ],
  closed: [
    { key: 'reopen', label: '重新打开', type: 'warning' }
  ],
  reopened: [
    { key: 'reassign', label: '重新指派', type: 'primary' }
  ]
}

function getRowActions(status) {
  return defectActions[status] || []
}

const actionLabels = {
  confirm: '确认缺陷',
  reject: '拒绝缺陷',
  assign: '指派修复',
  resolve: '提交复测',
  verify_pass: '复测通过',
  verify_fail: '复测不通过',
  reopen: '重新打开',
  reassign: '重新指派'
}

async function handleRowAction(row, action) {
  try {
    const { value: comment } = await ElMessageBox.prompt(
      `请输入${action.label}备注（可选）`,
      `${action.label} - ${row.title}`,
      {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        inputType: 'textarea',
        inputPlaceholder: '请输入备注信息...',
        inputRows: 3
      }
    )
    await executeAction(row, action.key, comment || '')
  } catch {
    // User cancelled the prompt
  }
}

async function executeAction(row, actionKey, comment) {
  loading.value = true
  try {
    // In production, uncomment the API calls below
    switch (actionKey) {
      case 'confirm':
        // await confirmDefect(row.id, { comment })
        break
      case 'reject':
        // await updateDefect(row.id, { status: 'closed', reject_reason: comment })
        break
      case 'assign':
        // await assignDefect(row.id, { assignee_id: null, comment })
        break
      case 'resolve':
        // await resolveDefect(row.id, { fix_description: comment })
        break
      case 'verify_pass':
        // await verifyDefect(row.id, { action: 'pass', comment })
        break
      case 'verify_fail':
        // await verifyDefect(row.id, { action: 'fail', comment })
        break
      case 'reopen':
        // await reopenDefect(row.id, { reason: comment })
        break
      case 'reassign':
        // await assignDefect(row.id, { assignee_id: null, comment })
        break
    }

    // Mock status transitions
    const transitions = {
      confirm: 'confirmed',
      reject: 'closed',
      assign: 'fixing',
      resolve: 'retesting',
      verify_pass: 'closed',
      verify_fail: 'fixing',
      reopen: 'reopened',
      reassign: 'fixing'
    }
    const newStatus = transitions[actionKey]
    if (newStatus) {
      const defect = defects.value.find(d => d.id === row.id)
      if (defect) defect.status = newStatus
    }

    ElMessage.success(`${actionLabels[actionKey]}操作成功`)
    currentPage.value = 1
  } catch (error) {
    ElMessage.error(error?.message || '操作失败，请重试')
  } finally {
    loading.value = false
  }
}

// ==================== Create dialog ====================
const createDialogVisible = ref(false)
const createFormRef = ref(null)
const submitting = ref(false)
const createForm = reactive({
  title: '',
  description: '',
  requirement_id: '',
  severity: '',
  defect_type: '',
  found_stage: '',
  images: []
})

const createFormRules = {
  title: [{ required: true, message: '请输入缺陷标题', trigger: 'blur' }],
  description: [{ required: true, message: '请输入缺陷描述', trigger: 'blur' }],
  severity: [{ required: true, message: '请选择严重程度', trigger: 'change' }],
  defect_type: [{ required: true, message: '请选择缺陷类型', trigger: 'change' }],
  found_stage: [{ required: true, message: '请选择发现阶段', trigger: 'change' }]
}

const requirementOptions = [
  { id: 1, title: '用户登录功能' },
  { id: 2, title: '财务报表功能' },
  { id: 3, title: '订单管理功能' },
  { id: 4, title: 'UI组件库规范' },
  { id: 5, title: '库存盘点功能' },
  { id: 6, title: '全局搜索功能' },
  { id: 7, title: '附件管理功能' },
  { id: 8, title: '数据权限控制' },
  { id: 9, title: '移动端适配' }
]

const severityOptions = ['致命', '严重', '一般', '轻微']
const defectTypeOptions = ['功能缺陷', '性能缺陷', 'UI缺陷', '兼容性', '其他']
const foundStageOptions = ['测试中', '已上线后']

const uploadFileList = ref([])

function handleCreateClick() {
  resetCreateForm()
  createDialogVisible.value = true
}

function resetCreateForm() {
  createForm.title = ''
  createForm.description = ''
  createForm.requirement_id = ''
  createForm.severity = ''
  createForm.defect_type = ''
  createForm.found_stage = ''
  createForm.images = []
  uploadFileList.value = []
  if (createFormRef.value) {
    createFormRef.value.resetFields()
  }
}

function handleUploadChange(file, fileList) {
  uploadFileList.value = fileList
}

function handleRemoveUpload(file) {
  const index = uploadFileList.value.indexOf(file)
  if (index > -1) {
    uploadFileList.value.splice(index, 1)
  }
}

async function handleSubmitCreate() {
  if (!createFormRef.value) return
  try {
    await createFormRef.value.validate()
  } catch {
    return
  }
  submitting.value = true
  try {
    // In production: await createDefect(createForm)
    const selectedReq = requirementOptions.find(r => r.id === createForm.requirement_id)
    const newId = Math.max(...defects.value.map(d => d.id), 0) + 1
    defects.value.unshift({
      id: newId,
      title: createForm.title,
      description: createForm.description,
      requirement: selectedReq ? { id: selectedReq.id, title: selectedReq.title } : null,
      project: selectedReq ? 'SAP B1' : '',
      severity: createForm.severity,
      defect_type: createForm.defect_type,
      status: 'pending',
      finder: authStore.userName || '当前用户',
      found_date: new Date().toISOString().slice(0, 10),
      assignee: null,
      found_stage: createForm.found_stage,
      images: uploadFileList.value.map(f => f.name)
    })
    ElMessage.success('缺陷提交成功')
    createDialogVisible.value = false
    currentPage.value = 1
  } catch (error) {
    ElMessage.error(error?.message || '提交失败，请重试')
  } finally {
    submitting.value = false
  }
}

function handleDialogClose() {
  resetCreateForm()
}

// ==================== View detail ====================
function handleView(row) {
  ElMessage.info(`查看缺陷详情：${row.title}`)
}
</script>

<template>
  <div class="page-container">
    <!-- Page header -->
    <div class="page-header flex-between">
      <div>
        <h2 class="page-title">缺陷管理</h2>
        <p class="page-description">管理项目缺陷，跟踪修复进度</p>
      </div>
      <el-button type="primary" :icon="Plus" @click="handleCreateClick">提交缺陷</el-button>
    </div>

    <!-- Status filter tabs -->
    <div class="filter-bar">
      <el-radio-group v-model="activeStatus" size="small" @change="currentPage = 1">
        <el-radio-button v-for="tab in statusTabs" :key="tab.key" :value="tab.key">
          {{ tab.label }}
          <span v-if="statusCounts[tab.key] > 0" class="tab-count"> {{ statusCounts[tab.key] }}</span>
        </el-radio-button>
      </el-radio-group>
    </div>

    <!-- Search and filter bar -->
    <div class="filter-bar">
      <el-input
        v-model="searchKeyword"
        placeholder="搜索缺陷标题或描述..."
        :prefix-icon="Search"
        clearable
        style="width: 260px"
        @change="currentPage = 1"
      />
      <el-select
        v-model="filterSeverity"
        placeholder="全部严重程度"
        clearable
        style="width: 150px"
        @change="currentPage = 1"
      >
        <el-option
          v-for="s in severityOptions"
          :key="s"
          :label="s"
          :value="s"
        />
      </el-select>
      <el-select
        v-model="filterDefectType"
        placeholder="全部缺陷类型"
        clearable
        style="width: 150px"
        @change="currentPage = 1"
      >
        <el-option
          v-for="t in defectTypeOptions"
          :key="t"
          :label="t"
          :value="t"
        />
      </el-select>
      <el-select
        v-model="filterProject"
        placeholder="全部项目"
        clearable
        style="width: 160px"
        @change="currentPage = 1"
      >
        <el-option
          v-for="p in projectOptions"
          :key="p"
          :label="p"
          :value="p"
        />
      </el-select>
    </div>

    <!-- Defect table -->
    <div class="content-card">
      <el-table
        :data="pagedDefects"
        v-loading="loading"
        style="width: 100%"
        :row-class-name="tableRowClassName"
        empty-text="暂无缺陷数据"
      >
        <el-table-column prop="title" label="缺陷标题" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            <el-button text type="primary" size="small" @click="handleView(row)">
              {{ row.title }}
            </el-button>
          </template>
        </el-table-column>

        <el-table-column label="严重程度" width="90" align="center">
          <template #default="{ row }">
            <el-tag :style="getSeverityTagStyle(row.severity)" size="small" effect="dark">
              {{ row.severity }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="关联需求" width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <router-link
              v-if="row.requirement"
              :to="`/requirements/${row.requirement.id}`"
              class="requirement-link"
            >
              {{ row.requirement.title }}
            </router-link>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <StatusTag type="defect" :status="row.status" />
          </template>
        </el-table-column>

        <el-table-column label="项目" width="110" prop="project" />

        <el-table-column label="发现人" width="90" prop="finder" />

        <el-table-column label="发现日期" width="110" prop="found_date" />

        <el-table-column label="发现阶段" width="100">
          <template #default="{ row }">
            <el-tag
              :type="row.found_stage === '测试中' ? '' : 'warning'"
              size="small"
              effect="plain"
            >
              {{ row.found_stage }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <div class="table-actions">
              <el-button text type="primary" size="small" @click="handleView(row)">
                查看
              </el-button>
              <template v-for="action in getRowActions(row.status)" :key="action.key">
                <el-button
                  v-if="canPerformAction(action.key)"
                  text
                  :type="action.type"
                  size="small"
                  @click="handleRowAction(row, action)"
                >
                  {{ action.label }}
                </el-button>
              </template>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- Pagination -->
      <div class="table-footer">
        <span class="text-muted">共 {{ totalFiltered }} 条缺陷</span>
        <el-pagination
          v-if="totalFiltered > pageSize"
          v-model:current-page="currentPage"
          :page-size="pageSize"
          :total="totalFiltered"
          layout="prev, pager, next"
          background
          small
        />
      </div>
    </div>

    <!-- Create defect dialog -->
    <el-dialog
      v-model="createDialogVisible"
      title="提交缺陷"
      width="640px"
      :close-on-click-modal="false"
      @closed="handleDialogClose"
      destroy-on-close
    >
      <el-form
        ref="createFormRef"
        :model="createForm"
        :rules="createFormRules"
        label-width="90px"
        label-position="right"
      >
        <el-form-item label="缺陷标题" prop="title">
          <el-input
            v-model="createForm.title"
            placeholder="请输入缺陷标题，简明扼要描述问题"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>

        <el-form-item label="缺陷描述" prop="description">
          <el-input
            v-model="createForm.description"
            type="textarea"
            :rows="4"
            placeholder="请详细描述缺陷，包括：复现步骤、预期结果、实际结果、影响范围等"
            maxlength="2000"
            show-word-limit
          />
        </el-form-item>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="关联需求" prop="requirement_id">
              <el-select
                v-model="createForm.requirement_id"
                placeholder="请选择关联需求（可选）"
                clearable
                filterable
                style="width: 100%"
              >
                <el-option
                  v-for="req in requirementOptions"
                  :key="req.id"
                  :label="req.title"
                  :value="req.id"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="严重程度" prop="severity">
              <el-select
                v-model="createForm.severity"
                placeholder="请选择严重程度"
                style="width: 100%"
              >
                <el-option
                  v-for="s in severityOptions"
                  :key="s"
                  :label="s"
                  :value="s"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="缺陷类型" prop="defect_type">
              <el-select
                v-model="createForm.defect_type"
                placeholder="请选择缺陷类型"
                style="width: 100%"
              >
                <el-option
                  v-for="t in defectTypeOptions"
                  :key="t"
                  :label="t"
                  :value="t"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="发现阶段" prop="found_stage">
              <el-select
                v-model="createForm.found_stage"
                placeholder="请选择发现阶段"
                style="width: 100%"
              >
                <el-option
                  v-for="s in foundStageOptions"
                  :key="s"
                  :label="s"
                  :value="s"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="截图附件">
          <el-upload
            v-model:file-list="uploadFileList"
            list-type="picture-card"
            :auto-upload="false"
            :limit="5"
            accept="image/png,image/jpeg,image/gif,image/webp"
            @change="handleUploadChange"
          >
            <el-icon><Plus /></el-icon>
          </el-upload>
          <div class="upload-hint">支持 PNG、JPEG、GIF、WebP 格式，最多上传 5 张，每张不超过 5MB</div>
        </el-form-item>
      </el-form>

      <template #footer>
        <div class="dialog-footer">
          <el-button @click="createDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitting" @click="handleSubmitCreate">
            提交
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// ==================== Status tabs ====================
.tab-count {
  font-weight: 600;
}

// ==================== Severity highlight rows ====================
:deep(.severity-highlight-row) {
  background-color: #fef0f0 !important;

  &:hover > td {
    background-color: #fde2e2 !important;
  }
}

// ==================== Requirement link ====================
.requirement-link {
  color: $color-primary;
  text-decoration: none;
  font-size: $font-size-small;

  &:hover {
    text-decoration: underline;
    color: $color-primary-light;
  }
}

// ==================== Table footer / pagination ====================
.table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;

  .text-muted {
    color: $gray-500;
    font-size: $font-size-small;
  }
}

// ==================== Create dialog ====================
.upload-hint {
  font-size: $font-size-caption;
  color: $gray-500;
  margin-top: 6px;
  line-height: 1.4;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

// ==================== Text muted ====================
.text-muted {
  color: $gray-500;
}
</style>
