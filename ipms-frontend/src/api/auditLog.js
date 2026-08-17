import request from './index'

/**
 * 操作日志 API 模块
 */

/**
 * 获取操作日志列表
 * @param {Object} params - {
 *   page, pageSize,
 *   start_date, end_date,
 *   operator, module, action_type, project_id
 * }
 */
export function listAuditLogs(params) {
  return request.get('/audit-logs', { params })
}

/**
 * 获取操作日志详情
 * @param {Number|String} id
 */
export function getAuditLog(id) {
  return request.get(`/audit-logs/${id}`)
}

/**
 * 导出操作日志（CSV）
 * @param {Object} params - 筛选参数同 list
 */
export function exportAuditLogs(params) {
  return request.get('/audit-logs/export', {
    params,
    responseType: 'blob'
  })
}
