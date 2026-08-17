<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, RefreshLeft, Download } from '@element-plus/icons-vue'
import { listAuditLogs, exportAuditLogs } from '@/api/auditLog'

// ===== 页面状态 =====
const loading = ref(false)

// ===== 筛选条件 =====
const dateRange = ref([])
const searchOperator = ref('')
const filterModule = ref('')
const filterType = ref('')
const filterProject = ref('')

// ===== 日期快捷选项 =====
const dateShortcuts = [
  {
    text: '最近7天',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(start.getDate() - 6)
      return [start, end]
    }
  },
  {
    text: '最近30天',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(start.getDate() - 29)
      return [start, end]
    }
  },
  {
    text: '本月',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(1)
      return [start, end]
    }
  }
]

// ===== 分页 =====
const pagination = reactive({
  currentPage: 1,
  pageSize: 50,
  total: 0
})

// ===== Mock 数据（15条） =====
const mockLogs = [
  {
    id: 1,
    time: '2026-08-03 14:30:25',
    operator: '张三',
    user_type: '内部IT',
    module: '需求管理',
    action_type: '创建',
    target: '新增财务报表导出功能',
    detail: '张三 创建了需求「新增财务报表导出功能」',
    old_value: '--',
    new_value: '状态：待审核；内容：支持报表导出功能，格式包含 Excel/PDF/CSV',
    ip: '192.168.1.101'
  },
  {
    id: 2,
    time: '2026-08-03 11:15:08',
    operator: '李四',
    user_type: '供应商',
    module: '缺陷管理',
    action_type: '状态变更',
    target: '登录页面样式异常',
    detail: '李四 将缺陷状态从「待确认」改为「已确认」',
    old_value: '状态：待确认',
    new_value: '状态：已确认',
    ip: '10.0.1.55'
  },
  {
    id: 3,
    time: '2026-08-03 10:42:33',
    operator: '王五',
    user_type: '内部IT',
    module: '任务管理',
    action_type: '编辑',
    target: '接口联调任务 #T-2024',
    detail: '王五 编辑了任务「接口联调任务 #T-2024」',
    old_value: '负责人：张三；截止时间：2026-08-10',
    new_value: '负责人：李四；截止时间：2026-08-12',
    ip: '192.168.1.88'
  },
  {
    id: 4,
    time: '2026-08-03 09:05:12',
    operator: '赵六',
    user_type: '内部IT',
    module: '权限管理',
    action_type: '审核',
    target: '供应商账号申请 - 钱七',
    detail: '赵六 审核通过了钱七的供应商账号申请',
    old_value: '状态：待审核',
    new_value: '状态：已通过；角色：供应商（只读）；绑定项目：SAP B1',
    ip: '192.168.1.100'
  },
  {
    id: 5,
    time: '2026-08-02 17:30:45',
    operator: '张三',
    user_type: '内部IT',
    module: '项目管理',
    action_type: '编辑',
    target: 'SAP B1 企业资源管理系统',
    detail: '张三 编辑了项目信息「SAP B1 企业资源管理系统」',
    old_value: '项目负责人：王五',
    new_value: '项目负责人：孙七',
    ip: '192.168.1.101'
  },
  {
    id: 6,
    time: '2026-08-02 16:22:18',
    operator: '孙七',
    user_type: '内部IT',
    module: '文档管理',
    action_type: '上传',
    target: 'SAP B1 接口文档 v2.3',
    detail: '孙七 上传了文档「SAP B1 接口文档 v2.3」',
    old_value: '--',
    new_value: '文件名：SAP_B1_API_v2.3.pdf；大小：2.4MB',
    ip: '192.168.1.120'
  },
  {
    id: 7,
    time: '2026-08-02 15:10:33',
    operator: '李四',
    user_type: '供应商',
    module: '缺陷管理',
    action_type: '创建',
    target: '报表导出格式错乱',
    detail: '李四 提交了缺陷「报表导出格式错乱」',
    old_value: '--',
    new_value: '严重程度：严重；描述：导出报表时 Excel 格式与预览不一致，列宽混乱',
    ip: '10.0.1.55'
  },
  {
    id: 8,
    time: '2026-08-02 14:05:50',
    operator: '王五',
    user_type: '内部IT',
    module: '需求管理',
    action_type: '分配',
    target: '审批流优化需求',
    detail: '王五 将需求「审批流优化」分配给 SAP 实施团队',
    old_value: '负责人：未分配',
    new_value: '负责人：SAP 实施团队；优先级：高',
    ip: '192.168.1.88'
  },
  {
    id: 9,
    time: '2026-08-02 11:48:22',
    operator: '系统管理员',
    user_type: '系统用户',
    module: '权限管理',
    action_type: '编辑',
    target: '角色权限配置 - 供应商角色',
    detail: '系统管理员 修改了「供应商角色」的权限配置',
    old_value: '权限：需求查看、缺陷提交、文档下载',
    new_value: '权限：需求查看、缺陷提交、缺陷查看、文档下载、任务查看',
    ip: '192.168.1.1'
  },
  {
    id: 10,
    time: '2026-08-02 09:30:15',
    operator: '赵六',
    user_type: '内部IT',
    module: '任务管理',
    action_type: '状态变更',
    target: '前端页面开发 #T-1987',
    detail: '赵六 将任务状态从「进行中」改为「已完成」',
    old_value: '状态：进行中',
    new_value: '状态：已完成',
    ip: '192.168.1.100'
  },
  {
    id: 11,
    time: '2026-08-01 16:55:40',
    operator: '周八',
    user_type: '供应商',
    module: '文档管理',
    action_type: '下载',
    target: 'Weaver OA 集成方案.pdf',
    detail: '周八 下载了文档「Weaver OA 集成方案.pdf」',
    old_value: '--',
    new_value: '下载次数：第 3 次',
    ip: '10.0.2.33'
  },
  {
    id: 12,
    time: '2026-08-01 14:20:08',
    operator: '张三',
    user_type: '内部IT',
    module: '缺陷管理',
    action_type: '审核',
    target: '数据导入性能问题',
    detail: '张三 审核关闭了缺陷「数据导入性能问题」',
    old_value: '状态：待复测',
    new_value: '状态：已关闭；审核意见：复测通过，性能达标',
    ip: '192.168.1.101'
  },
  {
    id: 13,
    time: '2026-08-01 10:12:55',
    operator: '李四',
    user_type: '供应商',
    module: '需求管理',
    action_type: '状态变更',
    target: '移动端适配需求',
    detail: '李四 将需求状态从「已分配」改为「开发中」',
    old_value: '状态：已分配',
    new_value: '状态：开发中',
    ip: '10.0.1.55'
  },
  {
    id: 14,
    time: '2026-08-01 08:45:30',
    operator: '系统管理员',
    user_type: '系统用户',
    module: '项目管理',
    action_type: '创建',
    target: 'DataV 数据可视化平台',
    detail: '系统管理员 创建了项目「DataV 数据可视化平台」',
    old_value: '--',
    new_value: '系统类型：外部采购；供应商：帆软软件；负责人：孙七',
    ip: '192.168.1.1'
  },
  {
    id: 15,
    time: '2026-07-31 17:30:00',
    operator: '王五',
    user_type: '内部IT',
    module: '任务管理',
    action_type: '删除',
    target: '废弃接口清理 #T-1800',
    detail: '王五 删除了任务「废弃接口清理 #T-1800」',
    old_value: '任务名称：废弃接口清理 #T-1800；状态：待办',
    new_value: '--',
    ip: '192.168.1.88'
  },
  {
    id: 16,
    time: '2026-07-31 15:10:42',
    operator: '赵六',
    user_type: '内部IT',
    module: '权限管理',
    action_type: '删除',
    target: '离职员工账号 - 刘九',
    detail: '赵六 删除了离职员工刘九的系统账号',
    old_value: '账号：liujiu；角色：开发工程师；状态：已禁用',
    new_value: '--（账号已删除）',
    ip: '192.168.1.100'
  },
  {
    id: 17,
    time: '2026-07-31 13:22:18',
    operator: '吴十',
    user_type: '供应商',
    module: '缺陷管理',
    action_type: '编辑',
    target: '移动端首页加载缓慢',
    detail: '吴十 编辑了缺陷「移动端首页加载缓慢」的描述',
    old_value: '描述：首页加载时间超过 5 秒',
    new_value: '描述：首页加载时间超过 5 秒（仅 iOS 端，Android 正常）；设备：iPhone 15 Pro',
    ip: '10.0.3.77'
  },
  {
    id: 18,
    time: '2026-07-31 10:05:30',
    operator: '张三',
    user_type: '内部IT',
    module: '文档管理',
    action_type: '状态变更',
    target: '系统架构设计文档 v1.0',
    detail: '张三 将文档状态从「草稿」改为「已发布」',
    old_value: '状态：草稿',
    new_value: '状态：已发布',
    ip: '192.168.1.101'
  }
]

