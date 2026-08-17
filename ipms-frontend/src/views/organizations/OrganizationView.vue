<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  OfficeBuilding,
  FolderOpened,
  Plus,
  Edit,
  Delete,
  Search,
  CirclePlus,
  Remove,
  UserFilled,
} from '@element-plus/icons-vue'
import {
  getOrgTree,
  createOrgNode,
  updateOrgNode,
  deleteOrgNode,
  addOrgUser,
  removeOrgUser,
} from '@/api/organization'

// ====================================================================
// ID 生成器
// ====================================================================
let nextId = 2000
function generateId() {
  return nextId++
}

// ====================================================================
// 常量
// ====================================================================
const NODE_TYPE_MAP = {
  department: '部门',
  team: '团队',
  user: '人员',
}

const INTERNAL_IT_ROLES = [
  '项目经理', '开发组长', '高级开发工程师', '开发工程师',
  '测试工程师', 'UI设计师', 'SAP顾问', 'SAP开发',
  '运维组长', '运维工程师', '系统管理员',
]

const SUPPLIER_ROLES = [
  '实施顾问', '技术顾问', '开发组长', '开发工程师',
  '测试工程师', '运维顾问', 'DBA',
]

const SYSTEM_USER_ROLES = [
  '财务主管', '会计', '销售经理', '销售代表',
  '采购经理', '采购专员', '业务分析师', '数据专员',
]

// ====================================================================
// Mock 组织架构数据
// ====================================================================
const internalITTree = ref([
  {
    id: 1, label: '信息化部门', type: 'department',
    children: [
      {
        id: 11, label: '开发组', type: 'team',
        children: [
          { id: 111, label: '张伟', type: 'user', role: '开发组长', email: 'zhangwei@it-company.com', phone: '13800138001', assignedProjects: ['SAP B1', 'Weaver OA'] },
          { id: 112, label: '李娜', type: 'user', role: '高级开发工程师', email: 'lina@it-company.com', phone: '13800138002', assignedProjects: ['VPMS'] },
          { id: 113, label: '王强', type: 'user', role: '开发工程师', email: 'wangqiang@it-company.com', phone: '13800138003', assignedProjects: ['SAP B1'] },
        ],
      },
      {
        id: 12, label: 'SAP组', type: 'team',
        children: [
          { id: 121, label: '赵敏', type: 'user', role: 'SAP顾问', email: 'zhaomin@it-company.com', phone: '13800138004', assignedProjects: ['SAP B1'] },
          { id: 122, label: '刘洋', type: 'user', role: 'SAP开发', email: 'liuyang@it-company.com', phone: '13800138005', assignedProjects: ['SAP B1'] },
        ],
      },
      {
        id: 13, label: '运维组', type: 'team',
        children: [
          { id: 131, label: '陈刚', type: 'user', role: '运维组长', email: 'chengang@it-company.com', phone: '13800138006', assignedProjects: ['Infra'] },
          { id: 132, label: '周静', type: 'user', role: '系统管理员', email: 'zhoujing@it-company.com', phone: '13800138007', assignedProjects: ['Infra'] },
        ],
      },
    ],
  },
])

const supplierTree = ref([
  {
    id: 2, label: '供应商A公司', type: 'department',
    children: [
      {
        id: 21, label: '实施团队', type: 'team',
        children: [
          { id: 211, label: '孙磊', type: 'user', role: '实施顾问', email: 'sunlei@supplier-a.com', phone: '13900139001', assignedProjects: ['SAP B1'] },
          { id: 212, label: '马超', type: 'user', role: '技术顾问', email: 'machao@supplier-a.com', phone: '13900139002', assignedProjects: ['SAP B1'] },
        ],
      },
      {
        id: 22, label: '开发团队', type: 'team',
        children: [
          { id: 221, label: '黄丽', type: 'user', role: '开发组长', email: 'huangli@supplier-a.com', phone: '13900139003', assignedProjects: ['Weaver OA'] },
          { id: 222, label: '林峰', type: 'user', role: '开发工程师', email: 'linfeng@supplier-a.com', phone: '13900139004', assignedProjects: ['Weaver OA'] },
        ],
      },
    ],
  },
  {
    id: 3, label: '供应商B公司', type: 'department',
    children: [
      {
        id: 31, label: '运维团队', type: 'team',
        children: [
          { id: 311, label: '吴婷', type: 'user', role: '运维顾问', email: 'wuting@supplier-b.com', phone: '13900139005', assignedProjects: ['VPMS'] },
          { id: 312, label: '郑浩', type: 'user', role: 'DBA', email: 'zhenghao@supplier-b.com', phone: '13900139006', assignedProjects: ['VPMS'] },
        ],
      },
    ],
  },
])

