<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Plus, Search } from '@element-plus/icons-vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { listRequirements, createRequirement } from '@/api/requirement'

const router = useRouter()

// ============================================================
// 状态筛选 Tabs
// ============================================================
const activeStatus = ref('')
const statusTabs = [
  { key: '', label: '全部' },
  { key: 'pending_review', label: '待审核' },
  { key: 'assigned', label: '已分配' },
  { key: 'developing', label: '开发中' },
  { key: 'testing', label: '测试中' },
  { key: 'pending_online', label: '待上线' },
  { key: 'online', label: '已上线' },
  { key: 'accepted', label: '已验收' }
]

// ============================================================
// Mock Data（至少 10 条，覆盖各状态和类型）
// ============================================================
const requirements = ref([
  {
    id: 1, title: '新增财务报表功能', description: '需在SAP B1中新增三大报表生成与导出功能，支持PDF和Excel格式输出',
    projects: ['SAP B1'], priority: '高', status: 'developing', type: '新功能',
    submitter: '王业务', assignee: '张开发', submit_date: '2026-08-02', expected_date: '2026-09-15'
  },
  {
    id: 2, title: '采购订单审批流优化', description: '优化Weaver OA中采购订单的审批流程，支持多级会签与条件分支',
    projects: ['Weaver OA'], priority: '中', status: 'pending_review', type: '功能优化',
    submitter: '李财务', assignee: '', submit_date: '2026-08-01', expected_date: '2026-08-30'
  },
  {
    id: 3, title: 'VPMS与SAP订单同步接口', description: '建立VPMS到SAP B1的订单同步接口，实现双向数据实时同步',
    projects: ['VPMS', 'SAP B1'], priority: '紧急', status: 'assigned', type: '系统对接',
    submitter: '张销售', assignee: '刘架构', submit_date: '2026-07-30', expected_date: '2026-09-01'
  },
  {
    id: 4, title: 'CRM客户标签管理功能', description: '在Salesforce中新增客户标签管理模块，支持自定义标签与批量打标',
    projects: ['Salesforce'], priority: '低', status: 'online', type: '新功能',
    submitter: '刘市场', assignee: '陈开发', submit_date: '2026-07-28', expected_date: '2026-08-20'
  },
  {
    id: 5, title: '库存盘点功能开发', description: 'SAP B1库存盘点模块开发，支持移动端扫码盘点与差异分析',
    projects: ['SAP B1'], priority: '高', status: 'testing', type: '新功能',
    submitter: '王仓库', assignee: '赵开发', submit_date: '2026-07-25', expected_date: '2026-08-18'
  },
  {
    id: 6, title: '登录页面SSO集成', description: '内部工具平台统一接入SSO单点登录，支持AD域账号认证',
    projects: ['Tools'], priority: '中', status: 'developing', type: '功能优化',
    submitter: '赵安全', assignee: '周开发', submit_date: '2026-07-22', expected_date: '2026-08-25'
  },
  {
    id: 7, title: '报表导出Excel功能优化', description: '优化SAP B1报表导出，支持大数据量分批导出与自定义模板',
    projects: ['SAP B1'], priority: '低', status: 'accepted', type: '功能优化',
    submitter: '钱报表', assignee: '孙开发', submit_date: '2026-07-15', expected_date: '2026-07-30'
  },
  {
    id: 8, title: 'WMS出库单接口对接', description: '对接WMS系统出库单接口，实现出库数据自动回传至SAP生成凭证',
    projects: ['WMS', 'SAP B1'], priority: '高', status: 'pending_online', type: '系统对接',
    submitter: '郑物流', assignee: '吴开发', submit_date: '2026-07-20', expected_date: '2026-08-10'
  },
  {
    id: 9, title: 'TMS运输计划模块', description: '开发TMS运输计划管理模块，支持路线规划与运力调度',
    projects: ['TMS'], priority: '中', status: 'testing', type: '新功能',
    submitter: '冯调度', assignee: '黄开发', submit_date: '2026-07-18', expected_date: '2026-08-28'
  },
  {
    id: 10, title: 'CRM客户流失预警修复', description: '修复CRM客户流失预警模型在周末时段数据缺失问题',
    projects: ['CRM'], priority: '紧急', status: 'pending_review', type: '缺陷修复',
    submitter: '褚运营', assignee: '', submit_date: '2026-08-03', expected_date: '2026-08-06'
  },
  {
    id: 11, title: 'VPMS合同模板管理', description: 'VPMS新增合同模板管理页面，支持模板上传、预览与版本管理',
    projects: ['VPMS'], priority: '低', status: 'assigned', type: '其他',
    submitter: '卫法务', assignee: '韩开发', submit_date: '2026-07-12', expected_date: '2026-09-10'
  },
  {
    id: 12, title: 'OA表单字段联动规则', description: 'Weaver OA表单设计器新增字段联动规则配置，支持显示/隐藏/必填联动',
    projects: ['Weaver OA'], priority: '高', status: 'developing', type: '新功能',
    submitter: '沈行政', assignee: '杨开发', submit_date: '2026-07-26', expected_date: '2026-08-22'
  },
  {
    id: 13, title: 'SAP发票校验流程改造', description: 'SAP B1采购发票校验流程改造，支持三单匹配与差异自动预警',
    projects: ['SAP B1'], priority: '高', status: 'online', type: '功能优化',
    submitter: '李财务', assignee: '朱开发', submit_date: '2026-06-20', expected_date: '2026-07-20'
  },
  {
    id: 14, title: '移动端审批通知推送', description: '内部系统审批通知对接企业微信，实现移动端实时推送与快捷审批',
    projects: ['Weaver OA', 'Tools'], priority: '中', status: 'accepted', type: '功能优化',
    submitter: '秦主管', assignee: '许开发', submit_date: '2026-06-10', expected_date: '2026-07-05'
  },
  {
    id: 15, title: 'Salesforce数据清洗工具', description: 'Salesforce重复客户数据自动识别与合并工具开发',
    projects: ['Salesforce'], priority: '低', status: 'accepted', type: '新功能',
    submitter: '尤数据', assignee: '何开发', submit_date: '2026-05-28', expected_date: '2026-06-30'
  }
])

