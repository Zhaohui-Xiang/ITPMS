import request from './index'

/**
 * 接口文档 API 模块
 */

/**
 * 获取接口文档列表
 * @param {Number|String} projectId
 * @param {Object} params - { folder_id, keyword, page, pageSize }
 */
export function listApiDocuments(projectId, params) {
  return request.get(`/projects/${projectId}/api-docs`, { params })
}

/**
 * 获取接口文档详情
 * @param {Number|String} id
 */
export function getApiDocument(id) {
  return request.get(`/api-docs/${id}`)
}

/**
 * 创建接口文档
 * @param {Number|String} projectId
 * @param {Object} data - {
 *   name, path, method, folder_id, requirement_id,
 *   request_params: [{ name, type, required, description }],
 *   response_params: [{ name, type, description }],
 *   auth_type, request_example, response_example, notes
 * }
 */
export function createApiDocument(projectId, data) {
  return request.post(`/projects/${projectId}/api-docs`, data)
}

/**
 * 更新接口文档
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateApiDocument(id, data) {
  return request.put(`/api-docs/${id}`, data)
}

/**
 * 获取接口文档版本历史
 * @param {Number|String} id
 */
export function getApiDocumentVersions(id) {
  return request.get(`/api-docs/${id}/versions`)
}

/**
 * 导出接口文档
 * @param {Number|String} id
 * @param {String} format - 'json' | 'markdown' | 'html'
 */
export function exportApiDocument(id, format = 'json') {
  return request.post(`/api-docs/${id}/export`, { format })
}
