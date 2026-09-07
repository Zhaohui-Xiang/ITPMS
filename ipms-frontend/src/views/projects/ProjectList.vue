<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Plus,
  Search,
  RefreshLeft,
  Grid,
  List,
  MoreFilled,
  Edit,
  View,
  Delete,
  FolderChecked,
  Document,
  Warning,
  ArrowDown
} from '@element-plus/icons-vue'
import { usePermission } from '@/composables/usePermission'
// import StatusTag from '@/components/common/StatusTag.vue'
import UserSelector from '@/components/common/UserSelector.vue'
import { archiveProject } from '@/api/project'

const { isSuperAdmin, canCreate, canEdit, canDelete, canPerform } = usePermission()

// ===== 页面状态 =====
const loading = ref(false)
const currentView = ref('card') // 'card' | 'table'

// ===== 搜索和筛选 =====
const searchKeyword = ref('')
const filterStatus = ref('')
const filterType = ref('')
const currentPage = ref(1)
const pageSize = ref(9)

// ===== 对话框状态 =====
const dialogVisible = ref(false)
const dialogTitle = ref('新建项目')
const dialogMode = ref('create') // 'create' | 'edit'
const formLoading = ref(false)
const editingProjectId = ref(null)

// ===== 表单数据 =====
const formData = reactive({
  name: '',
  system_type: '',
  description: '',
  manager_id: null,
  supplier_id: null,
  supplier: ''
})

// ===== 表单验证规则 =====
const formRules = reactive({
  name: [
    { required: true, message: '请输入项目名称', trigger: 'blur' },
    { min: 2, max: 50, message: '项目名称长度在 2 到 50 个字符', trigger: 'blur' }
  ],
  system_type: [
    { required: true, message: '请选择系统类型', trigger: 'change' }
  ],
  description: [
    { max: 500, message: '项目描述不能超过 500 个字符', trigger: 'blur' }
  ],
  manager_id: [
    { required: true, message: '请选择项目负责人', trigger: 'change' }
  ]
})

const formRef = ref(null)

