import request from './index'

/**
 * 接口文档 API 模块
 */

/**
 * 获取接口文档列表
 * @param {Object} params - { project_id, folder_id, keyword, page, pageSize }
 */
export function listApiDocuments(params) {
  return request.get('/api-documents', { params })
}

/**
 * 获取接口文档详情
 * @param {Number|String} id
 */
export function getApiDocument(id) {
  return request.get(`/api-documents/${id}`)
}

/**
 * 创建接口文档
 * @param {Object} data - {
 *   name, path, method, project_id, folder_id, requirement_id,
 *   request_params: [{ name, type, required, description }],
 *   response_params: [{ name, type, description }],
 *   auth_type, request_example, response_example, notes
 * }
 */
export function createApiDocument(data) {
  return request.post('/api-documents', data)
}

/**
 * 更新接口文档
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateApiDocument(id, data) {
  return request.put(`/api-documents/${id}`, data)
}

/**
 * 获取接口文档版本历史
 * @param {Number|String} id
 */
export function getApiDocumentVersions(id) {
  return request.get(`/api-documents/${id}/versions`)
}

/**
 * 导出接口文档
 * @param {Number|String} id
 * @param {String} format - 'json' | 'markdown' | 'html'
 */
export function exportApiDocument(id, format = 'json') {
  return request.get(`/api-documents/${id}/export`, {
    params: { format },
    responseType: format === 'json' ? 'json' : 'blob'
  })
}
