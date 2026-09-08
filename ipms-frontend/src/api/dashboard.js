import request from './index'

/**
 * 仪表盘 API 模块
 */

/**
 * 获取当前用户角色范围内的工作台摘要
 */
export function getDashboardSummary() {
  return request.get('/dashboard/summary')
}

/**
 * 获取仪表盘统计数据
 */
export function getDashboardStats() {
  return request.get('/dashboard/stats')
}

/**
 * 获取近期任务列表
 * @param {Object} params - { limit }
 */
export function getRecentTasks(params) {
  return request.get('/dashboard/recent-tasks', { params })
}

/**
 * 获取近期需求列表
 * @param {Object} params - { limit }
 */
export function getRecentRequirements(params) {
  return request.get('/dashboard/recent-requirements', { params })
}
