import request from './index'

/**
 * 项目 API 模块
 * 所有函数返回 Promise
 */

/**
 * 获取项目列表
 * @param {Object} params - { page, pageSize, keyword, status, type }
 */
export function listRequirementProjectOptions(params) {
  return request.get('/requirements/project-options', { params })
}

export function listProjects(params) {
  return request.get('/projects', { params })
}

/**
 * 获取项目详情
 * @param {Number|String} id - 项目 ID
 */
export function getProject(id) {
  return request.get(`/projects/${id}`)
}

/**
 * 创建项目
 * @param {Object} data - { name, system_type, description, manager_id, supplier_id }
 */
export function createProject(data) {
  return request.post('/projects', data)
}

/**
 * 更新项目
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateProject(id, data) {
  return request.put(`/projects/${id}`, data)
}

/**
 * 删除项目
 * @param {Number|String} id
 */
export function deleteProject(id) {
  return request.delete(`/projects/${id}`)
}

/**
 * 归档项目
 * @param {Number|String} id
 */
export function archiveProject(id) {
  return request.post(`/projects/${id}/archive`)
}
