import request from './index'

/**
 * 通知 API 模块
 */

/**
 * 获取通知配置
 */
export function getNotificationConfig() {
  return request.get('/notification-configs')
}

/**
 * 更新通知配置
 * @param {Object} data - { remind_enabled, daily_remind_time, default_remind_days, smtp_*, sender_address }
 */
export function updateNotificationConfig(data) {
  return request.put('/notification-configs', data)
}

/**
 * 获取通知日志
 * @param {Object} params - { page, pageSize, type, start_date, end_date }
 */
export function listNotificationLogs(params) {
  return request.get('/notification-logs', { params })
}
