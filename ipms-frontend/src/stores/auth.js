import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as apiLogin, logout as apiLogout, fetchUser as apiFetchUser } from '@/api/auth'
import router from '@/router'

export const useAuthStore = defineStore('auth', () => {
  // ======= State =======
  const user = ref(null)
  const token = ref(localStorage.getItem('ipms_token') || null)
  const isAuthenticated = computed(() => !!user.value)

  // ======= Getters =======
  const currentRole = computed(() => user.value?.role || 'guest')

  const permissions = computed(() => user.value?.permissions || [])

  const userName = computed(() => {
    if (!user.value) return ''
    return user.value.name || user.value.username || ''
  })

  const userType = computed(() => user.value?.user_type || '')

  const isSuperAdmin = computed(() => currentRole.value === 'super_admin')

  // ======= Actions =======
  async function login(credentials) {
    const response = await apiLogin(credentials)
    const { user: userData, token: authToken } = response.data
    user.value = userData
    if (authToken) {
      token.value = authToken
      localStorage.setItem('ipms_token', authToken)
    }
    return response
  }

  async function logout() {
    try {
      await apiLogout()
    } catch (e) {
      // 即使后端登出失败，也清除前端状态
    } finally {
      user.value = null
      token.value = null
      localStorage.removeItem('ipms_token')
      router.push('/login')
    }
  }

  async function fetchUser() {
    try {
      const response = await apiFetchUser()
      user.value = response.data
      return response
    } catch (error) {
      user.value = null
      token.value = null
      localStorage.removeItem('ipms_token')
      throw error
    }
  }

  // 检查用户是否有某个权限
  function hasPermission(permission) {
    if (!permission) return true
    if (isSuperAdmin.value) return true
    return permissions.value.includes(permission)
  }

  // 检查是否是某种用户类型
  function isUserType(type) {
    return userType.value === type
  }

  return {
    // State
    user,
    token,
    isAuthenticated,
    // Getters
    currentRole,
    permissions,
    userName,
    userType,
    isSuperAdmin,
    // Actions
    login,
    logout,
    fetchUser,
    hasPermission,
    isUserType
  }
})
