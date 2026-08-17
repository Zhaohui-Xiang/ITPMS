import request from './index'

/**
 * 通知 API 模块
 */

/**
 * 获取通知配置
 */
export function getNotificationConfig() {
  return request.get('/notifications/config')
}

/**
 * 更新通知配置
 * @param {Object} data - { remind_enabled, daily_remind_time, default_remind_days, smtp_*, sender_address }
 */
export function updateNotificationConfig(data) {
  return request.put('/notifications/config', data)
}

/**
 * 获取通知日志
 * @param {Object} params - { page, pageSize, type, start_date, end_date }
 */
export function listNotificationLogs(params) {
  return request.get('/notifications/logs', { params })
}

/**
 * 获取未读通知数
 */
export function getUnreadCount() {
  return request.get('/notifications/unread-count')
}

/**
 * 获取通知列表（用户可见）
 * @param {Object} params - { page, pageSize }
 */
export function listMyNotifications(params) {
  return request.get('/notifications', { params })
}

/**
 * 标记通知为已读
 * @param {Number|String} id
 */
export function markNotificationRead(id) {
  return request.put(`/notifications/${id}/read`)
}

/**
 * 标记所有通知为已读
 */
export function markAllNotificationsRead() {
  return request.put('/notifications/read-all')
}

/**
 * 发送测试邮件
 * @param {Object} data - { email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption }
 */
export function sendTestEmail(data) {
  return request.post('/notifications/test-email', data)
}
