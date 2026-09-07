import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const MENU_ITEMS = [
  { index: '/dashboard', title: '工作台', icon: 'HomeFilled' },
  { index: '/projects', title: '项目管理', icon: 'Folder' },
  { index: '/requirements', title: '需求管理', icon: 'Document' },
  { index: '/tasks', title: '任务管理', icon: 'List' },
  { index: '/defects', title: '缺陷管理', icon: 'Warning' },
  { index: '/documents', title: '文档管理', icon: 'Files' },
  { index: '/audit-logs', title: '审计日志', icon: 'Tickets' },
  { index: '/organizations', title: '组织架构', icon: 'OfficeBuilding' },
]

const ROLE_MENU_PATHS = {
  requester: ['/dashboard', '/requirements', '/defects'],
  supplier_dev: ['/dashboard', '/tasks', '/defects', '/documents'],
  supplier_tester: ['/dashboard', '/tasks', '/defects', '/documents'],
  supplier_pm: ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents'],
  it_member: ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents'],
  it_pm: ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents', '/audit-logs'],
  super_admin: MENU_ITEMS.map((item) => item.index),
}

const CREATE_MODULE_ROLES = {
  requirement: ['requester', 'it_member', 'it_pm'],
  task: ['supplier_pm', 'it_member', 'it_pm'],
  defect: ['requester', 'supplier_tester', 'supplier_pm', 'it_member', 'it_pm'],
  document: ['supplier_dev', 'supplier_tester', 'supplier_pm', 'it_member', 'it_pm'],
}

const EDIT_MODULE_ROLES = {
  requirement: ['requester', 'it_member', 'it_pm'],
  task: ['supplier_pm', 'it_member', 'it_pm'],
  defect: ['supplier_tester', 'supplier_pm', 'it_member'],
  document: ['supplier_pm', 'it_member', 'it_pm'],
}

export function usePermission() {
  const authStore = useAuthStore()

  const role = computed(() => authStore.currentRole)
  const roles = computed(() => authStore.roles ?? [])
  const userType = computed(() => authStore.userType)
  const isSuperAdmin = computed(() => authStore.isSuperAdmin)

  const visibleMenuItems = computed(() => {
    const activeRoles = isSuperAdmin.value ? ['super_admin'] : roles.value
    const visiblePaths = new Set(
      activeRoles.flatMap((roleCode) => ROLE_MENU_PATHS[roleCode] ?? []),
    )

    return MENU_ITEMS.filter((item) => visiblePaths.has(item.index))
  })

  function hasAnyRole(...roleCodes) {
    return isSuperAdmin.value || roleCodes.some((roleCode) => roles.value.includes(roleCode))
  }

  function hasModulePermission(permissionMap, module) {
    return isSuperAdmin.value || (permissionMap[module] ?? []).some((roleCode) => roles.value.includes(roleCode))
  }

  function canCreate(module) {
    return hasModulePermission(CREATE_MODULE_ROLES, module)
  }

  function canEdit(module) {
    return hasModulePermission(EDIT_MODULE_ROLES, module)
  }

  function canDelete() {
    return isSuperAdmin.value
  }

  function canPerform(resource, action, localAllowed = true) {
    return Boolean(
      localAllowed
      && Array.isArray(resource?.allowed_actions)
      && resource.allowed_actions.includes(action),
    )
  }

  return {
    role,
    roles,
    userType,
    isSuperAdmin,
    visibleMenuItems,
    hasAnyRole,
    canCreate,
    canEdit,
    canDelete,
    canPerform,
  }
}