const systemUserTree = ref([
  {
    id: 4, label: '通用科技有限公司', type: 'department',
    children: [
      {
        id: 41, label: '财务部', type: 'team',
        children: [
          { id: 411, label: '何慧', type: 'user', role: '财务主管', email: 'hehui@gentech.com', phone: '13700137001', assignedProjects: ['SAP B1'] },
          { id: 412, label: '徐明', type: 'user', role: '会计', email: 'xuming@gentech.com', phone: '13700137002', assignedProjects: ['SAP B1'] },
        ],
      },
      {
        id: 42, label: '销售部', type: 'team',
        children: [
          { id: 421, label: '唐芳', type: 'user', role: '销售经理', email: 'tangfang@gentech.com', phone: '13700137003', assignedProjects: ['Salesforce'] },
          { id: 422, label: '曹磊', type: 'user', role: '销售代表', email: 'caolei@gentech.com', phone: '13700137004', assignedProjects: ['Salesforce'] },
        ],
      },
      {
        id: 43, label: '采购部', type: 'team',
        children: [
          { id: 431, label: '邓超', type: 'user', role: '采购经理', email: 'dengchao@gentech.com', phone: '13700137005', assignedProjects: ['VPMS'] },
          { id: 432, label: '彭燕', type: 'user', role: '采购专员', email: 'pengyan@gentech.com', phone: '13700137006', assignedProjects: ['VPMS'] },
        ],
      },
    ],
  },
])

// ====================================================================
// 可分配用户池
// ====================================================================
const internalITUsersPool = ref([
  { id: 501, label: '杨帆', type: 'user', role: '开发工程师', email: 'yangfan@it-company.com', phone: '13800138010', assignedProjects: [] },
  { id: 502, label: '胡涛', type: 'user', role: '测试工程师', email: 'hutao@it-company.com', phone: '13800138011', assignedProjects: [] },
  { id: 503, label: '唐洁', type: 'user', role: 'UI设计师', email: 'tangjie@it-company.com', phone: '13800138012', assignedProjects: [] },
  { id: 504, label: '苏瑞', type: 'user', role: '开发工程师', email: 'surui@it-company.com', phone: '13800138013', assignedProjects: [] },
])

const supplierUsersPool = ref([
  { id: 601, label: '宋伟', type: 'user', role: '实施顾问', email: 'songwei@supplier.com', phone: '13900139010', assignedProjects: [] },
  { id: 602, label: '冯雪', type: 'user', role: '测试工程师', email: 'fengxue@supplier.com', phone: '13900139011', assignedProjects: [] },
  { id: 603, label: '贺明', type: 'user', role: '技术顾问', email: 'heming@supplier.com', phone: '13900139012', assignedProjects: [] },
])

const systemUsersPool = ref([
  { id: 701, label: '方磊', type: 'user', role: '业务分析师', email: 'fanglei@gentech.com', phone: '13700137010', assignedProjects: [] },
  { id: 702, label: '钱敏', type: 'user', role: '数据专员', email: 'qianmin@gentech.com', phone: '13700137011', assignedProjects: [] },
  { id: 703, label: '韩冰', type: 'user', role: '会计', email: 'hanbing@gentech.com', phone: '13700137012', assignedProjects: [] },
])

// ====================================================================
// 页面状态
// ====================================================================
const activeTab = ref('internal_it')
const treeData = ref([])
const selectedNode = ref(null)
const treeRef = ref(null)
const filterText = ref('')

// 上下文菜单
const contextMenu = reactive({
  visible: false,
  left: 0,
  top: 0,
  targetNode: null,
})

// ====================================================================
// 计算属性
// ====================================================================

/** 当前 Tab 对应的树数据 */
const currentTree = computed(() => {
  switch (activeTab.value) {
    case 'internal_it': return internalITTree.value
    case 'supplier': return supplierTree.value
    case 'system_user': return systemUserTree.value
    default: return internalITTree.value
  }
})

/** 当前选中节点的所有成员（递归收集用户） */
const currentMembers = computed(() => {
  if (!selectedNode.value || selectedNode.value.type === 'user') return []
  return collectUsers(selectedNode.value)
})

/** 当前 Tab 对应的可用角色 */
const availableRoles = computed(() => {
  switch (activeTab.value) {
    case 'internal_it': return INTERNAL_IT_ROLES
    case 'supplier': return SUPPLIER_ROLES
    case 'system_user': return SYSTEM_USER_ROLES
    default: return INTERNAL_IT_ROLES
  }
})