// ===== Mock 数据 =====
const mockProjects = [
  {
    id: 1,
    name: 'SAP B1 企业资源管理系统',
    system_type: '外部采购',
    status: '活跃',
    description: 'SAP Business One 企业资源计划系统，涵盖财务、采购、库存、销售、生产等核心业务模块',
    requirements_count: 12,
    defects_count: 3,
    manager: { id: 1, name: '张三' },
    supplier: 'SAP 中国',
    created_at: '2025-03-15',
    updated_at: '2026-08-02'
  },
  {
    id: 2,
    name: 'Salesforce CRM 客户管理平台',
    system_type: '外部采购',
    status: '活跃',
    description: 'Salesforce 客户关系管理系统，用于销售线索管理、客户服务、市场营销自动化',
    requirements_count: 8,
    defects_count: 1,
    manager: { id: 3, name: '王五' },
    supplier: '普华永道',
    created_at: '2025-06-20',
    updated_at: '2026-08-01'
  },
  {
    id: 3,
    name: 'VPMS 供应商管理系统',
    system_type: '内部自研',
    status: '维护中',
    description: '供应商全生命周期管理平台，涵盖准入、评估、绩效考核、合同管理等功能',
    requirements_count: 5,
    defects_count: 0,
    manager: { id: 2, name: '李四' },
    supplier: null,
    created_at: '2024-11-01',
    updated_at: '2026-07-28'
  },
  {
    id: 4,
    name: 'Weaver OA 协同办公平台',
    system_type: '外部采购',
    status: '活跃',
    description: '泛微 OA 办公自动化系统，包含流程审批、公文管理、会议管理、知识库等功能',
    requirements_count: 6,
    defects_count: 2,
    manager: { id: 4, name: '赵六' },
    supplier: '泛微网络',
    created_at: '2025-01-10',
    updated_at: '2026-07-25'
  },
  {
    id: 5,
    name: '内部开发工具集',
    system_type: '内部自研',
    status: '归档',
    description: '开发工具和基础设施集合，包含代码仓库管理、CI/CD 流水线、监控告警等',
    requirements_count: 3,
    defects_count: 0,
    manager: { id: 4, name: '赵六' },
    supplier: null,
    created_at: '2024-05-20',
    updated_at: '2026-06-15'
  },
  {
    id: 6,
    name: 'CAD/PLM 设计管理平台',
    system_type: '内部自研',
    status: '活跃',
    description: '计算机辅助设计与产品生命周期管理一体化平台，支持 3D 建模与协同设计',
    requirements_count: 6,
    defects_count: 1,
    manager: { id: 5, name: '孙七' },
    supplier: null,
    created_at: '2025-09-01',
    updated_at: '2026-08-02'
  },
  {
    id: 7,
    name: '物流门户与追踪系统',
    system_type: '内部自研',
    status: '维护中',
    description: '物流运输全程可视化追踪平台，对接多家物流服务商，提供实时位置与状态查询',
    requirements_count: 4,
    defects_count: 0,
    manager: { id: 1, name: '张三' },
    supplier: null,
    created_at: '2025-04-12',
    updated_at: '2026-07-20'
  },
  {
    id: 8,
    name: '人力资源管理系统 HRM',
    system_type: '外部采购',
    status: '维护中',
    description: '人事管理、薪酬福利、绩效考核、招聘管理一体化 HR 平台',
    requirements_count: 9,
    defects_count: 2,
    manager: { id: 3, name: '王五' },
    supplier: '北森云计算',
    created_at: '2025-02-18',
    updated_at: '2026-07-15'
  },
  {
    id: 9,
    name: '数据分析 BI 平台',
    system_type: '外部采购',
    status: '活跃',
    description: '企业级商业智能与数据分析平台，支持多维度报表、仪表盘、数据挖掘',
    requirements_count: 15,
    defects_count: 4,
    manager: { id: 2, name: '李四' },
    supplier: '帆软软件',
    created_at: '2025-08-05',
    updated_at: '2026-07-30'
  },
  {
    id: 10,
    name: '知识管理 Wiki 平台',
    system_type: '内部自研',
    status: '归档',
    description: '企业内部知识沉淀与共享平台，支持 Markdown 编辑、权限管控、全文检索',
    requirements_count: 2,
    defects_count: 0,
    manager: { id: 6, name: '周八' },
    supplier: null,
    created_at: '2024-08-10',
    updated_at: '2026-05-20'
  }
]

// ===== 供应商选项 =====
const supplierOptions = ref([
  'SAP 中国',
  '普华永道',
  '泛微网络',
  '北森云计算',
  '帆软软件',
  '金蝶国际',
  '用友网络',
  '浪潮信息'
])

// ===== 计算属性 =====
const allProjects = ref([...mockProjects])

// 筛选后的项目列表
const filteredProjects = computed(() => {
  let result = [...allProjects.value]

  // 搜索关键词
  if (searchKeyword.value.trim()) {
    const keyword = searchKeyword.value.trim().toLowerCase()
    result = result.filter(
      (p) =>
        p.name.toLowerCase().includes(keyword) ||
        (p.description && p.description.toLowerCase().includes(keyword))
    )
  }

  // 状态筛选
  if (filterStatus.value) {
    result = result.filter((p) => p.status === filterStatus.value)
  }

  // 类型筛选
  if (filterType.value) {
    result = result.filter((p) => p.system_type === filterType.value)
  }

  return result
})

// 分页后的项目列表
const pagedProjects = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  const end = start + pageSize.value
  return filteredProjects.value.slice(start, end)
})

// 总条数
const totalCount = computed(() => filteredProjects.value.length)

// 状态颜色映射
const statusColorMap = {
  '活跃': 'success',
  '维护中': 'warning',
  '归档': 'info'
}

