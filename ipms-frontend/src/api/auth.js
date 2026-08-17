import axios from 'axios'
import request from './index'

/**
 * 登录
 * @param {Object} credentials - { username, password }
 */
export async function login(credentials) {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
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

export { changePassword } from './user'