/** 当前 Tab 对应的可分配用户池 */
const availableUsers = computed(() => {
  switch (activeTab.value) {
    case 'internal_it': return internalITUsersPool.value
    case 'supplier': return supplierUsersPool.value
    case 'system_user': return systemUsersPool.value
    default: return internalITUsersPool.value
  }
})

/** 当前选中节点是否为部门或团队 */
const isContainerNode = computed(() => {
  if (!selectedNode.value) return false
  return selectedNode.value.type === 'department' || selectedNode.value.type === 'team'
})

/** 上下文菜单定位样式 */
const contextMenuStyle = computed(() => ({
  left: contextMenu.left + 'px',
  top: contextMenu.top + 'px',
}))

// ====================================================================
// 工具函数
// ====================================================================

/** 递归收集节点下的所有用户 */
function collectUsers(node) {
  const users = []
  if (node.children && node.children.length > 0) {
    for (const child of node.children) {
      if (child.type === 'user') {
        users.push(child)
      } else {
        users.push(...collectUsers(child))
      }
    }
  }
  return users
}

/** 递归统计子节点和成员数量 */
function countDescendants(node) {
  let childNodes = 0
  let members = 0
  if (node.children && node.children.length > 0) {
    for (const child of node.children) {
      if (child.type === 'user') {
        members++
      } else {
        childNodes++
        const sub = countDescendants(child)
        childNodes += sub.childNodes
        members += sub.members
      }
    }
  }
  return { childNodes, members }
}

/** 在树中查找节点（广度优先） */
function findNodeInTree(tree, nodeId) {
  for (const node of tree) {
    if (node.id === nodeId) return node
    if (node.children && node.children.length > 0) {
      const found = findNodeInTree(node.children, nodeId)
      if (found) return found
    }
  }
  return null
}

/** 在树中查找父节点 */
function findParentInTree(tree, nodeId) {
  for (const node of tree) {
    if (node.children && node.children.length > 0) {
      for (const child of node.children) {
        if (child.id === nodeId) return node
      }
      const found = findParentInTree(node.children, nodeId)
      if (found) return found
    }
  }
  return null
}

/** 从父节点中移除子节点 */
function removeChildFromNode(parentNode, childId) {
  if (!parentNode || !parentNode.children) return false
  const index = parentNode.children.findIndex((c) => c.id === childId)
  if (index !== -1) {
    parentNode.children.splice(index, 1)
    return true
  }
  return false
}

/** 获取节点类型图标和颜色 */
function getNodeIconStyle(type) {
  switch (type) {
    case 'department':
      return { icon: OfficeBuilding, color: '#67C23A' }
    case 'team':
      return { icon: FolderOpened, color: '#E6A23C' }
    case 'user':
      return { icon: UserFilled, color: '#409EFF' }
    default:
      return { icon: OfficeBuilding, color: '#67C23A' }
  }
}

/** 获取节点类型的中文标签 */
function getNodeTypeLabel(type) {
  return NODE_TYPE_MAP[type] || type
}

/** 清空选中状态 */
function clearSelection() {
  selectedNode.value = null
  treeRef.value?.setCurrentKey(null)
}

// ====================================================================
// Tab 切换监听
// ====================================================================
watch(activeTab, () => {
  clearSelection()
  closeContextMenu()
  treeData.value = currentTree.value
}, { immediate: true })

// ====================================================================
// 树搜索过滤
// ====================================================================
watch(filterText, (val) => {
  treeRef.value?.filter(val)
})

function filterNode(value, data) {
  if (!value) return true
  return data.label && data.label.includes(value)
}

// ====================================================================
// 树节点交互
// ====================================================================

/** 左键点击节点 - 选中并显示详情 */
function handleNodeClick(data) {
  selectedNode.value = data
  closeContextMenu()
}

/** 右键点击节点 - 弹出上下文菜单 */
function handleContextMenu(event, data, node) {
  event.preventDefault()
  selectedNode.value = data
  contextMenu.targetNode = data
  contextMenu.left = Math.min(event.clientX, window.innerWidth - 160)
  contextMenu.top = Math.min(event.clientY, window.innerHeight - 160)
  contextMenu.visible = true
}

/** 关闭上下文菜单 */
function closeContextMenu() {
  contextMenu.visible = false
  contextMenu.targetNode = null
}

/** 全局点击关闭上下文菜单 */
function handleGlobalClick() {
  if (contextMenu.visible) {
    closeContextMenu()
  }
}