const statusDotColorMap = {
  '活跃': '#67C23A',
  '维护中': '#E6A23C',
  '归档': '#909399'
}

const systemTypeTagMap = {
  '外部采购': '',
  '内部自研': 'success'
}

// ===== 初始化加载 =====
onMounted(() => {
  fetchProjects()
})

// ===== API 调用 (当前使用 Mock) =====
async function fetchProjects() {
  loading.value = true
  try {
    // const res = await listProjects({
    //   page: currentPage.value,
    //   pageSize: pageSize.value,
    //   keyword: searchKeyword.value,
    //   status: filterStatus.value,
    //   type: filterType.value
    // })
    // allProjects.value = res.data.data
    // totalCount.value = res.data.total

    // 模拟延迟
    await new Promise((resolve) => setTimeout(resolve, 300))
    // 使用 mock 数据
    allProjects.value = [...mockProjects]
  } catch (error) {
    ElMessage.error('获取项目列表失败')
    console.error('fetchProjects error:', error)
  } finally {
    loading.value = false
  }
}

// ===== 搜索 =====
function handleSearch() {
  currentPage.value = 1
  fetchProjects()
}

function handleReset() {
  searchKeyword.value = ''
  filterStatus.value = ''
  filterType.value = ''
  currentPage.value = 1
  fetchProjects()
}

// ===== 监听筛选变化 =====
watch([filterStatus, filterType], () => {
  currentPage.value = 1
})

// ===== 分页 =====
function handlePageChange(page) {
  currentPage.value = page
}

function handleSizeChange(size) {
  pageSize.value = size
  currentPage.value = 1
}

// ===== 视图切换 =====
function switchView(view) {
  currentView.value = view
}

function canEditProject(project) {
  return canPerform(project, 'edit', canEdit('project'))
}

function canArchiveProject(project) {
  return canPerform(project, 'archive', isSuperAdmin.value)
}

function canDeleteProject(project) {
  return canPerform(project, 'delete', canDelete('project'))
}

// ===== 打开创建对话框 =====
function openCreateDialog() {
  dialogTitle.value = '新建项目'
  dialogMode.value = 'create'
  editingProjectId.value = null
  resetForm()
  dialogVisible.value = true
}

// ===== 打开编辑对话框 =====
function openEditDialog(project) {
  dialogTitle.value = '编辑项目'
  dialogMode.value = 'edit'
  editingProjectId.value = project.id
  formData.name = project.name
  formData.system_type = project.system_type
  formData.description = project.description || ''
  formData.manager_id = project.manager ? project.manager.id : null
  formData.supplier = project.supplier || ''
  dialogVisible.value = true
}

// ===== 重置表单 =====
function resetForm() {
  formData.name = ''
  formData.system_type = ''
  formData.description = ''
  formData.manager_id = null
  formData.supplier = ''
  if (formRef.value) {
    formRef.value.resetFields()
  }
}

