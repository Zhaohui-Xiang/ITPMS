import request from './index'

/**
 * 仪表盘 API 模块
 */

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

/**
 * 获取近期动态
 * @param {Object} params - { limit }
 */
export function getRecentActivities(params) {
  return request.get('/dashboard/recent-activities', { params })
}

/**
 * 获取项目概览（项目进度等）
 */
export function getProjectOverview() {
  return request.get('/dashboard/project-overview')
}