// ====================================================================
// 上下文菜单操作
// ====================================================================

/** 上下文菜单 - 新增子节点 */
function handleContextAddChild() {
  const parent = contextMenu.targetNode
  if (!parent) return
  if (parent.type === 'user') {
    ElMessage.warning('用户节点不能添加子节点')
    closeContextMenu()
    return
  }
  openNodeDialog('create', parent)
  closeContextMenu()
}

/** 上下文菜单 - 编辑节点 */
function handleContextEdit() {
  const node = contextMenu.targetNode
  if (!node) return
  openNodeDialog('edit', node)
  closeContextMenu()
}

/** 上下文菜单 - 删除节点 */
function handleContextDelete() {
  const node = contextMenu.targetNode
  if (!node) return
  closeContextMenu()
  handleDeleteNode(node)
}

// ====================================================================
// 节点对话框（新增/编辑）
// ====================================================================
const nodeDialog = reactive({
  visible: false,
  title: '',
  mode: 'create', // 'create' | 'edit'
  form: {
    name: '',
    type: 'team',
    parentLabel: '',
  },
  rules: {
    name: [
      { required: true, message: '请输入节点名称', trigger: 'blur' },
      { min: 1, max: 30, message: '节点名称长度在 1 到 30 个字符', trigger: 'blur' },
    ],
    type: [
      { required: true, message: '请选择节点类型', trigger: 'change' },
    ],
  },
  parentNode: null,
  targetNode: null,
})

const nodeFormRef = ref(null)

function openNodeDialog(mode, node) {
  nodeDialog.mode = mode
  nodeDialog.targetNode = node

  if (mode === 'create') {
    nodeDialog.parentNode = node
    nodeDialog.title = '新增子节点'
    nodeDialog.form.name = ''
    // 父节点为部门 → 默认创建团队；父节点为团队 → 默认创建人员
    nodeDialog.form.type = node.type === 'department' ? 'team' : 'user'
    nodeDialog.form.parentLabel = node.label
  } else if (mode === 'edit') {
    const parent = findParentInTree(currentTree.value, node.id)
    nodeDialog.parentNode = parent
    nodeDialog.title = '编辑节点'
    nodeDialog.form.name = node.label
    nodeDialog.form.type = node.type
    nodeDialog.form.parentLabel = parent ? parent.label : '根节点'
  }

  nodeDialog.visible = true
  nextTick(() => {
    nodeFormRef.value?.clearValidate()
  })
}

async function handleNodeDialogSubmit() {
  if (!nodeFormRef.value) return

  try {
    await nodeFormRef.value.validate()
  } catch {
    return
  }

  const { mode, targetNode, parentNode, form } = nodeDialog

  try {
    // await createOrgNode / updateOrgNode API call
    // 此处使用 Mock 操作

    if (mode === 'create') {
      const newNode = {
        id: generateId(),
        label: form.name,
        type: form.type,
        children: [],
        ...(form.type === 'user' ? {
          role: '',
          email: '',
          phone: '',
          assignedProjects: [],
        } : {}),
      }

      if (parentNode) {
        if (!parentNode.children) {
          parentNode.children = []
        }
        parentNode.children.push(newNode)
      } else {
        // 新增根节点
        currentTree.value.push(newNode)
      }

      ElMessage.success('节点创建成功')
    } else if (mode === 'edit') {
      targetNode.label = form.name
      // targetNode.type 不可修改（已在表单中禁用）
      ElMessage.success('节点更新成功')
    }

    nodeDialog.visible = false
    // 刷新选中节点的显示
    if (selectedNode.value && mode === 'edit' && selectedNode.value.id === targetNode.id) {
      selectedNode.value = targetNode
    }
  } catch (error) {
    ElMessage.error(mode === 'create' ? '节点创建失败' : '节点更新失败')
    console.error('handleNodeDialogSubmit error:', error)
  }
}