// ===== 提交表单 =====
async function handleFormSubmit() {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch {
    return
  }

  formLoading.value = true
  try {
    if (dialogMode.value === 'create') {
      // const res = await createProject({
      //   name: formData.name,
      //   system_type: formData.system_type,
      //   description: formData.description,
      //   manager_id: formData.manager_id,
      //   supplier: formData.supplier
      // })

      // Mock: 添加到本地列表
      const newProject = {
        id: allProjects.value.length + 1,
        name: formData.name,
        system_type: formData.system_type,
        status: '活跃',
        description: formData.description,
        requirements_count: 0,
        defects_count: 0,
        manager: { id: formData.manager_id, name: '新负责人' },
        supplier: formData.supplier || null,
        created_at: new Date().toISOString().split('T')[0],
        updated_at: new Date().toISOString().split('T')[0]
      }
      allProjects.value.unshift(newProject)
      ElMessage.success('项目创建成功')
    } else {
      // const res = await updateProject(editingProjectId.value, {
      //   name: formData.name,
      //   system_type: formData.system_type,
      //   description: formData.description,
      //   manager_id: formData.manager_id,
      //   supplier: formData.supplier
      // })

      // Mock: 更新本地数据
      const index = allProjects.value.findIndex((p) => p.id === editingProjectId.value)
      if (index !== -1) {
        allProjects.value[index] = {
          ...allProjects.value[index],
          name: formData.name,
          system_type: formData.system_type,
          description: formData.description,
          manager: {
            ...allProjects.value[index].manager,
            id: formData.manager_id
          },
          supplier: formData.supplier || null,
          updated_at: new Date().toISOString().split('T')[0]
        }
      }
      ElMessage.success('项目更新成功')
    }

    dialogVisible.value = false
    resetForm()
  } catch (error) {
    ElMessage.error(dialogMode.value === 'create' ? '项目创建失败' : '项目更新失败')
    console.error('handleFormSubmit error:', error)
  } finally {
    formLoading.value = false
  }
}

// ===== 删除项目 =====
async function handleDelete(project) {
  try {
    // 检查是否有关联需求
    // const checkRes = await checkProjectDeletable(project.id)
    // const isDeletable = checkRes.data.deletable
    // const requirementsCount = checkRes.data.requirements_count

    // Mock: 检查需求关联
    const requirementsCount = project.requirements_count

    if (requirementsCount > 0) {
      await ElMessageBox.confirm(
        `该项目已关联 ${requirementsCount} 条需求，无法删除`,
        '无法删除',
        {
          confirmButtonText: '知道了',
          cancelButtonText: '取消',
          type: 'warning',
          showCancelButton: false
        }
      )
      return
    }

    await ElMessageBox.confirm(
      `确定要删除项目「${project.name}」吗？此操作不可恢复。`,
      '确认删除',
      {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning'
      }
    )

    // await deleteProject(project.id)

    // Mock: 从本地移除
    const index = allProjects.value.findIndex((p) => p.id === project.id)
    if (index !== -1) {
      allProjects.value.splice(index, 1)
    }
    ElMessage.success('项目已删除')
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      ElMessage.error('删除项目失败')
      console.error('handleDelete error:', error)
    }
  }
}

// ===== 归档项目 =====
async function handleArchive(project) {
  try {
    await ElMessageBox.confirm(
      `确定要归档项目「${project.name}」吗？`,
      '确认归档',
      {
        confirmButtonText: '确定归档',
        cancelButtonText: '取消',
        type: 'warning'
      }
    )

    await archiveProject(project.id)
    await fetchProjects()
    ElMessage.success('项目已归档')
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      ElMessage.error('归档项目失败')
      console.error('handleArchive error:', error)
    }
  }
}

// ===== 查看项目详情 =====
function handleView(id) {
  // 使用 router 跳转到详情页
  // router.push(`/projects/${id}`)
  ElMessage.info(`查看项目详情 ${id}（路由跳转将在后续版本中实现）`)
}