// ===== 所有日志数据 =====
const allLogs = ref([...mockLogs])

// ===== 模块选项 =====
const moduleOptions = [
  { label: '全部', value: '' },
  { label: '项目管理', value: '项目管理' },
  { label: '需求管理', value: '需求管理' },
  { label: '任务管理', value: '任务管理' },
  { label: '缺陷管理', value: '缺陷管理' },
  { label: '文档管理', value: '文档管理' },
  { label: '权限管理', value: '权限管理' }
]

// ===== 操作类型选项 =====
const actionTypeOptions = [
  { label: '全部', value: '' },
  { label: '创建', value: '创建' },
  { label: '编辑', value: '编辑' },
  { label: '删除', value: '删除' },
  { label: '状态变更', value: '状态变更' },
  { label: '审核', value: '审核' },
  { label: '分配', value: '分配' },
  { label: '上传', value: '上传' },
  { label: '下载', value: '下载' }
]

// ===== 项目选项 =====
const projectOptions = [
  { label: '全部', value: '' },
  { label: 'SAP B1', value: 'SAP B1' },
  { label: 'VPMS', value: 'VPMS' },
  { label: 'Weaver OA', value: 'Weaver OA' },
  { label: 'Salesforce CRM', value: 'Salesforce CRM' },
  { label: 'DataV 数据可视化平台', value: 'DataV 数据可视化平台' }
]