// ============================================================
// 筛选条件
// ============================================================
const searchKeyword = ref('')
const filterProject = ref('')
const filterPriority = ref('')

// 优先级 tag 类型映射
const priorityTagType = {
  '紧急': 'danger',
  '高': 'warning',
  '中': 'info',
  '低': ''
}

// 需求类型选项
const requirementTypes = ['新功能', '功能优化', '缺陷修复', '系统对接', '其他']

// 从 mock 数据提取项目列表
const projectOptions = computed(() => {
  const set = new Set()
  requirements.value.forEach(r => r.projects.forEach(p => set.add(p)))
  return Array.from(set).sort()
})

// ============================================================
// 分页
// ============================================================
const currentPage = ref(1)
const pageSize = ref(10)

// 筛选条件变更时重置到第一页
watch([activeStatus, filterProject, filterPriority, searchKeyword], () => {
  currentPage.value = 1
})

// ============================================================
// 计算属性：筛选 + 分页
// ============================================================
const filteredRequirements = computed(() => {
  let data = requirements.value

  if (activeStatus.value) {
    data = data.filter(r => r.status === activeStatus.value)
  }
  if (filterProject.value) {
    data = data.filter(r => r.projects.includes(filterProject.value))
  }
  if (filterPriority.value) {
    data = data.filter(r => r.priority === filterPriority.value)
  }
  if (searchKeyword.value) {
    const kw = searchKeyword.value.toLowerCase()
    data = data.filter(r => r.title.toLowerCase().includes(kw))
  }

  return data
})

const paginatedRequirements = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredRequirements.value.slice(start, start + pageSize.value)
})

const total = computed(() => filteredRequirements.value.length)

// 各状态计数
const statusCounts = computed(() => {
  const counts = {}
  statusTabs.forEach(tab => {
    if (tab.key === '') return
    counts[tab.key] = requirements.value.filter(r => r.status === tab.key).length
  })
  return counts
})

// ============================================================
// 权限判断
// ============================================================
function canEdit(row) {
  // 已上线、已验收状态不可编辑
  return !['online', 'accepted'].includes(row.status)
}

