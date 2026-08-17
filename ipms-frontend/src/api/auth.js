import request from './index'

/**
 * 登录
 * @param {Object} credentials - { username, password }
 */
export function login(credentials) {
  return request.post('/login', credentials)
}

/**
 * 登出
 */
export function logout() {
  return request.post('/logout')
}

/**
 * 获取当前用户信息
 */
export function fetchUser() {
  return request.get('/user')
}

/**
 * 修改密码
 * @param {Object} data - { current_password, new_password, new_password_confirmation }
 */
export function changePassword(data) {
  return request.put('/user/password', data)
}

/**
 * 获取 CSRF Cookie (Sanctum SPA)
 */
export function getCsrfCookie() {
  return request.get('/sanctum/csrf-cookie')
}