// ===== 用户类型 Tag 颜色映射 =====
const userTypeTagMap = {
  '内部IT': 'primary',
  '供应商': 'warning',
  '系统用户': 'info'
}

// ===== 操作类型 Tag 颜色映射 =====
const actionTypeTagMap = {
  '创建': 'success',
  '编辑': 'warning',
  '删除': 'danger',
  '状态变更': 'info',
  '审核': 'primary',
  '分配': '',
  '上传': '',
  '下载': ''
}

// ===== 计算属性：筛选后的日志 =====
const filteredLogs = computed(() => {
  let result = [...allLogs.value]

  // 日期范围筛选
  if (dateRange.value && dateRange.value.length === 2) {
    const [startDate, endDate] = dateRange.value
    // dateRange from el-date-picker returns Date objects or strings
    const start = new Date(startDate)
    start.setHours(0, 0, 0, 0)
    const end = new Date(endDate)
    end.setHours(23, 59, 59, 999)

    result = result.filter((item) => {
      const itemDate = new Date(item.time)
      return itemDate >= start && itemDate <= end
    })
  }

  // 操作人筛选
  if (searchOperator.value.trim()) {
    const keyword = searchOperator.value.trim().toLowerCase()
    result = result.filter((item) =>
      item.operator.toLowerCase().includes(keyword)
    )
  }

  // 模块筛选
  if (filterModule.value) {
    result = result.filter((item) => item.module === filterModule.value)
  }

  // 操作类型筛选
  if (filterType.value) {
    result = result.filter((item) => item.action_type === filterType.value)
  }

  return result
})

// ===== 计算属性：分页后的日志 =====
const pagedLogs = computed(() => {
  const start = (pagination.currentPage - 1) * pagination.pageSize
  const end = start + pagination.pageSize
  return filteredLogs.value.slice(start, end)
})

// ===== 计算属性：总条数 =====
const totalCount = computed(() => filteredLogs.value.length)

// ===== 初始化加载 =====
onMounted(() => {
  fetchLogs()
})