// ====================================================================
// 删除节点
// ====================================================================
async function handleDeleteNode(node) {
  if (!node) return

  const { childNodes, members } = countDescendants(node)
  const totalChildren = childNodes + members

  if (totalChildren > 0) {
    const parts = []
    if (childNodes > 0) parts.push(`${childNodes} 个子节点`)
    if (members > 0) parts.push(`${members} 名成员`)
    await ElMessageBox.alert(
      `该节点下有 ${parts.join('、')}，请先移除后再删除`,
      '无法删除',
      { confirmButtonText: '知道了', type: 'warning' },
    )
    return
  }

  try {
    await ElMessageBox.confirm(
      `确定要删除「${node.label}」吗？此操作不可撤销。`,
      '确认删除',
      {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning',
      },
    )

    // await deleteOrgNode(node.id)
    // Mock: 从父节点中移除
    const parentNode = findParentInTree(currentTree.value, node.id)
    if (parentNode) {
      removeChildFromNode(parentNode, node.id)
    } else {
      // 根节点 - 从树数据中移除
      const tree = currentTree.value
      const idx = tree.findIndex((n) => n.id === node.id)
      if (idx !== -1) tree.splice(idx, 1)
    }

    if (selectedNode.value && selectedNode.value.id === node.id) {
      clearSelection()
    }

    ElMessage.success('节点已删除')
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      ElMessage.error('删除节点失败')
      console.error('handleDeleteNode error:', error)
    }
  }
}

// ====================================================================
// 添加成员对话框
// ====================================================================
const addMemberDialog = reactive({
  visible: false,
  targetNode: null,
  selectedUserId: null,
  assignedRole: '',
})

const addMemberFormRef = ref(null)

function openAddMemberDialog() {
  if (!selectedNode.value) return
  if (selectedNode.value.type === 'user') {
    ElMessage.warning('用户节点不能添加成员')
    return
  }

  addMemberDialog.targetNode = selectedNode.value
  addMemberDialog.selectedUserId = null
  addMemberDialog.assignedRole = ''
  addMemberDialog.visible = true
}

async function handleAddMemberSubmit() {
  if (!addMemberDialog.targetNode) return
  if (!addMemberDialog.selectedUserId) {
    ElMessage.warning('请选择用户')
    return
  }
  if (!addMemberDialog.assignedRole) {
    ElMessage.warning('请选择角色')
    return
  }

  const pool = availableUsers.value
  const selectedUser = pool.find((u) => u.id === addMemberDialog.selectedUserId)
  if (!selectedUser) {
    ElMessage.error('未找到选中的用户')
    return
  }

  try {
    // await addOrgUser(addMemberDialog.targetNode.id, {
    //   user_id: selectedUser.id,
    //   role: addMemberDialog.assignedRole,
    // })

    // Mock: 将用户添加到目标节点下
    const newUser = {
      id: generateId(),
      label: selectedUser.label,
      type: 'user',
      role: addMemberDialog.assignedRole,
      email: selectedUser.email,
      phone: selectedUser.phone,
      assignedProjects: selectedUser.assignedProjects || [],
    }

    if (!addMemberDialog.targetNode.children) {
      addMemberDialog.targetNode.children = []
    }
    addMemberDialog.targetNode.children.push(newUser)

    // 从用户池中移除
    const idx = pool.findIndex((u) => u.id === selectedUser.id)
    if (idx !== -1) pool.splice(idx, 1)

    ElMessage.success(`已将「${selectedUser.label}」添加到「${addMemberDialog.targetNode.label}」`)
    addMemberDialog.visible = false
  } catch (error) {
    ElMessage.error('添加成员失败')
    console.error('handleAddMemberSubmit error:', error)
  }
}

// ====================================================================
// 移除成员
// ====================================================================
async function handleRemoveMember(member) {
  try {
    await ElMessageBox.confirm(
      `确定要从「${selectedNode.value.label}」中移除「${member.label}」吗？`,
      '确认移除',
      {
        confirmButtonText: '确定移除',
        cancelButtonText: '取消',
        type: 'warning',
      },
    )

    // await removeOrgUser(selectedNode.value.id, member.id)

    // Mock: 从当前节点移除用户
    if (selectedNode.value && selectedNode.value.children) {
      const idx = selectedNode.value.children.findIndex((c) => c.id === member.id)
      if (idx !== -1) {
        const [removed] = selectedNode.value.children.splice(idx, 1)
        // 将该用户放回用户池
        const pool = availableUsers.value
        pool.push({
          id: generateId(),
          label: removed.label,
          type: 'user',
          role: removed.role,
          email: removed.email || '',
          phone: removed.phone || '',
          assignedProjects: removed.assignedProjects || [],
        })
      }
    }

    ElMessage.success(`已移除「${member.label}」`)
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      ElMessage.error('移除成员失败')
      console.error('handleRemoveMember error:', error)
    }
  }
}

// ====================================================================
// 添加根节点
// ====================================================================
function handleAddRootNode() {
  const mockRootParent = { label: '根节点', type: 'root' }
  openNodeDialog('create', mockRootParent)
}

// ====================================================================
// 生命周期
// ====================================================================
onMounted(() => {
  document.addEventListener('click', handleGlobalClick)
})

