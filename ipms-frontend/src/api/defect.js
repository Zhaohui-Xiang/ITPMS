import request from './index'

/**
 * 缺陷 API 模块
 */

/**
 * 获取缺陷列表
 * @param {Object} params - { page, pageSize, keyword, status, severity, type, project_id }
 */
export function listDefects(params) {
  return request.get('/defects', { params })
}

/**
 * 获取缺陷详情
 * @param {Number|String} id
 */
export function getDefect(id) {
  return request.get(`/defects/${id}`)
}

/**
 * 创建缺陷
 * @param {Object} data - { title, description, requirement_id, severity, defect_type, found_stage, images }
 */
export function createDefect(data) {
  return request.post('/defects', data)
}

/**
 * 更新缺陷
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateDefect(id, data) {
  return request.put(`/defects/${id}`, data)
}

/**
 * 确认缺陷（待确认 -> 已确认）
 * @param {Number|String} id
 * @param {Object} data - { assignee_id, comment }
 */
export function confirmDefect(id, data) {
  return request.post(`/defects/${id}/confirm`, data)
}

/**
 * 指派修复人
 * @param {Number|String} id
 * @param {Object} data - { assignee_id }
 */
export function assignDefect(id, data) {
  return request.post(`/defects/${id}/assign`, data)
}

/**
 * 提交修复（修复中 -> 待复测）
 * @param {Number|String} id
 * @param {Object} data - { fix_description }
 */
export function resolveDefect(id, data) {
  return request.post(`/defects/${id}/resolve`, data)
}

/**
 * 复测验证
 * @param {Number|String} id
 * @param {Object} data - { result: 'pass'|'fail', comment }
 */
export function verifyDefect(id, data) {
  return request.post(`/defects/${id}/verify`, data)
}

/**
 * 重新打开缺陷
 * @param {Number|String} id
 * @param {Object} data - { reason }
 */
export function reopenDefect(id, data) {
  return request.post(`/defects/${id}/reopen`, data)
}