// ===== 拉取日志数据 =====
async function fetchLogs() {
  loading.value = true
  try {
    // const params = {
    //   page: pagination.currentPage,
    //   pageSize: pagination.pageSize,
    //   start_date: dateRange.value?.[0] || undefined,
    //   end_date: dateRange.value?.[1] || undefined,
    //   operator: searchOperator.value || undefined,
    //   module: filterModule.value || undefined,
    //   action_type: filterType.value || undefined,
    //   project_id: filterProject.value || undefined
    // }
    // const res = await listAuditLogs(params)
    // allLogs.value = res.data.data
    // pagination.total = res.data.total

    // Mock 延迟
    await new Promise((resolve) => setTimeout(resolve, 300))
    allLogs.value = [...mockLogs]
    pagination.total = allLogs.value.length
  } catch (error) {
    ElMessage.error('获取操作日志失败')
    console.error('fetchLogs error:', error)
  } finally {
    loading.value = false
  }
}

// ===== 查询 =====
function handleSearch() {
  pagination.currentPage = 1
  fetchLogs()
}

// ===== 重置 =====
function handleReset() {
  dateRange.value = []
  searchOperator.value = ''
  filterModule.value = ''
  filterType.value = ''
  filterProject.value = ''
  pagination.currentPage = 1
  fetchLogs()
}

// ===== 分页 =====
function handlePageChange(page) {
  pagination.currentPage = page
}

function handleSizeChange(size) {
  pagination.pageSize = size
  pagination.currentPage = 1
}

// ===== 导出 CSV =====
function handleExport() {
  // 生成 CSV 内容
  const headers = ['时间', '操作人', '用户类型', '模块', '操作类型', '操作对象', '详情', '变更前', '变更后', 'IP地址']
  const rows = allLogs.value.map((item) => [
    item.time,
    item.operator,
    item.user_type,
    item.module,
    item.action_type,
    item.target,
    item.detail,
    item.old_value || '--',
    item.new_value || '--',
    item.ip
  ])

  // 转义 CSV 单元格
  function escapeCsvCell(value) {
    if (value == null) return ''
    const str = String(value)
    if (str.includes(',') || str.includes('"') || str.includes('\n')) {
      return '"' + str.replace(/"/g, '""') + '"'
    }
    return str
  }

  const csvContent = [
    headers.map(escapeCsvCell).join(','),
    ...rows.map((row) => row.map(escapeCsvCell).join(','))
  ].join('\n')

  // 添加 BOM 以支持 Excel 中文显示
  const BOM = '﻿'
  const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  const now = new Date()
  const dateStr = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`
  link.download = `操作日志_${dateStr}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)

  ElMessage.success(`已导出 ${allLogs.value.length} 条操作日志`)
}

// ===== 格式化值显示 =====
function formatValueText(value) {
  if (!value || value === '--') return '--'
  return value
}
</script>

