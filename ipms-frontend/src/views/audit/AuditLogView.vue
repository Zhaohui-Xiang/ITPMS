<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, View } from '@element-plus/icons-vue'
import { listAuditLogs, exportAuditLogs } from '@/api/auditLog'
import FilterBar from '@/components/common/FilterBar.vue'
import PaginatedTable from '@/components/common/PaginatedTable.vue'
import { usePagination } from '@/composables/usePagination'
import { mapApiError } from '@/composables/useApiError'
const { page, pageSize, total, requestParams, applyPagination, resetPage, setPage, setPageSize } = usePagination()
const rows = ref([]), loading = ref(false), error = ref(null), exporting = ref(false)
const selected = ref(null), detailVisible = ref(false)
const filters = reactive({ keyword: '', module: '', action_type: '', date_from: '', date_to: '' })
const applied = ref({})
const modules = { 1: '项目', 2: '需求', 3: '任务', 4: '缺陷', 5: '文档', 6: '用户', 7: '系统与发布' }
const actions = { 1: '创建', 2: '编辑', 3: '删除', 4: '状态变更', 5: '审核', 6: '分配', 7: '上传', 8: '下载', 9: '导出', 10: '登录' }
let sequence = 0
async function fetchRows() {
  const current = ++sequence
  loading.value = true
  error.value = null
  try {
    const { data } = await listAuditLogs({ ...requestParams.value, ...applied.value })
    if (current !== sequence) return
    rows.value = data.data.items
    applyPagination(data.data)
  } catch (failure) {
    if (current !== sequence) return
    rows.value = []
    error.value = mapApiError(failure)
  } finally { if (current === sequence) loading.value = false }
}
function search() {
  applied.value = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null))
  resetPage()
  fetchRows()
}
function reset() { Object.keys(filters).forEach(key => { filters[key] = '' }); search() }
function changePage(value) { setPage(value); fetchRows() }
function changeSize(value) { setPageSize(value); fetchRows() }
async function exportCsv() {
  exporting.value = true
  try {
    const { data } = await exportAuditLogs(applied.value)
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = 'audit-logs.csv'
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (failure) {
    if (failure.response?.data instanceof Blob) {
      try { failure.response.data = JSON.parse(await failure.response.data.text()) } catch {}
    }
    ElMessage.error(mapApiError(failure).message)
  } finally { exporting.value = false }
}
function inspect(row) { selected.value = row; detailVisible.value = true }
onMounted(fetchRows)
</script>
<template>
  <div class="page-container audit-page">
    <header><h1>审计日志</h1><el-button :icon="Download" :loading="exporting" @click="exportCsv">导出 CSV</el-button></header>
    <FilterBar :busy="loading" @search="search" @reset="reset">
      <el-input v-model="filters.keyword" clearable placeholder="操作人或目标名称" @keyup.enter="search" />
      <el-select v-model="filters.module" clearable placeholder="全部模块"><el-option v-for="(label, value) in modules" :key="value" :label="label" :value="Number(value)" /></el-select>
      <el-select v-model="filters.action_type" clearable placeholder="全部操作"><el-option v-for="(label, value) in actions" :key="value" :label="label" :value="Number(value)" /></el-select>
      <el-date-picker v-model="filters.date_from" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" />
      <el-date-picker v-model="filters.date_to" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" />
    </FilterBar>
    <PaginatedTable :rows="rows" :loading="loading" :error="error" :total="total" :page="page" :page-size="pageSize" @retry="fetchRows" @page-change="changePage" @page-size-change="changeSize">
      <table><thead><tr><th>时间</th><th>操作人</th><th>模块</th><th>操作</th><th>目标</th><th>IP 地址</th><th>详情</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.id">
          <td>{{ new Date(row.created_at).toLocaleString('zh-CN', { hour12: false }) }}</td>
          <td>{{ row.user_display_name || row.user_name }}</td><td>{{ modules[row.module] || row.module }}</td>
          <td>{{ actions[row.action_type] || row.action_type }}</td><td>{{ row.target_name || '-' }}</td><td>{{ row.ip_address || '-' }}</td>
          <td><el-button text :icon="View" aria-label="审计详情" @click="inspect(row)" /></td>
        </tr></tbody>
      </table>
    </PaginatedTable>
    <el-dialog v-model="detailVisible" title="审计详情" width="min(720px, 94vw)"><pre v-if="selected">{{ JSON.stringify(selected, null, 2) }}</pre></el-dialog>
  </div>
</template>
<style scoped lang="scss">
.audit-page { display: grid; gap: 16px; min-width: 0; }
header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
h1 { font-size: 24px; margin: 0; }
table { width: 100%; min-width: 900px; border-collapse: collapse; }
th, td { text-align: left; padding: 12px 16px; border-bottom: 1px solid $color-border; }
th { background: $color-canvas; color: $color-muted; font-size: 13px; }
pre { white-space: pre-wrap; overflow-wrap: anywhere; }
:deep(.filter-bar__fields > *) { max-width: 210px; }
</style>
