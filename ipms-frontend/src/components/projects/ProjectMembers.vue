<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh, Delete } from '@element-plus/icons-vue'
import { listProjectMembers, listProjectMemberOptions, addProjectMember, removeProjectMember } from '@/api/projectMembership'
import { mapApiError } from '@/composables/useApiError'

const props = defineProps({ project: { type: Object, required: true } })
const canManage = computed(() => props.project.allowed_actions?.includes('manage_members'))
const members = ref([])
const loading = ref(false)
const error = ref('')
const visible = ref(false)
const options = ref([])
const optionsLoading = ref(false)
const optionsError = ref('')
const selected = ref(null)
const saving = ref(false)
const removing = ref(null)
const saveError = ref('')

async function loadMembers() {
  if (!canManage.value) return
  loading.value = true
  error.value = ''
  try { members.value = (await listProjectMembers(props.project.id)).data.data }
  catch (failure) { error.value = mapApiError(failure).message }
  finally { loading.value = false }
}
async function loadOptions() {
  optionsLoading.value = true
  optionsError.value = ''
  options.value = []
  try { options.value = (await listProjectMemberOptions(props.project.id)).data.data }
  catch (failure) { optionsError.value = mapApiError(failure).message }
  finally { optionsLoading.value = false }
}
function openAdd() {
  selected.value = null
  saveError.value = ''
  visible.value = true
  loadOptions()
}
async function add() {
  if (saving.value || optionsLoading.value || optionsError.value) return
  const userId = Number(selected.value)
  if (!options.value.some(user => user.id === userId)) {
    saveError.value = '请选择项目成员'
    return
  }
  saving.value = true
  saveError.value = ''
  try {
    await addProjectMember(props.project.id, { user_id: userId })
    visible.value = false
    await loadMembers()
    ElMessage.success('项目成员已添加')
  } catch (failure) { saveError.value = mapApiError(failure).message }
  finally { saving.value = false }
}
async function remove(member) {
  if (removing.value || member.is_manager) return
  removing.value = member.user_id
  try {
    await ElMessageBox.confirm(`确认将“${member.display_name}”移出项目？`, '移除项目成员', {
      type: 'warning', confirmButtonText: '移除', cancelButtonText: '取消',
    })
    await removeProjectMember(props.project.id, member.user_id)
    await loadMembers()
    ElMessage.success('项目成员已移除')
  } catch (failure) {
    if (failure !== 'cancel' && failure !== 'close') error.value = mapApiError(failure).message
  } finally { removing.value = null }
}
onMounted(loadMembers)
</script>

<template>
  <section v-if="canManage" class="members-band" aria-label="项目成员">
    <header class="members-heading">
      <h2>项目成员</h2>
      <div class="member-actions">
        <el-button :icon="Refresh" aria-label="刷新项目成员" :loading="loading" @click="loadMembers" />
        <el-button type="primary" :icon="Plus" data-testid="add-member" @click="openAdd">添加成员</el-button>
      </div>
    </header>
    <p v-if="error" role="alert" class="member-error">{{ error }} <el-button text @click="loadMembers">重试</el-button></p>
    <p v-if="loading" role="status">正在加载成员</p>
    <div v-else class="members-table">
      <table>
        <thead><tr><th>姓名</th><th>项目角色</th><th>账户状态</th><th>加入时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="member in members" :key="member.user_id">
            <td>{{ member.display_name }}</td>
            <td>{{ member.is_manager ? '内部 IT 项目经理' : '项目成员' }}</td>
            <td>{{ member.is_active ? '正常' : '停用' }}</td>
            <td>{{ member.assigned_at ? member.assigned_at.slice(0, 10) : '-' }}</td>
            <td>
              <el-button v-if="!member.is_manager" :data-testid="`remove-member-${member.user_id}`"
                :icon="Delete" text type="danger" :loading="removing === member.user_id"
                :disabled="Boolean(removing)" @click="remove(member)">移除</el-button>
              <span v-else>-</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <el-dialog v-model="visible" title="添加项目成员" width="min(520px, 94vw)"
      :close-on-click-modal="!saving" :close-on-press-escape="!saving" :show-close="!saving">
      <el-form-item label="项目成员" required>
        <el-select v-model="selected" filterable :loading="optionsLoading" :disabled="saving || Boolean(optionsError)"
          data-testid="member-select" placeholder="选择成员" no-data-text="暂无可添加成员">
          <el-option v-for="user in options" :key="user.id" :value="user.id" :label="user.display_name" />
        </el-select>
      </el-form-item>
      <p v-if="optionsError" role="alert" class="member-error">{{ optionsError }}
        <el-button data-testid="retry-member-options" text @click="loadOptions">重试</el-button>
      </p>
      <p v-if="saveError" role="alert" class="member-error">{{ saveError }}</p>
      <template #footer>
        <el-button :disabled="saving" @click="visible = false">取消</el-button>
        <el-button type="primary" data-testid="save-member" :loading="saving"
          :disabled="optionsLoading || Boolean(optionsError)" @click="add">添加</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped lang="scss">
.members-band { min-width: 0; padding: 6px 0 20px; }
.members-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.members-heading h2 { margin: 0; font-size: 18px; }
.member-actions { display: flex; gap: 8px; }
.member-actions :deep(.el-button + .el-button) { margin-left: 0; }
.members-table { max-width: 100%; overflow-x: auto; }
table { width: 100%; min-width: 580px; border-collapse: collapse; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid $color-border; font-size: 13px; }
th { background: $color-canvas; color: $color-muted; }
.member-error { color: $color-danger; overflow-wrap: anywhere; }
:deep(.el-select) { width: 100%; }
</style>