<template>
  <div class="page-container">
    <!-- 页面标题 -->
    <div class="page-header flex-between">
      <div>
        <h2 class="page-title">操作日志</h2>
        <p class="page-description">查看系统操作记录，追溯数据变更历史</p>
      </div>
      <div class="header-export">
        <el-badge :value="'P1'" class="export-badge" type="danger">
          <el-button type="success" :icon="Download" @click="handleExport">
            导出 CSV
          </el-button>
        </el-badge>
      </div>
    </div>

    <!-- 筛选条件 -->
    <div class="filter-bar">
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        range-separator="至"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        :shortcuts="dateShortcuts"
        style="width: 260px"
        clearable
      />
      <el-input
        v-model="searchOperator"
        placeholder="搜索操作人..."
        :prefix-icon="Search"
        clearable
        style="width: 180px"
        @keyup.enter="handleSearch"
        @clear="handleSearch"
      />
      <el-select
        v-model="filterModule"
        placeholder="全部模块"
        clearable
        style="width: 140px"
      >
        <el-option
          v-for="item in moduleOptions"
          :key="item.value"
          :label="item.label"
          :value="item.value"
        />
      </el-select>
      <el-select
        v-model="filterType"
        placeholder="全部类型"
        clearable
        style="width: 140px"
      >
        <el-option
          v-for="item in actionTypeOptions"
          :key="item.value"
          :label="item.label"
          :value="item.value"
        />
      </el-select>
      <el-select
        v-model="filterProject"
        placeholder="全部项目"
        clearable
        style="width: 160px"
      >
        <el-option
          v-for="item in projectOptions"
          :key="item.value"
          :label="item.label"
          :value="item.value"
        />
      </el-select>
      <el-button type="primary" :icon="Search" @click="handleSearch">
        查询
      </el-button>
      <el-button :icon="RefreshLeft" @click="handleReset">
        重置
      </el-button>
    </div>

    <!-- 日志表格 -->
    <div class="content-card">
      <!-- 加载状态 -->
      <div v-if="loading" class="loading-container">
        <el-skeleton :rows="10" animated />
      </div>

      <!-- 空状态 -->
      <div v-else-if="filteredLogs.length === 0" class="empty-container">
        <el-empty description="暂无操作日志记录" />
      </div>

      <!-- 有数据 -->
      <template v-else>
        <el-table
          :data="pagedLogs"
          v-loading="loading"
          stripe
          style="width: 100%"
          :header-cell-class-name="() => 'audit-table-header'"
        >
          <!-- 展开行 -->
          <el-table-column type="expand" width="50">
            <template #default="{ row }">
              <div class="expand-content">
                <div class="expand-title">变更详情</div>
                <div class="expand-row">
                  <div class="expand-col">
                    <div class="expand-label">变更前</div>
                    <div class="expand-value expand-old">{{ formatValueText(row.old_value) }}</div>
                  </div>
                  <div class="expand-arrow">
                    <span class="arrow-icon">&#8594;</span>
                  </div>
                  <div class="expand-col">
                    <div class="expand-label">变更后</div>
                    <div class="expand-value expand-new">{{ formatValueText(row.new_value) }}</div>
                  </div>
                </div>
                <div class="expand-detail-row">
                  <span class="expand-label">操作描述：</span>
                  <span>{{ row.detail }}</span>
                </div>
              </div>
            </template>
          </el-table-column>

          <!-- 时间 -->
          <el-table-column label="时间" width="160" prop="time">
            <template #default="{ row }">
              <span class="cell-time">{{ row.time }}</span>
            </template>
          </el-table-column>

          <!-- 操作人 -->
          <el-table-column label="操作人" width="100" prop="operator">
            <template #default="{ row }">
              <span class="cell-operator">{{ row.operator }}</span>
            </template>
          </el-table-column>

          <!-- 用户类型 -->
          <el-table-column label="用户类型" width="100" align="center">
            <template #default="{ row }">
              <el-tag
                :type="userTypeTagMap[row.user_type] || 'info'"
                size="small"
                effect="plain"
              >
                {{ row.user_type }}
              </el-tag>
            </template>
          </el-table-column>

          <!-- 模块 -->
          <el-table-column label="模块" width="100" prop="module" align="center">
            <template #default="{ row }">
              <span class="cell-module">{{ row.module }}</span>
            </template>
          </el-table-column>

          <!-- 操作类型 -->
          <el-table-column label="类型" width="100" align="center">
            <template #default="{ row }">
              <el-tag
                :type="actionTypeTagMap[row.action_type] || ''"
                size="small"
              >
                {{ row.action_type }}
              </el-tag>
            </template>
          </el-table-column>

          <!-- 操作对象 -->
          <el-table-column label="操作对象" width="180" prop="target" show-overflow-tooltip>
            <template #default="{ row }">
              <span class="cell-target">{{ row.target }}</span>
            </template>
          </el-table-column>

          <!-- 详情 -->
          <el-table-column label="详情" min-width="200" prop="detail" show-overflow-tooltip>
            <template #default="{ row }">
              <span class="cell-detail">{{ row.detail }}</span>
            </template>
          </el-table-column>

          <!-- IP地址 -->
          <el-table-column label="IP地址" width="140" prop="ip" align="center">
            <template #default="{ row }">
              <code class="cell-ip">{{ row.ip }}</code>
            </template>
          </el-table-column>
        </el-table>

        <!-- 分页 -->
        <div class="table-footer">
          <span class="text-muted">共 {{ totalCount }} 条记录</span>
          <el-pagination
            v-model:current-page="pagination.currentPage"
            v-model:page-size="pagination.pageSize"
            :page-sizes="[20, 50, 100, 200]"
            :layout="'total, sizes, prev, pager, next, jumper'"
            :total="totalCount"
            background
            @current-change="handlePageChange"
            @size-change="handleSizeChange"
          />
        </div>
      </template>
    </div>
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

  .header-export {
    flex-shrink: 0;

    .export-badge {
      :deep(.el-badge__content) {
        font-size: 10px;
        height: 16px;
        line-height: 16px;
        padding: 0 4px;
      }
    }
  }
}