// ===== 格式化日期 =====
function formatDate(dateStr) {
  if (!dateStr) return '-'
  const date = new Date(dateStr)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

// ===== 获取状态 CSS 类 =====
function getStatusClass(status) {
  return `status-dot-${statusColorMap[status] || 'info'}`
}

// ===== 获取状态点颜色 =====
function getStatusDotColor(status) {
  return statusDotColorMap[status] || '#909399'
}
</script>

<template>
  <div class="page-container">
    <!-- 页面标题 -->
    <div class="page-header flex-between">
      <div>
        <h2 class="page-title">项目管理</h2>
        <p class="page-description">管理系统中的所有信息化项目，跟踪需求与缺陷</p>
      </div>
      <div class="page-header-actions">
        <el-button-group class="view-switcher">
          <el-button
            :type="currentView === 'card' ? 'primary' : 'default'"
            :icon="Grid"
            @click="switchView('card')"
          >
            卡片
          </el-button>
          <el-button
            :type="currentView === 'table' ? 'primary' : 'default'"
            :icon="List"
            @click="switchView('table')"
          >
            列表
          </el-button>
        </el-button-group>
        <el-button
          v-if="isSuperAdmin"
          type="primary"
          :icon="Plus"
          @click="openCreateDialog"
        >
          新建项目
        </el-button>
      </div>
    </div>

    <!-- 搜索和筛选栏 -->
    <div class="filter-bar">
      <div class="filter-bar-left">
        <el-input
          v-model="searchKeyword"
          placeholder="搜索项目名称或描述..."
          :prefix-icon="Search"
          clearable
          class="search-input"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <el-button type="primary" :icon="Search" @click="handleSearch">
          搜索
        </el-button>
        <el-select
          v-model="filterStatus"
          placeholder="全部状态"
          clearable
          class="filter-select"
        >
          <el-option label="活跃" value="活跃" />
          <el-option label="维护中" value="维护中" />
          <el-option label="归档" value="归档" />
        </el-select>
        <el-select
          v-model="filterType"
          placeholder="全部类型"
          clearable
          class="filter-select"
        >
          <el-option label="外部采购" value="外部采购" />
          <el-option label="内部自研" value="内部自研" />
        </el-select>
        <el-button :icon="RefreshLeft" @click="handleReset">重置</el-button>
      </div>
      <div class="filter-bar-right">
        <span class="total-count">共 {{ totalCount }} 个项目</span>
      </div>
    </div>

    <!-- 加载状态 -->
    <div v-if="loading" class="loading-container">
      <el-skeleton :rows="5" animated />
    </div>

    <!-- 空状态 -->
    <div v-else-if="filteredProjects.length === 0" class="empty-container">
      <el-empty description="暂无项目数据">
        <el-button v-if="isSuperAdmin" type="primary" @click="openCreateDialog">
          立即创建
        </el-button>
      </el-empty>
    </div>

    <!-- 卡片视图 -->
    <div v-else-if="currentView === 'card'" class="project-cards">
      <div class="card-grid">
        <el-card
          v-for="project in pagedProjects"
          :key="project.id"
          shadow="hover"
          class="project-card"
          :class="{ 'archived-card': project.status === '归档' }"
          @click="handleView(project.id)"
        >
          <div class="project-card-content">
            <!-- 顶部：名称 + 操作菜单 -->
            <div class="card-header">
              <span class="project-name">{{ project.name }}</span>
              <el-dropdown trigger="click" @click.stop>
                <el-button
                  class="card-more-btn"
                  :icon="MoreFilled"
                  size="small"
                  text
                  @click.stop
                />
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item
                      v-if="canEditProject(project)"
                      :icon="Edit"
                      @click.stop="openEditDialog(project)"
                    >
                      编辑
                    </el-dropdown-item>
                    <el-dropdown-item
                      :icon="View"
                      @click.stop="handleView(project.id)"
                    >
                      查看详情
                    </el-dropdown-item>
                    <el-dropdown-item
                      v-if="canArchiveProject(project)"
                      :icon="FolderChecked"
                      @click.stop="handleArchive(project)"
                    >
                      归档
                    </el-dropdown-item>
                    <el-dropdown-item
                      v-if="canDeleteProject(project)"
                      :icon="Delete"
                      divided
                      class="danger-item"
                      @click.stop="handleDelete(project)"
                    >
                      删除
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </div>

            <!-- 类型标签 + 状态 -->
            <div class="card-tags">
              <el-tag
                :type="systemTypeTagMap[project.system_type]"
                size="small"
                effect="plain"
              >
                {{ project.system_type }}
              </el-tag>
              <div class="status-indicator">
                <span
                  class="status-dot"
                  :style="{ backgroundColor: getStatusDotColor(project.status) }"
                ></span>
                <el-tag :type="statusColorMap[project.status]" size="small">
                  {{ project.status }}
                </el-tag>
              </div>
            </div>

            <!-- 描述 -->
            <div class="card-description">
              {{ project.description || '暂无描述' }}
            </div>

            <!-- 统计信息 -->
            <div class="card-stats">
              <div class="stat-item">
                <el-icon class="stat-icon req-icon"><Document /></el-icon>
                <span class="stat-value">{{ project.requirements_count }}</span>
                <span class="stat-label">需求</span>
              </div>
              <div class="stat-item">
                <el-icon class="stat-icon defect-icon"><Warning /></el-icon>
                <span class="stat-value">{{ project.defects_count }}</span>
                <span class="stat-label">缺陷</span>
              </div>
            </div>

            <!-- 底部：负责人 + 时间 -->
            <div class="card-footer">
              <div class="manager-info">
                <el-avatar :size="24" class="manager-avatar">
                  {{ project.manager ? project.manager.name.charAt(0) : '?' }}
                </el-avatar>
                <span class="manager-name">{{ project.manager ? project.manager.name : '未分配' }}</span>
                <span v-if="project.supplier" class="supplier-name">
                  {{ project.supplier }}
                </span>
              </div>
              <span class="card-date">{{ formatDate(project.updated_at) }}</span>
            </div>
          </div>
        </el-card>
      </div>
    </div>

    <!-- 表格视图 -->
    <div v-else class="project-table">
      <el-table
        :data="pagedProjects"
        v-loading="loading"
        stripe
        border
        style="width: 100%"
        row-class-name="table-row"
        :header-cell-class-name="() => 'table-header'"
      >
        <el-table-column type="index" label="#" width="55" align="center" />
        <el-table-column prop="name" label="项目名称" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span
              class="table-project-name"
              :class="{ 'archived-text': row.status === '归档' }"
              @click="handleView(row.id)"
            >
              {{ row.name }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="system_type" label="系统类型" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="systemTypeTagMap[row.system_type]" size="small" effect="plain">
              {{ row.system_type }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <div class="table-status-cell">
              <span
                class="table-status-dot"
                :style="{ backgroundColor: getStatusDotColor(row.status) }"
              ></span>
              <el-tag :type="statusColorMap[row.status]" size="small">
                {{ row.status }}
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="description" label="描述" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="table-description">{{ row.description || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="需求数" width="80" align="center">
          <template #default="{ row }">
            <span class="stat-num">{{ row.requirements_count }}</span>
          </template>
        </el-table-column>
        <el-table-column label="缺陷数" width="80" align="center">
          <template #default="{ row }">
            <span class="stat-num">{{ row.defects_count }}</span>
          </template>
        </el-table-column>
        <el-table-column label="负责人" width="100" align="center">
          <template #default="{ row }">
            <div class="table-manager">
              <el-avatar :size="24" class="table-avatar">
                {{ row.manager ? row.manager.name.charAt(0) : '?' }}
              </el-avatar>
              <span>{{ row.manager ? row.manager.name : '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="supplier" label="供应商" width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <span>{{ row.supplier || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="更新时间" width="120" align="center">
          <template #default="{ row }">
            <span class="table-date">{{ formatDate(row.updated_at) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" align="center" fixed="right">
          <template #default="{ row }">
            <div class="table-actions" @click.stop>
              <el-button
                v-if="canEditProject(row)"
                link
                type="primary"
                size="small"
                @click="openEditDialog(row)"
              >
                编辑
              </el-button>
              <el-dropdown trigger="click">
                <el-button link type="primary" size="small">
                  更多
                  <el-icon class="el-icon--right"><ArrowDown /></el-icon>
                </el-button>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item :icon="View" @click="handleView(row.id)">
                      查看详情
                    </el-dropdown-item>
                    <el-dropdown-item
                      v-if="canArchiveProject(row)"
                      :icon="FolderChecked"
                      @click="handleArchive(row)"
                    >
                      归档
                    </el-dropdown-item>
                    <el-dropdown-item
                      v-if="canDeleteProject(row)"
                      :icon="Delete"
                      divided
                      class="danger-item"
                      @click="handleDelete(row)"
                    >
                      删除
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 分页 -->
    <div v-if="filteredProjects.length > 0" class="pagination-wrapper">
      <el-pagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        :page-sizes="[9, 18, 36]"
        :layout="'total, sizes, prev, pager, next, jumper'"
        :total="totalCount"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </div>

    <!-- 创建/编辑项目对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="600px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="formData"
        :rules="formRules"
        label-width="100px"
        label-position="right"
      >
        <el-form-item label="项目名称" prop="name">
          <el-input
            v-model="formData.name"
            placeholder="请输入项目名称"
            maxlength="50"
            show-word-limit
            clearable
          />
        </el-form-item>
        <el-form-item label="系统类型" prop="system_type">
          <el-radio-group v-model="formData.system_type">
            <el-radio value="外部采购">外部采购</el-radio>
            <el-radio value="内部自研">内部自研</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="项目描述" prop="description">
          <el-input
            v-model="formData.description"
            type="textarea"
            placeholder="请输入项目描述"
            :rows="4"
            maxlength="500"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="项目负责人" prop="manager_id">
          <UserSelector
            v-model="formData.manager_id"
            placeholder="请选择项目负责人"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="供应商" prop="supplier">
          <el-select
            v-model="formData.supplier"
            placeholder="请选择供应商（可选）"
            clearable
            filterable
            allow-create
            style="width: 100%"
          >
            <el-option
              v-for="item in supplierOptions"
              :key="item"
              :label="item"
              :value="item"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="formLoading" @click="handleFormSubmit">
            {{ dialogMode === 'create' ? '创建' : '保存' }}
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// ============================================================
// 页面头部
// ============================================================
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 20px;

  .page-title {
    font-size: $font-size-h1;
    font-weight: 600;
    color: $gray-900;
    margin: 0 0 4px 0;
  }

  .page-description {
    font-size: $font-size-small;
    color: $gray-500;
    margin: 0;
  }

  .page-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;

    .view-switcher {
      margin-right: 4px;
    }
  }
}

// ============================================================
// 过滤栏
// ============================================================
.filter-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 16px 20px;
  background: #fff;
  border-radius: $border-radius-md;
  border: 1px solid $gray-200;
  margin-bottom: 20px;

  .filter-bar-left {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;

    .search-input {
      width: 260px;
    }

    .filter-select {
      width: 140px;
    }
  }

  .filter-bar-right {
    .total-count {
      font-size: $font-size-small;
      color: $gray-500;
      white-space: nowrap;
    }
  }
}

// ============================================================
// 加载 / 空状态
// ============================================================
.loading-container {
  padding: 40px 0;
}

.empty-container {
  padding: 80px 0;
  display: flex;
  justify-content: center;
}

// ============================================================
// 卡片视图
// ============================================================
.project-cards {
  .card-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }

  .project-card {
    cursor: pointer;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    border: 1px solid $gray-200;
    border-radius: $border-radius-md;

    &:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    }

    &:deep(.el-card__body) {
      padding: 18px 20px;
    }

    &.archived-card {
      opacity: 0.65;

      &:hover {
        opacity: 0.85;
      }
    }
  }
}

.project-card-content {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 0;

  // 顶部：名称 + 操作
  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;

    .project-name {
      font-size: 15px;
      font-weight: 600;
      color: $gray-900;
      line-height: 1.4;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      flex: 1;
      min-width: 0;
    }

    .card-more-btn {
      flex-shrink: 0;
      opacity: 0.6;
      transition: opacity 0.2s;

      &:hover {
        opacity: 1;
      }
    }
  }

  // 标签 + 状态
  .card-tags {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;

    .status-indicator {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }
  }

  // 描述
  .card-description {
    font-size: $font-size-small;
    color: $gray-700;
    line-height: 1.6;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 36px;
  }

  // 统计
  .card-stats {
    display: flex;
    gap: 20px;

    .stat-item {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: $font-size-small;
      color: $gray-700;

      .stat-icon {
        font-size: 16px;
        &.req-icon {
          color: $color-primary;
        }
        &.defect-icon {
          color: $color-danger;
        }
      }

      .stat-value {
        font-weight: 600;
        color: $gray-900;
        font-size: $font-size-body;
      }

      .stat-label {
        color: $gray-500;
      }
    }
  }

  // 底部
  .card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 10px;
    border-top: 1px solid $gray-200;

    .manager-info {
      display: flex;
      align-items: center;
      gap: 6px;
      min-width: 0;
      flex: 1;

      .manager-avatar {
        flex-shrink: 0;
        font-size: $font-size-caption;
      }

      .manager-name {
        font-size: $font-size-small;
        color: $gray-700;
        white-space: nowrap;
      }

      .supplier-name {
        font-size: $font-size-caption;
        color: $gray-500;
        padding: 0 6px;
        background: $gray-100;
        border-radius: $border-radius-sm;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100px;
      }
    }

    .card-date {
      font-size: $font-size-caption;
      color: $gray-500;
      white-space: nowrap;
      flex-shrink: 0;
    }
  }
}

// ============================================================
// 表格视图
// ============================================================
.project-table {
  background: #fff;
  border-radius: $border-radius-md;
  border: 1px solid $gray-200;
  overflow: hidden;

  :deep(.table-header) {
    background: $gray-50;
    color: $gray-700;
    font-weight: 600;
    font-size: $font-size-small;
  }

  :deep(.el-table__row) {
    &.archived-row {
      opacity: 0.6;
    }
  }

  .table-project-name {
    color: $color-primary;
    cursor: pointer;
    font-weight: 500;

    &:hover {
      color: $color-primary-dark;
      text-decoration: underline;
    }

    &.archived-text {
      color: $gray-500;
    }
  }

  .table-description {
    color: $gray-700;
    font-size: $font-size-small;
  }

  .table-status-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }

  .table-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .stat-num {
    font-weight: 600;
    color: $gray-900;
  }

  .table-manager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: $font-size-small;

    .table-avatar {
      flex-shrink: 0;
      font-size: $font-size-caption;
    }
  }

  .table-date {
    color: $gray-500;
    font-size: $font-size-small;
  }

  .table-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
    white-space: nowrap;
  }
}