// ============================================================
// 操作回调
// ============================================================
function handleView(row) {
  router.push(`/requirements/${row.id}`)
}

function handleEdit(row) {
  router.push(`/requirements/${row.id}?edit=1`)
}

function handlePageChange(page) {
  currentPage.value = page
}

function handlePageSizeChange(size) {
  pageSize.value = size
  currentPage.value = 1
}

// ============================================================
// 新建需求 Dialog
// ============================================================
const dialogVisible = ref(false)
const formRef = ref(null)
const submitting = ref(false)

const form = reactive({
  title: '',
  description: '',
  project_ids: [],
  priority: '',
  req_type: '',
  expected_date: ''
})

const formRules = {
  title: [
    { required: true, message: '请输入需求标题', trigger: 'blur' },
    { min: 2, max: 100, message: '标题长度在 2 到 100 个字符', trigger: 'blur' }
  ],
  project_ids: [
    { required: true, message: '请选择至少一个关联系统', trigger: 'change' }
  ],
  priority: [
    { required: true, message: '请选择优先级', trigger: 'change' }
  ],
  req_type: [
    { required: true, message: '请选择需求类型', trigger: 'change' }
  ]
}

function openCreateDialog() {
  resetForm()
  dialogVisible.value = true
}

function resetForm() {
  form.title = ''
  form.description = ''
  form.project_ids = []
  form.priority = ''
  form.req_type = ''
  form.expected_date = ''
  if (formRef.value) {
    formRef.value.clearValidate()
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
    submitting.value = true

    // 生产环境调用 API
    // await createRequirement({
    //   title: form.title,
    //   description: form.description,
    //   project_ids: form.project_ids,
    //   priority: form.priority,
    //   req_type: form.req_type,
    //   expected_date: form.expected_date
    // })

    // Mock：追加至本地数据
    const newId = Math.max(...requirements.value.map(r => r.id), 0) + 1
    requirements.value.unshift({
      id: newId,
      title: form.title,
      description: form.description,
      projects: form.project_ids,
      priority: form.priority,
      status: 'pending_review',
      type: form.req_type,
      submitter: '当前用户',
      assignee: '',
      submit_date: new Date().toISOString().split('T')[0],
      expected_date: form.expected_date
    })

    ElMessage.success('需求提交成功，已进入待审核状态')
    dialogVisible.value = false
  } catch (err) {
    if (err) {
      ElMessage.warning('请完善必填信息')
    }
  } finally {
    submitting.value = false
  }
}

// ============================================================
// 加载数据（接入真实 API 时启用）
// ============================================================
// async function fetchRequirements() {
//   loading.value = true
//   try {
//     const { data } = await listRequirements({
//       page: currentPage.value,
//       pageSize: pageSize.value,
//       keyword: searchKeyword.value || undefined,
//       status: activeStatus.value || undefined,
//       project_id: filterProject.value || undefined,
//       priority: filterPriority.value || undefined
//     })
//     requirements.value = data.list
//     total.value = data.total
//   } catch (err) {
//     ElMessage.error('加载需求列表失败')
//   } finally {
//     loading.value = false
//   }
// }
//
// watch([currentPage, pageSize, activeStatus, filterProject, filterPriority, searchKeyword], () => {
//   fetchRequirements()
// }, { immediate: true })
</script>

