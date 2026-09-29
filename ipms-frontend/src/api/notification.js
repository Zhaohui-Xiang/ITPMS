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
 * @param {Object} data - { remind_enabled, remind_days_before }
 */
export function updateNotificationConfig(data) {
  return request.put('/notification-configs', data)
}

/**
 * 获取通知日志
 * @param {Object} params - { notification_type, status, page_size }
 */
export function listNotificationLogs(params) {
  return request.get('/notification-logs', { params })
}

/**
 * 站内通知收件箱
 * @param {Object} params - { page, page_size }
 */
export function listInbox(params) {
  return request.get('/notifications', { params })
}

/**
 * 未读通知数
 */
export function getUnreadCount() {
  return request.get('/notifications/unread-count')
}

/**
 * 标记单条已读
 * @param {Number|String} id
 */
export function markNotificationRead(id) {
  return request.post(`/notifications/${id}/read`)
}

/**
 * 全部标记已读
 */
export function markAllNotificationsRead() {
  return request.post('/notifications/read-all')
}
