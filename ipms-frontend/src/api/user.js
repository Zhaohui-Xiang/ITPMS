import request from './index'

/**
 * 用户 API 模块
 */

/**
 * 获取用户列表
 * @param {Object} params - { page, pageSize, keyword, user_type, role, status }
 */
export function listUsers(params) {
  return request.get('/users', { params })
}

/**
 * 创建用户
 * @param {Object} data - { username, password, name, email, user_type, role, org_node_id }
 */
export function createUser(data) {
  return request.post('/users', data)
}

/**
 * 获取用户详情
 * @param {Number|String} id
 */
export function getUser(id) {
  return request.get(`/users/${id}`)
}

/**
 * 更新用户
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateUser(id, data) {
  return request.put(`/users/${id}`, data)
}

/**
 * 禁用用户
 * @param {Number|String} id
 */
export function disableUser(id) {
  return request.post(`/users/${id}/disable`)
}

/**
 * 更新个人信息
 * @param {Object} data - { name, email }
 */
export function updateProfile(data) {
  return request.put('/settings/profile', data)
}

/**
 * 修改密码
 * @param {Object} data - { current_password, new_password, new_password_confirmation }
 */
export function changePassword(data) {
  return request.put('/settings/password', data)
}