onUnmounted(() => {
  document.removeEventListener('click', handleGlobalClick)
})
</script>

<template>
  <div class="page-container">
    <!-- 页面标题 -->
    <div class="page-header">
      <h2 class="page-title">组织架构</h2>
      <p class="page-description">管理内部IT团队、供应商团队及系统用户的组织架构</p>
    </div>

    <!-- Tab 切换 -->
    <div class="org-tabs">
      <el-radio-group v-model="activeTab" size="default">
        <el-radio-button value="internal_it">内部 IT 团队</el-radio-button>
        <el-radio-button value="supplier">供应商团队</el-radio-button>
        <el-radio-button value="system_user">系统用户</el-radio-button>
      </el-radio-group>
    </div>

    <!-- 组织架构主体布局 -->
    <div class="org-layout">
      <!-- ============================================ -->
      <!-- 左侧：组织树 -->
      <!-- ============================================ -->
      <div class="org-sidebar">
        <div class="org-tree-header">
          <el-input
            v-model="filterText"
            placeholder="输入关键字搜索节点"
            clearable
            :prefix-icon="Search"
            size="default"
          />
        </div>

        <div class="org-tree-body">
          <el-tree
            ref="treeRef"
            :data="treeData"
            node-key="id"
            default-expand-all
            highlight-current
            :filter-node-method="filterNode"
            :expand-on-click-node="false"
            @node-click="handleNodeClick"
            @node-contextmenu="handleContextMenu"
          >
            <template #default="{ data }">
              <span class="tree-node">
                <el-icon :size="16" :color="getNodeIconStyle(data.type).color">
                  <component :is="getNodeIconStyle(data.type).icon" />
                </el-icon>
                <span class="node-label">{{ data.label }}</span>
                <el-tag
                  v-if="data.role"
                  size="small"
                  class="node-role-tag"
                  type="info"
                >
                  {{ data.role }}
                </el-tag>
              </span>
            </template>
          </el-tree>
        </div>

        <div class="org-tree-footer">
          <el-button text size="small" :icon="Plus" @click="handleAddRootNode">
            新增根节点
          </el-button>
        </div>
      </div>

      <!-- ============================================ -->
      <!-- 上下文菜单 -->
      <!-- ============================================ -->
      <Teleport to="body">
        <div
          v-show="contextMenu.visible"
          class="context-menu"
          :style="contextMenuStyle"
          @click.stop
        >
          <div
            v-if="contextMenu.targetNode && contextMenu.targetNode.type !== 'user'"
            class="context-menu-item"
            @click="handleContextAddChild"
          >
            <el-icon :size="14"><CirclePlus /></el-icon>
            <span>新增子节点</span>
          </div>
          <div class="context-menu-item" @click="handleContextEdit">
            <el-icon :size="14"><Edit /></el-icon>
            <span>编辑</span>
          </div>
          <div class="context-menu-item danger" @click="handleContextDelete">
            <el-icon :size="14"><Delete /></el-icon>
            <span>删除</span>
          </div>
        </div>
      </Teleport>

      <!-- ============================================ -->
      <!-- 右侧：节点详情 -->
      <!-- ============================================ -->
      <div class="org-detail">
        <!-- 部门/团队 → 成员列表 -->
        <template v-if="selectedNode && isContainerNode">
          <el-card class="content-card" shadow="never">
            <template #header>
              <div class="card-header">
                <div class="card-header-left">
                  <el-icon :size="18" :color="getNodeIconStyle(selectedNode.type).color">
                    <component :is="getNodeIconStyle(selectedNode.type).icon" />
                  </el-icon>
                  <span class="card-title">{{ selectedNode.label }}</span>
                  <el-tag size="small" :type="selectedNode.type === 'department' ? 'success' : 'warning'">
                    {{ getNodeTypeLabel(selectedNode.type) }}
                  </el-tag>
                </div>
                <div class="card-header-right">
                  <el-button size="small" type="primary" :icon="Plus" @click="openAddMemberDialog">
                    添加成员
                  </el-button>
                  <el-button size="small" :icon="Edit" @click="openNodeDialog('edit', selectedNode)">
                    编辑
                  </el-button>
                  <el-button size="small" type="danger" :icon="Delete" @click="handleDeleteNode(selectedNode)">
                    删除
                  </el-button>
                </div>
              </div>
            </template>

            <el-table
              :data="currentMembers"
              size="default"
              stripe
              empty-text="暂无成员"
            >
              <el-table-column prop="label" label="姓名" min-width="100" />
              <el-table-column prop="role" label="角色" min-width="120">
                <template #default="{ row }">
                  <el-tag v-if="row.role" size="small" type="info">{{ row.role }}</el-tag>
                  <span v-else class="text-muted">-</span>
                </template>
              </el-table-column>
              <el-table-column prop="email" label="邮箱" min-width="180" show-overflow-tooltip>
                <template #default="{ row }">
                  <span v-if="row.email">{{ row.email }}</span>
                  <span v-else class="text-muted">-</span>
                </template>
              </el-table-column>
              <el-table-column prop="phone" label="电话" min-width="130">
                <template #default="{ row }">
                  <span v-if="row.phone">{{ row.phone }}</span>
                  <span v-else class="text-muted">-</span>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="80" fixed="right">
                <template #default="{ row }">
                  <el-button
                    text
                    size="small"
                    type="danger"
                    :icon="Remove"
                    @click="handleRemoveMember(row)"
                  >
                    移除
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
          </el-card>
        </template>

        <!-- 用户 → 详细信息 -->
        <template v-else-if="selectedNode && selectedNode.type === 'user'">
          <el-card class="content-card" shadow="never">
            <template #header>
              <div class="card-header">
                <div class="card-header-left">
                  <el-icon :size="18" color="#409EFF"><UserFilled /></el-icon>
                  <span class="card-title">{{ selectedNode.label }}</span>
                  <el-tag size="small" type="primary">{{ getNodeTypeLabel(selectedNode.type) }}</el-tag>
                </div>
                <div class="card-header-right">
                  <el-button size="small" :icon="Edit" @click="openNodeDialog('edit', selectedNode)">
                    编辑
                  </el-button>
                  <el-button size="small" type="danger" :icon="Delete" @click="handleDeleteNode(selectedNode)">
                    删除
                  </el-button>
                </div>
              </div>
            </template>

            <el-descriptions :column="2" border size="default">
              <el-descriptions-item label="姓名" :span="1">
                {{ selectedNode.label }}
              </el-descriptions-item>
              <el-descriptions-item label="角色" :span="1">
                <el-tag v-if="selectedNode.role" size="small">{{ selectedNode.role }}</el-tag>
                <span v-else class="text-muted">-</span>
              </el-descriptions-item>
              <el-descriptions-item label="邮箱" :span="1">
                {{ selectedNode.email || '-' }}
              </el-descriptions-item>
              <el-descriptions-item label="电话" :span="1">
                {{ selectedNode.phone || '-' }}
              </el-descriptions-item>
              <el-descriptions-item label="关联项目" :span="2">
                <template v-if="selectedNode.assignedProjects && selectedNode.assignedProjects.length > 0">
                  <el-tag
                    v-for="project in selectedNode.assignedProjects"
                    :key="project"
                    size="small"
                    type="success"
                    class="project-tag"
                  >
                    {{ project }}
                  </el-tag>
                </template>
                <span v-else class="text-muted">暂未关联项目</span>
              </el-descriptions-item>
              <el-descriptions-item label="所属团队" :span="2">
                {{
                  findParentInTree(treeData, selectedNode.id)?.label || '-'
                }}
              </el-descriptions-item>
            </el-descriptions>
          </el-card>
        </template>

        <!-- 未选中节点 -->
        <template v-else>
          <div class="empty-placeholder">
            <el-icon :size="56" class="empty-icon"><OfficeBuilding /></el-icon>
            <p class="empty-text">请选择左侧组织树的节点</p>
            <p class="empty-hint">左键点击节点查看详细信息，右键点击节点打开操作菜单</p>
          </div>
        </template>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- 新增/编辑节点对话框 -->
    <!-- ============================================ -->
    <el-dialog
      v-model="nodeDialog.visible"
      :title="nodeDialog.title"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form
        ref="nodeFormRef"
        :model="nodeDialog.form"
        :rules="nodeDialog.rules"
        label-width="100px"
        label-position="right"
      >
        <el-form-item label="父节点">
          <el-input
            :model-value="nodeDialog.form.parentLabel || '根节点'"
            disabled
          />
        </el-form-item>
        <el-form-item label="节点名称" prop="name">
          <el-input
            v-model="nodeDialog.form.name"
            placeholder="请输入节点名称"
            maxlength="30"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="节点类型" prop="type">
          <el-select
            v-model="nodeDialog.form.type"
            placeholder="请选择节点类型"
            :disabled="nodeDialog.mode === 'edit'"
            style="width: 100%"
          >
            <el-option label="部门" value="department" />
            <el-option label="团队" value="team" />
            <el-option label="人员" value="user" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="nodeDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="handleNodeDialogSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- ============================================ -->
    <!-- 添加成员对话框 -->
    <!-- ============================================ -->
    <el-dialog
      v-model="addMemberDialog.visible"
      title="添加成员"
      width="460px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form
        ref="addMemberFormRef"
        label-width="100px"
        label-position="right"
      >
        <el-form-item label="目标节点">
          <el-input
            :model-value="addMemberDialog.targetNode?.label || ''"
            disabled
          />
        </el-form-item>
        <el-form-item label="选择用户" required>
          <el-select
            v-model="addMemberDialog.selectedUserId"
            filterable
            placeholder="请输入姓名搜索用户"
            style="width: 100%"
          >
            <el-option
              v-for="user in availableUsers"
              :key="user.id"
              :label="`${user.label}（${user.role || '未分配角色'}）`"
              :value="user.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="分配角色" required>
          <el-select
            v-model="addMemberDialog.assignedRole"
            filterable
            allow-create
            placeholder="请选择或输入角色"
            style="width: 100%"
          >
            <el-option
              v-for="role in availableRoles"
              :key="role"
              :label="role"
              :value="role"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="addMemberDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="handleAddMemberSubmit">确定添加</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// ====================================================================