// ============================================================
// 分页
// ============================================================
.pagination-wrapper {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
  padding: 0 4px;
}

// ============================================================
// 对话框
// ============================================================
.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

// ============================================================
// 下拉菜单危险项
// ============================================================
:deep(.danger-item) {
  color: $color-danger !important;

  &:hover {
    background-color: #fef0f0 !important;
    color: $color-danger !important;
  }
}

// ============================================================
// 响应式
// ============================================================
@media (max-width: 1400px) {
  .project-cards .card-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 1200px) {
  .project-cards .card-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .filter-bar {
    .filter-bar-left {
      .search-input {
        width: 200px;
      }
    }
  }

  .project-table {
    overflow-x: auto;
  }
}

@media (max-width: 768px) {
  .project-cards .card-grid {
    grid-template-columns: 1fr;
  }

  .filter-bar {
    flex-direction: column;

    .filter-bar-left {
      flex-direction: column;
      width: 100%;

      .search-input,
      .filter-select {
        width: 100%;
      }
    }

    .filter-bar-right {
      width: 100%;
    }
  }

  .page-header {
    flex-direction: column;
    gap: 12px;

    .page-header-actions {
      width: 100%;
      justify-content: flex-start;
    }
  }

  .pagination-wrapper {
    justify-content: center;
  }
}
</style>
