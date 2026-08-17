import request from './index'

/**
 * 任务 API 模块
 */

/**
 * 获取任务列表
 * @param {Object} params - { page, pageSize, status, project_id, priority, assignee_id, keyword }
 */
export function listTasks(params) {
  return request.get('/tasks', { params })
}

/**
 * 获取任务详情
 * @param {Number|String} id
 */
export function getTask(id) {
  return request.get(`/tasks/${id}`)
}

/**
 * 创建任务
 * @param {Object} data - { title, description, requirement_id, priority, assignee_id, due_date, remind_days }
 */
export function createTask(data) {
  return request.post('/tasks', data)
}

/**
 * 更新任务
 * @param {Number|String} id
 * @param {Object} data
 */
export function updateTask(id, data) {
  return request.put(`/tasks/${id}`, data)
}

/**
 * 认领任务
 * @param {Number|String} id
 */
export function claimTask(id) {
  return request.post(`/tasks/${id}/claim`)
}

/**
 * 任务状态流转
 * @param {Number|String} id
 * @param {Object} data - { action: 'start'|'complete'|'suspend'|'resume', comment }
 */
export function transitionTask(id, data) {
  return request.post(`/tasks/${id}/status`, data)
}

/**
 * 挂起任务
 * @param {Number|String} id
 * @param {Object} data - { reason }
 */
export function holdTask(id, data) {
  return request.post(`/tasks/${id}/hold`, data)
}