// 页面级变量
// ====================================================================
$sidebar-width: 300px;
$context-menu-width: 150px;

// ====================================================================
// Tab 区域
// ====================================================================
.org-tabs {
  background: #fff;
  padding: 12px 16px;
  border-radius: $border-radius-md;
  margin-bottom: 16px;
}

// ====================================================================
// 主布局：左树 + 右详情
// ====================================================================
.org-layout {
  display: flex;
  gap: 16px;
  min-height: 560px;
  align-items: flex-start;
}

// ====================================================================
// 左侧组织树面板
// ====================================================================
.org-sidebar {
  width: $sidebar-width;
  flex-shrink: 0;
  background: #fff;
  border-radius: $border-radius-md;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 240px);
}

.org-tree-header {
  padding: 12px;
  border-bottom: 1px solid $gray-200;
}

.org-tree-body {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0;

  :deep(.el-tree) {
    background: transparent;
    padding: 0 8px;

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
    }
  }
}

.org-tree-footer {
  padding: 8px 12px;
  border-top: 1px solid $gray-200;
}

// ====================================================================
// 树节点样式
// ====================================================================
.tree-node {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: $font-size-body;
  flex: 1;
  overflow: hidden;

  .node-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .node-role-tag {
    flex-shrink: 0;
    font-size: 11px;
  }
}

