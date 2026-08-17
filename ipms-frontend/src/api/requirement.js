import request from './index'

/**
 * 需求 API 模块
 */

/**
 * 获取需求列表
 * @param {Object} params - { page, pageSize, keyword, status, project_id, priority, type }
 */
export function listRequirements(params) {
  return request.get('/requirements', { params })
}

/**
 * 获取需求详情
 * @param {Number|String} id
 */
export function getRequirement(id) {
  return request.get(`/requirements/${id}`)
}

/**
 * 创建需求
 * @param {Object} data - { title, description, project_ids, priority, req_type, expected_date, attachments }
 */
export function createRequirement(data) {
  return request.post('/requirements', data)
}

/**
 * 更新需求
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateRequirement(id, data) {
  return request.put(`/requirements/${id}`, data)
}

/**
 * 审核需求
 * @param {Number|String} id
 * @param {Object} data - { action: 'approve'|'reject', comment: reject 时必填 }
 */
export function reviewRequirement(id, data) {
  return request.post(`/requirements/${id}/review`, data)
}

/**
 * 需求状态流转
 * @param {Number|String} id
 * @param {Object} data - { status: 2|3|4|5|6|7 }
 */
export function transitionRequirement(id, data) {
  return request.post(`/requirements/${id}/status`, data)
}

/**
 * 获取需求版本历史
 * @param {Number|String} id
 */
export function getRequirementVersions(id) {
  return request.get(`/requirements/${id}/versions`)
}
