import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as apiLogin, logout as apiLogout, fetchUser as apiFetchUser } from '@/api/auth'
import router from '@/router'

const ROLE_PRIORITY = [
  'super_admin',
  'it_pm',
  'it_member',
  'supplier_pm',
  'supplier_tester',
  'supplier_dev',
  'requester',
]

export const useAuthStore = defineStore('auth', () => {
  // ======= State =======
  const user = ref(null)
  const isAuthenticated = computed(() => !!user.value)

  // ======= Getters =======
  const roles = computed(() => user.value?.roles ?? [])
  const currentRole = computed(() => ROLE_PRIORITY.find((role) => roles.value.includes(role)) ?? 'guest')
  const permissions = computed(() => user.value?.permissions ?? [])
  const userName = computed(() => user.value?.display_name || user.value?.username || '')
  const userType = computed(() => user.value?.user_type ?? null)
  const isSuperAdmin = computed(() => user.value?.is_super_admin === true || roles.value.includes('super_admin'))
  const mustChangePassword = computed(() => user.value?.must_change_password === true)

  // ======= Actions =======
  async function login(credentials) {
    const response = await apiLogin(credentials)
    const payload = response.data.data
    user.value = payload.user
    return { data: payload }
  }

  async function logout() {
    try {
      await apiLogout()
    } catch (e) {
      // 即使后端登出失败，也清除前端状态
    } finally {
      user.value = null
      router.push('/login')
    }
  }

  async function fetchUser() {
    try {
      const response = await apiFetchUser()
      user.value = response.data.data.user
      return response
    } catch (error) {
      user.value = null
      throw error
    }
  }

  // 检查用户是否有某个权限
  function hasPermission(permission) {
    if (!permission) return true
    if (isSuperAdmin.value) return true
    return permissions.value.includes(permission)
  }

  function hasRole(code) {
    return roles.value.includes(code)
  }

  // 检查是否是某种用户类型
  function isUserType(type) {
    return userType.value === type
  }

  return {
    // State
    user,
    isAuthenticated,
    // Getters
    roles,
    currentRole,
    permissions,
    userName,
    userType,
    isSuperAdmin,
    mustChangePassword,
    // Actions
    login,
    logout,
    fetchUser,
    hasPermission,
    hasRole,
    isUserType
  }
})