// ====================================================================
// 上下文菜单
// ====================================================================
.context-menu {
  position: fixed;
  z-index: 3000;
  background: #fff;
  border: 1px solid $gray-300;
  border-radius: $border-radius-md;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
  padding: 4px 0;
  min-width: $context-menu-width;

  .context-menu-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: $font-size-body;
    color: $gray-900;
    transition: background-color 0.15s ease;

    &:hover {
      background-color: $gray-100;
    }

    &.danger {
      color: $color-danger;

      &:hover {
        background-color: #fef0f0;
      }
    }
  }
}

// ====================================================================
// 右侧详情面板
// ====================================================================
.org-detail {
  flex: 1;
  min-width: 0;
}

.content-card {
  :deep(.el-card__header) {
    padding: 12px 16px;
    border-bottom: 1px solid $gray-200;
  }

  :deep(.el-card__body) {
    padding: 16px;
  }
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}

.card-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-header-right {
  display: flex;
  align-items: center;
  gap: 6px;
}

.card-title {
  font-size: $font-size-h3;
  font-weight: 600;
  color: $gray-900;
}

// ====================================================================
// 空状态占位
// ====================================================================
.empty-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  background: #fff;
  border-radius: $border-radius-md;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  text-align: center;

  .empty-icon {
    color: $gray-300;
    margin-bottom: 16px;
  }

  .empty-text {
    font-size: $font-size-h3;
    color: $gray-700;
    margin: 0 0 8px;
  }

  .empty-hint {
    font-size: $font-size-body;
    color: $gray-500;
    margin: 0;
  }
}

// ====================================================================
// 通用样式
// ====================================================================
.text-muted {
  color: $gray-500;
}

.project-tag {
  margin-right: 6px;
  margin-bottom: 4px;
}

// ====================================================================
// 响应式
// ====================================================================
@media (max-width: 900px) {
  .org-layout {
    flex-direction: column;
  }

  .org-sidebar {
    width: 100%;
    max-height: 360px;
  }
}
</style>