<template>
  <div class="page-container">
    <!-- 页面头部 -->
    <div class="page-header flex-between">
      <div>
        <h2 class="page-title">需求管理</h2>
        <p class="page-description">管理所有项目需求，跟踪需求全生命周期</p>
      </div>
      <el-button type="primary" :icon="Plus" @click="openCreateDialog">
        提交需求
      </el-button>
    </div>

    <!-- 状态筛选 Tabs -->
    <div class="filter-bar">
      <div class="status-tabs">
        <el-radio-group v-model="activeStatus" size="small">
          <el-radio-button
            v-for="tab in statusTabs"
            :key="tab.key"
            :value="tab.key"
          >
            {{ tab.label }}
            <template v-if="tab.key">
              <span class="tab-count">{{ statusCounts[tab.key] || 0 }}</span>
            </template>
          </el-radio-button>
        </el-radio-group>
      </div>
    </div>

    <!-- 搜索和筛选 -->
    <div class="filter-bar">
      <el-input
        v-model="searchKeyword"
        placeholder="搜索需求标题..."
        :prefix-icon="Search"
        clearable
        style="width: 260px"
      />
      <el-select
        v-model="filterProject"
        placeholder="全部项目"
        clearable
        style="width: 160px"
      >
        <el-option
          v-for="p in projectOptions"
          :key="p"
          :label="p"
          :value="p"
        />
      </el-select>
      <el-select
        v-model="filterPriority"
        placeholder="全部优先级"
        clearable
        style="width: 140px"
      >
        <el-option label="紧急" value="紧急" />
        <el-option label="高" value="高" />
        <el-option label="中" value="中" />
        <el-option label="低" value="低" />
      </el-select>
    </div>

    <!-- 需求列表 -->
    <div class="content-card">
      <el-table
        :data="paginatedRequirements"
        stripe
        v-loading="loading"
        style="width: 100%"
        empty-text="暂无需求数据"
      >
        <!-- 标题（可点击跳转详情） -->
        <el-table-column label="标题" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              class="title-link"
              @click="handleView(row)"
            >
              {{ row.title }}
            </el-button>
          </template>
        </el-table-column>

        <!-- 关联项目（el-tag 列表） -->
        <el-table-column label="关联项目" min-width="150">
          <template #default="{ row }">
            <div class="project-tags">
              <el-tag
                v-for="project in row.projects"
                :key="project"
                size="small"
                type="info"
                class="project-tag-item"
              >
                {{ project }}
              </el-tag>
            </div>
          </template>
        </el-table-column>

        <!-- 优先级（颜色 tag） -->
        <el-table-column label="优先级" width="80" align="center">
          <template #default="{ row }">
            <el-tag
              :type="priorityTagType[row.priority] || ''"
              size="small"
            >
              {{ row.priority }}
            </el-tag>
          </template>
        </el-table-column>

        <!-- 状态（StatusTag 组件） -->
        <el-table-column label="状态" width="110" align="center">
          <template #default="{ row }">
            <StatusTag type="requirement" :status="row.status" />
          </template>
        </el-table-column>

        <!-- 负责人 -->
        <el-table-column label="负责人" width="100" align="center">
          <template #default="{ row }">
            <span v-if="row.assignee">{{ row.assignee }}</span>
            <span v-else class="text-muted">待分配</span>
          </template>
        </el-table-column>

        <!-- 截止日期 -->
        <el-table-column label="截止日期" width="115" align="center">
          <template #default="{ row }">
            {{ row.expected_date }}
          </template>
        </el-table-column>

        <!-- 操作 -->
        <el-table-column label="操作" width="150" fixed="right" align="center">
          <template #default="{ row }">
            <div class="table-actions">
              <el-button
                text
                type="primary"
                size="small"
                @click="handleView(row)"
              >
                查看详情
              </el-button>
              <el-button
                v-if="canEdit(row)"
                text
                type="primary"
                size="small"
                @click="handleEdit(row)"
              >
                编辑
              </el-button>
            </div>
          </template>
        </el-table-column>

        <!-- 空状态 -->
        <template #empty>
          <div class="empty-placeholder">
            <div class="empty-icon">
              <svg viewBox="0 0 64 64" width="64" height="64" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="8" y="12" width="48" height="40" rx="4" stroke="currentColor" stroke-width="2" fill="none" />
                <line x1="8" y1="24" x2="56" y2="24" stroke="currentColor" stroke-width="2" />
                <line x1="24" y1="12" x2="24" y2="8" stroke="currentColor" stroke-width="2" />
                <line x1="40" y1="12" x2="40" y2="8" stroke="currentColor" stroke-width="2" />
                <circle cx="20" cy="18" r="2" fill="currentColor" />
                <circle cx="28" cy="18" r="2" fill="currentColor" />
                <circle cx="36" cy="18" r="2" fill="currentColor" />
              </svg>
            </div>
            <p class="empty-text">暂无匹配的需求</p>
            <p class="empty-hint">尝试调整筛选条件，或创建新的需求</p>
          </div>
        </template>
      </el-table>

      <!-- 分页 -->
      <div class="table-footer">
        <span class="text-muted">共 {{ total }} 条需求</span>
        <el-pagination
          v-if="total > pageSize"
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          background
          small
          @current-change="handlePageChange"
          @size-change="handlePageSizeChange"
        />
      </div>
    </div>

    <!-- 新建需求 Dialog -->
    <el-dialog
      v-model="dialogVisible"
      title="提交需求"
      width="600px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-width="100px"
        label-position="right"
      >
        <el-form-item label="标题" prop="title">
          <el-input
            v-model="form.title"
            placeholder="请输入需求标题（2-100字）"
            maxlength="100"
            show-word-limit
            clearable
          />
        </el-form-item>

        <el-form-item label="描述" prop="description">
          <el-input
            v-model="form.description"
            type="textarea"
            placeholder="请详细描述需求背景与预期效果"
            :rows="4"
            maxlength="500"
            show-word-limit
          />
        </el-form-item>

        <el-form-item label="关联系统" prop="project_ids">
          <el-select
            v-model="form.project_ids"
            multiple
            placeholder="请选择关联系统（可多选）"
            style="width: 100%"
            collapse-tags
            collapse-tags-tooltip
          >
            <el-option
              v-for="p in projectOptions"
              :key="p"
              :label="p"
              :value="p"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="优先级" prop="priority">
          <el-select
            v-model="form.priority"
            placeholder="请选择优先级"
            style="width: 100%"
          >
            <el-option label="紧急" value="紧急" />
            <el-option label="高" value="高" />
            <el-option label="中" value="中" />
            <el-option label="低" value="低" />
          </el-select>
        </el-form-item>

        <el-form-item label="需求类型" prop="req_type">
          <el-select
            v-model="form.req_type"
            placeholder="请选择需求类型"
            style="width: 100%"
          >
            <el-option
              v-for="t in requirementTypes"
              :key="t"
              :label="t"
              :value="t"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="期望完成时间" prop="expected_date">
          <el-date-picker
            v-model="form.expected_date"
            type="date"
            placeholder="请选择期望完成时间"
            style="width: 100%"
            value-format="YYYY-MM-DD"
            :disabled-date="(date) => date.getTime() < Date.now() - 86400000"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <div class="dialog-footer">
          <el-button @click="dialogVisible = false" :disabled="submitting">
            取消
          </el-button>
          <el-button
            type="primary"
            :loading="submitting"
            @click="handleSubmit"
          >
            确认提交
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// ============================================================
// 状态筛选 Tabs
// ============================================================
.status-tabs {
  :deep(.el-radio-button__inner) {
    padding: 6px 16px;
    font-size: $font-size-small;
  }

  .tab-count {
    display: inline-block;
    min-width: 18px;
    height: 18px;
    line-height: 18px;
    border-radius: 9px;
    background-color: rgba($color-primary, 0.12);
    color: $color-primary;
    font-size: $font-size-caption;
    font-weight: 600;
    text-align: center;
    margin-left: 4px;
    padding: 0 5px;
  }

  :deep(.el-radio-button.is-active) .tab-count {
    background-color: rgba(#fff, 0.25);
    color: #fff;
  }
}

// ============================================================
// 标题链接
// ============================================================
.title-link {
  padding: 0;
  font-size: $font-size-body;
  justify-content: flex-start;
}

// ============================================================
// 关联项目 Tag 列表
// ============================================================
.project-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;

  .project-tag-item {
    margin: 0;
  }
}

// ============================================================
// 表格底部（分页）
// ============================================================
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

// ============================================================
// Dialog 底部按钮
// ============================================================
.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

// ============================================================
// 空状态
// ============================================================
.empty-placeholder {
  padding: 48px 0;
  text-align: center;

  .empty-icon {
    color: $gray-300;
    margin-bottom: 12px;
    display: flex;
    justify-content: center;
  }

  .empty-text {
    font-size: $font-size-body;
    color: $gray-500;
    margin-bottom: 4px;
  }

  .empty-hint {
    font-size: $font-size-caption;
    color: $gray-300;
  }
}
</style>
