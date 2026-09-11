import request from './index'

/**
 * 组织架构 API 模块
 */

/**
 * 获取组织架构树
 * @param {Object} params - { type: 'internal_it'|'supplier'|'system_user' }
 */
export function getOrgTree(params) {
  return request.get('/organizations', { params })
}

/**
 * 获取子节点列表
 * @param {Number|String} parentId
 * @param {Object} params - { type }
 */
export function getOrgChildren(parentId, params) {
  return request.get(`/organizations/${parentId}/children`, { params })
}

/**
 * 创建组织节点
 * @param {Object} data - { name, type, parent_id, org_type: 'internal_it'|'supplier'|'system_user' }
 */
export function createOrgNode(data) {
  return request.post('/organizations', data)
}

/**
 * 更新组织节点
 * @param {Number|String} id
 * @param {Object} data - { name }
 */
export function updateOrgNode(id, data) {
  return request.put(`/organizations/${id}`, data)
}

/**
 * 删除组织节点
 * @param {Number|String} id
 */
export function deleteOrgNode(id) {
  return request.delete(`/organizations/${id}`)
}

/**
 * 向组织节点添加用户
 * @param {Number|String} nodeId
 * @param {Object} data - { user_id, role }
 */
export function addOrgUser(nodeId, data) {
  return request.post(`/organizations/${nodeId}/users`, data)
}

/**
 * 从组织节点移除用户
 * @param {Number|String} nodeId
 * @param {Number|String} userId
 */
export function removeOrgUser(nodeId, userId) {
  return request.delete(`/organizations/${nodeId}/users/${userId}`)
}
