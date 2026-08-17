import request from './index'

/**
 * 文档 API 模块
 */

/**
 * 获取文档列表
 * @param {Number|String} projectId
 * @param {Object} params - { folder_id, keyword, page, pageSize }
 */
export function listDocuments(projectId, params) {
  return request.get(`/projects/${projectId}/documents`, { params })
}

/**
 * 上传文件
 * @param {Number|String} projectId
 * @param {Object} data - FormData { file, folder_id }
 */
export function uploadDocument(projectId, data) {
  return request.post(`/projects/${projectId}/documents/upload`, data, {
    headers: { 'Content-Type': 'multipart/form-data' }
  })
}

/**
 * 创建文件夹
 * @param {Number|String} projectId
 * @param {Object} data - { name, parent_id }
 */
export function createFolder(projectId, data) {
  return request.post(`/projects/${projectId}/documents/folder`, data)
}

/**
 * 下载文件
 * @param {Number|String} id
 */
export function downloadDocument(id) {
  return request.get(`/documents/${id}/download`, {
    responseType: 'blob'
  })
}

/**
 * 删除文档（移入回收站）
 * @param {Number|String} id
 */
export function deleteDocument(id) {
  return request.delete(`/documents/${id}`)
}
