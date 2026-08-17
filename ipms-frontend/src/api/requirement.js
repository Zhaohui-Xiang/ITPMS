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
 * @param {Object} data - { action: 'approve'|'reject', comment, supplier_id, developer_id }
 */
export function reviewRequirement(id, data) {
  return request.put(`/requirements/${id}/review`, data)
}

/**
 * 需求状态流转
 * @param {Number|String} id
 * @param {Object} data - { action, comment }
 *  action: start_dev | complete_dev | pass_test | fail_test | confirm_online | confirm_accept
 */
export function transitionRequirement(id, data) {
  return request.put(`/requirements/${id}/status`, data)
}

/**
 * 获取需求版本历史
 * @param {Number|String} id
 */
export function getRequirementVersions(id) {
  return request.get(`/requirements/${id}/versions`)
}

/**
 * 获取需求子任务列表
 * @param {Number|String} id
 */
export function getRequirementTasks(id) {
  return request.get(`/requirements/${id}/tasks`)
}

/**
 * 获取需求关联缺陷
 * @param {Number|String} id
 */
export function getRequirementDefects(id) {
  return request.get(`/requirements/${id}/defects`)
}