// ============================================================
// 筛选栏
// ============================================================
.filter-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 16px 20px;
  background: #fff;
  border-radius: $border-radius-md;
  border: 1px solid $gray-200;
  margin-bottom: 20px;
}

// ============================================================
// 内容卡片
// ============================================================
.content-card {
  background: #fff;
  border-radius: $border-radius-md;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

// ============================================================
// 加载 / 空状态
// ============================================================
.loading-container {
  padding: 40px 0;
}

.empty-container {
  padding: 60px 0;
  display: flex;
  justify-content: center;
}

// ============================================================
// 表格样式
// ============================================================
:deep(.audit-table-header) {
  background: $gray-50;
  color: $gray-700;
  font-weight: 600;
  font-size: $font-size-small;
}

.cell-time {
  font-size: $font-size-small;
  color: $gray-700;
}

.cell-operator {
  font-weight: 500;
  color: $gray-900;
}

.cell-module {
  color: $gray-700;
  font-size: $font-size-small;
}

.cell-target {
  color: $color-primary;
  font-weight: 500;
}

.cell-detail {
  color: $gray-700;
  font-size: $font-size-small;
}

.cell-ip {
  font-family: 'SF Mono', 'Consolas', 'Monaco', monospace;
  font-size: $font-size-caption;
  color: $gray-500;
  background: $gray-100;
  padding: 2px 6px;
  border-radius: $border-radius-sm;
}

// ============================================================
// 展开行内容
// ============================================================
.expand-content {
  padding: 16px 24px 16px 40px;
  background: $gray-50;
  border-radius: $border-radius-sm;

  .expand-title {
    font-size: $font-size-body;
    font-weight: 600;
    color: $gray-900;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid $gray-200;
  }

  .expand-row {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 12px;
  }

  .expand-col {
    flex: 1;
    min-width: 0;
  }

  .expand-label {
    font-size: $font-size-caption;
    color: $gray-500;
    margin-bottom: 4px;
    font-weight: 500;
  }

  .expand-value {
    font-size: $font-size-small;
    color: $gray-700;
    background: #fff;
    border: 1px solid $gray-200;
    border-radius: $border-radius-sm;
    padding: 8px 12px;
    line-height: 1.6;
    word-break: break-all;
    white-space: pre-wrap;

    &.expand-old {
      color: $gray-500;
      border-left: 3px solid $gray-300;
    }

    &.expand-new {
      color: $color-success;
      border-left: 3px solid $color-success;
    }
  }

  .expand-arrow {
    display: flex;
    align-items: flex-start;
    padding-top: 22px;
    flex-shrink: 0;

    .arrow-icon {
      font-size: 20px;
      color: $color-primary;
      font-weight: bold;
    }
  }

  .expand-detail-row {
    font-size: $font-size-small;
    color: $gray-700;
    padding-top: 8px;
    border-top: 1px solid $gray-200;

    .expand-label {
      display: inline;
      color: $gray-500;
      font-weight: 500;
    }
  }
}

// ============================================================
// 表格底部分页
// ============================================================
.table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
  padding: 0 4px;

  .text-muted {
    color: $gray-500;
    font-size: $font-size-small;
    flex-shrink: 0;
  }
}

// ============================================================
// 响应式
// ============================================================
@media (max-width: 1200px) {
  .filter-bar {
    gap: 8px;
  }

  .expand-content {
    padding: 12px 16px 12px 24px;

    .expand-row {
      flex-direction: column;
      gap: 8px;
    }

    .expand-arrow {
      display: none;
    }
  }
}

@media (max-width: 768px) {
  .page-header {
    flex-direction: column;
    gap: 12px;

    .header-export {
      width: 100%;
    }
  }

  .filter-bar {
    flex-direction: column;
    align-items: stretch;

    > * {
      width: 100% !important;
    }
  }

  .table-footer {
    flex-direction: column;
    gap: 12px;
    align-items: stretch;

    :deep(.el-pagination) {
      justify-content: center;
      flex-wrap: wrap;
    }
  }
}
</style>
