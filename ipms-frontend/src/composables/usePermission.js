import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 权限校验组合式函数
 * 提供菜单可见性和操作权限的响应式判断
 */
export function usePermission() {
  const authStore = useAuthStore()

  const role = computed(() => authStore.currentRole)
  const userType = computed(() => authStore.userType)
  const isSuperAdmin = computed(() => authStore.isSuperAdmin)

  // ===== 菜单可见性 =====

  // 完整菜单配置
  const fullMenuItems = [
    { index: '/dashboard', title: '首页', icon: 'HomeFilled', minRole: 'member' },
    { index: '/projects', title: '项目管理', icon: 'Folder', minRole: 'member' },
    { index: '/requirements', title: '需求管理', icon: 'Document', minRole: 'member' },
    { index: '/tasks', title: '任务管理', icon: 'List', minRole: 'member' },
    { index: '/defects', title: '缺陷管理', icon: 'Warning', minRole: 'member' },
    { index: '/documents', title: '文档管理', icon: 'Files', minRole: 'member' },
    { index: '/audit-logs', title: '操作日志', icon: 'Tickets', minRole: 'project_manager' },
    { index: '/organizations', title: '组织架构', icon: 'OfficeBuilding', superAdminOnly: true },
    { index: '/settings', title: '系统设置', icon: 'Setting', superAdminOnly: true }
  ]

  // 根据用户角色过滤可见菜单
  const visibleMenuItems = computed(() => {
    return fullMenuItems.filter((item) => {
      // 超管拥有所有菜单
      if (isSuperAdmin.value) return true

      // 超管专属菜单
      if (item.superAdminOnly) return false

      // 按角色过滤
      if (item.minRole === 'project_manager') {
        // 操作日志仅内部IT项目经理及以上可见
        return authStore.isUserType('internal_it') && role.value !== 'member'
      }

      // 系统用户只能看有限菜单
      if (authStore.isUserType('system_user')) {
        return ['/dashboard', '/requirements', '/defects'].includes(item.index)
      }

      // 供应商开发人员/测试人员精简菜单
      if (authStore.isUserType('supplier')) {
        if (role.value === 'developer' || role.value === 'tester') {
          return ['/dashboard', '/tasks', '/defects', '/documents'].includes(item.index)
        }
        // 供应商项目经理
        if (role.value === 'project_manager') {
          return !['/audit-logs', '/organizations', '/settings'].includes(item.index)
        }
      }

      return true
    })
  })

  // ===== 操作权限 =====

  function canCreate(module) {
    if (isSuperAdmin.value) return true
    if (authStore.isUserType('system_user')) {
      return ['requirement', 'defect'].includes(module)
    }
    if (authStore.isUserType('supplier') && role.value !== 'project_manager') {
      return ['defect'].includes(module)
    }
    return authStore.isUserType('internal_it')
  }

  function canEdit(module) {
    if (isSuperAdmin.value) return true
    if (authStore.isUserType('system_user')) {
      return ['requirement'].includes(module)
    }
    return !authStore.isUserType('system_user')
  }

  function canDelete(module) {
    if (isSuperAdmin.value) return true
    return false
  }

  return {
    role,
    userType,
    isSuperAdmin,
    visibleMenuItems,
    canCreate,
    canEdit,
    canDelete
  }
}
