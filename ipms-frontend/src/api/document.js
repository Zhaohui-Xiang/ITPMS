import request from './index'

/**
 * 文档 API 模块
 */

/**
 * 获取文档列表
 * @param {Object} params - { project_id, folder_id, keyword, page, pageSize }
 */
export function listDocuments(params) {
  return request.get('/documents', { params })
}

/**
 * 获取文档目录树
 * @param {Object} params - { project_id }
 */
export function getDocumentTree(params) {
  return request.get('/documents/tree', { params })
}

/**
 * 上传文件
 * @param {Object} data - FormData { file, project_id, folder_id }
 */
export function uploadDocument(data) {
  return request.post('/documents/upload', data, {
    headers: { 'Content-Type': 'multipart/form-data' }
  })
}

/**
 * 创建文件夹
 * @param {Object} data - { name, project_id, parent_id }
 */
export function createFolder(data) {
  return request.post('/documents/folder', data)
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

/**
 * 获取回收站文档列表
 * @param {Object} params - { project_id }
 */
export function listTrashDocuments(params) {
  return request.get('/documents/trash', { params })
}

/**
 * 从回收站恢复文档
 * @param {Number|String} id
 */
export function restoreDocument(id) {
  return request.put(`/documents/${id}/restore`)
}

/**
 * 永久删除文档
 * @param {Number|String} id
 */
export function forceDeleteDocument(id) {
  return request.delete(`/documents/${id}/force`)
}
