<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Edit, Plus, Refresh, User } from '@element-plus/icons-vue'
import { getOrgTree, createOrgNode, updateOrgNode, deleteOrgNode, addOrgUser, removeOrgUser } from '@/api/organization'
import { listUsers } from '@/api/user'
import AsyncState from '@/components/common/AsyncState.vue'
import { mapApiError } from '@/composables/useApiError'
const orgType = ref(1), tree = ref([]), selected = ref(null)
const loading = ref(false), error = ref(null), busy = ref(false)
const nodeVisible = ref(false), memberVisible = ref(false), memberError = ref(null)
const nodeForm = reactive({ id: null, name: '', description: '', parent_id: null })
const userOptions = ref([]), userIds = ref([]), userLoading = ref(false)
const members = computed(() => selected.value?.users ?? [])
const types = [{ value: 1, label: '内部 IT' }, { value: 2, label: '供应商' }, { value: 3, label: '业务部门' }]
let sequence = 0
function findNode(nodes, id) {
  for (const node of nodes) {
    if (node.id === id) return node
    const child = findNode(node.children ?? [], id)
    if (child) return child
  }
  return null
}
async function fetchTree() {
  const current = ++sequence
  const id = selected.value?.id
  loading.value = true
  error.value = null
  try {
    const { data } = await getOrgTree({ org_type: orgType.value })
    if (current !== sequence) return
    tree.value = data.data
    selected.value = findNode(tree.value, id) ?? tree.value[0] ?? null
  } catch (failure) {
    if (current !== sequence) return
    tree.value = []
    selected.value = null
    error.value = mapApiError(failure)
  } finally { if (current === sequence) loading.value = false }
}
function openNode(edit = false, parent = null) {
  Object.assign(nodeForm, edit ? {
    id: selected.value.id, name: selected.value.name, description: selected.value.description ?? '', parent_id: selected.value.parent_id,
  } : { id: null, name: '', description: '', parent_id: parent?.id ?? null })
  nodeVisible.value = true
}
async function saveNode() {
  if (!nodeForm.name.trim() || busy.value) return
  busy.value = true
  try {
    const data = { name: nodeForm.name.trim(), description: nodeForm.description }
    if (nodeForm.id) await updateOrgNode(nodeForm.id, data)
    else await createOrgNode({ ...data, org_type: orgType.value, parent_id: nodeForm.parent_id })
    nodeVisible.value = false
    ElMessage.success('组织已保存')
    await fetchTree()
  } catch (failure) { ElMessage.error(mapApiError(failure).message) }
  finally { busy.value = false }
}
async function removeNode() {
  if (!selected.value || busy.value) return
  const node = selected.value
  try {
    await ElMessageBox.confirm('确认删除组织「' + node.name + '」？', '删除组织', { type: 'warning' })
    busy.value = true
    await deleteOrgNode(node.id)
    await fetchTree()
    ElMessage.success('组织已删除')
  } catch (failure) {
    if (failure !== 'cancel' && failure !== 'close') ElMessage.error(mapApiError(failure).message)
  } finally { busy.value = false }
}
async function loadUsers() {
  userLoading.value = true
  memberError.value = null
  userOptions.value = []
  const node = selected.value
  try {
    let page = 1, last = 1
    const all = []
    do {
      const { data } = await listUsers({ user_type: orgType.value, page, page_size: 100 })
      all.push(...data.data.items)
      last = data.data.total_pages
      page++
    } while (page <= last)
    if (node?.id === selected.value?.id) userOptions.value = all.filter(user => !members.value.some(member => member.id === user.id))
  } catch (failure) { memberError.value = mapApiError(failure) }
  finally { userLoading.value = false }
}
async function openMembers() {
  userIds.value = []
  memberVisible.value = true
  await loadUsers()
}
async function saveMembers() {
  if (!userIds.value.length || busy.value) return
  busy.value = true
  try {
    await addOrgUser(selected.value.id, { user_ids: userIds.value.map(Number), role_in_org: 'member' })
    memberVisible.value = false
    await fetchTree()
    ElMessage.success('成员已添加')
  } catch (failure) { memberError.value = mapApiError(failure) }
  finally { busy.value = false }
}
async function removeMember(member) {
  try {
    await ElMessageBox.confirm('确认将「' + member.display_name + '」移出「' + selected.value.name + '」？', '移除成员')
    busy.value = true
    await removeOrgUser(selected.value.id, member.id)
    await fetchTree()
  } catch (failure) {
    if (failure !== 'cancel' && failure !== 'close') ElMessage.error(mapApiError(failure).message)
  } finally { busy.value = false }
}
onMounted(fetchTree)
</script>
<template>
  <div class="page-container organization-page">
    <header><h1>组织架构</h1><div class="toolbar">
      <el-select v-model="orgType" :disabled="busy" @change="selected = null; fetchTree()"><el-option v-for="type in types" :key="type.value" :label="type.label" :value="type.value" /></el-select>
      <el-button :icon="Refresh" aria-label="刷新组织" @click="fetchTree" />
      <el-button :icon="Plus" type="primary" @click="openNode()">新建组织</el-button>
    </div></header>
    <AsyncState :loading="loading" :error="error" :empty="!tree.length" empty-title="暂无组织" @retry="fetchTree">
      <div class="organization-layout">
        <nav aria-label="组织树"><el-tree :data="tree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all highlight-current :current-node-key="selected?.id" @node-click="selected = $event" /></nav>
        <section v-if="selected">
          <header><div><h2>{{ selected.name }}</h2><p>{{ selected.description }}</p></div>
            <div class="toolbar">
              <el-button :icon="Plus" aria-label="新建子组织" @click="openNode(false, selected)" />
              <el-button :icon="Edit" aria-label="编辑组织" @click="openNode(true)" />
              <el-button :icon="Delete" type="danger" plain aria-label="删除组织" @click="removeNode" />
            </div>
          </header>
          <div class="members-heading"><h3>成员（{{ members.length }}）</h3><el-button :icon="User" :disabled="busy" @click="openMembers">添加成员</el-button></div>
          <div class="table-scroll"><table><thead><tr><th>姓名</th><th>账号</th><th>组织角色</th><th>操作</th></tr></thead>
            <tbody><tr v-for="member in members" :key="member.id"><td>{{ member.display_name }}</td><td>{{ member.username }}</td><td>{{ member.pivot?.role_in_org || '-' }}</td><td><el-button text :icon="Delete" aria-label="移除成员" :disabled="busy" @click="removeMember(member)" /></td></tr></tbody></table>
          </div>
          <el-empty v-if="!members.length" description="暂无成员" />
        </section>
      </div>
    </AsyncState>
    <el-dialog v-model="nodeVisible" :title="nodeForm.id ? '编辑组织' : '新建组织'" width="min(540px, 94vw)">
      <el-form label-position="top"><el-form-item label="组织名称" required><el-input v-model="nodeForm.name" maxlength="100" /></el-form-item><el-form-item label="说明"><el-input v-model="nodeForm.description" type="textarea" :rows="4" /></el-form-item></el-form>
      <template #footer><el-button @click="nodeVisible = false">取消</el-button><el-button type="primary" :loading="busy" @click="saveNode">保存</el-button></template>
    </el-dialog>
    <el-dialog v-model="memberVisible" title="添加组织成员" width="min(540px, 94vw)">
      <el-select v-model="userIds" multiple filterable :loading="userLoading" placeholder="选择人员">
        <el-option v-for="user in userOptions" :key="user.id" :label="user.display_name + ' (' + user.username + ')'" :value="user.id" />
      </el-select>
      <p v-if="memberError" class="form-error">{{ memberError.message }}<el-button text @click="loadUsers">重试</el-button></p>
      <template #footer><el-button @click="memberVisible = false">取消</el-button><el-button type="primary" :loading="busy" :disabled="!userIds.length" @click="saveMembers">添加</el-button></template>
    </el-dialog>
  </div>
</template>
<style scoped lang="scss">
.organization-page { display: grid; gap: 20px; min-width: 0; }
header, .toolbar, .members-heading { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
h1 { font-size: 24px; margin: 0; } h2 { font-size: 20px; margin: 0; } h3 { font-size: 16px; }
.toolbar .el-select { width: 160px; }
.organization-layout { display: grid; grid-template-columns: minmax(200px, 280px) minmax(0, 1fr); background: white; min-height: 440px; }
nav { padding: 16px; border-right: 1px solid $color-border; overflow: auto; }
section { padding: 20px; min-width: 0; }
table { width: 100%; min-width: 480px; border-collapse: collapse; }
th, td { text-align: left; padding: 12px; border-bottom: 1px solid $color-border; }
th { background: $color-canvas; font-size: 13px; }
.table-scroll { overflow-x: auto; }
.form-error { color: $color-danger; }
@media (max-width: 760px) { .organization-layout { grid-template-columns: 1fr; } nav { border-right: 0; border-bottom: 1px solid $color-border; max-height: 240px; } }
</style>
