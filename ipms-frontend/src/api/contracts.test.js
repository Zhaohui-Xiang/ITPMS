import { beforeEach, describe, expect, it, vi } from 'vitest'
import request from './index'
import * as projectApi from './project'
import * as requirementApi from './requirement'
import * as taskApi from './task'
import * as defectApi from './defect'
import * as documentApi from './document'
import * as apiDocumentApi from './apiDocument'
import * as notificationApi from './notification'
import * as userApi from './user'
import * as dashboardApi from './dashboard'

vi.mock('./index', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

describe('frontend API contracts', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it.each([
    ['project archive', () => projectApi.archiveProject(4), ['/projects/4/archive']],
    [
      'requirement review',
      () => requirementApi.reviewRequirement(5, { action: 'approve' }),
      ['/requirements/5/review', { action: 'approve' }],
    ],
    [
      'requirement transition',
      () => requirementApi.transitionRequirement(5, { status: 3 }),
      ['/requirements/5/status', { status: 3 }],
    ],
    ['task claim', () => taskApi.claimTask(6), ['/tasks/6/claim']],
    [
      'task transition',
      () => taskApi.transitionTask(6, { status: 2 }),
      ['/tasks/6/status', { status: 2 }],
    ],
    ['task hold', () => taskApi.holdTask(6, { reason: 'blocked' }), ['/tasks/6/hold', { reason: 'blocked' }]],
    ['defect confirm', () => defectApi.confirmDefect(7, {}), ['/defects/7/confirm', {}]],
    ['defect assign', () => defectApi.assignDefect(7, { assignee_id: 8 }), ['/defects/7/assign', { assignee_id: 8 }]],
    [
      'defect resolve',
      () => defectApi.resolveDefect(7, { fix_description: 'fixed' }),
      ['/defects/7/resolve', { fix_description: 'fixed' }],
    ],
    ['defect verify', () => defectApi.verifyDefect(7, { result: 'pass' }), ['/defects/7/verify', { result: 'pass' }]],
    ['defect reopen', () => defectApi.reopenDefect(7, { reason: 'regression' }), ['/defects/7/reopen', { reason: 'regression' }]],
    ['API document export', () => apiDocumentApi.exportApiDocument(9, 'json'), ['/api-docs/9/export', { format: 'json' }]],
    ['user disable', () => userApi.disableUser(10), ['/users/10/disable']],
  ])('uses the exact POST contract for %s', (_name, invoke, expectedArgs) => {
    invoke()

    expect(request.post).toHaveBeenCalledTimes(1)
    expect(request.post).toHaveBeenCalledWith(...expectedArgs)
    expect(request.get).not.toHaveBeenCalled()
    expect(request.put).not.toHaveBeenCalled()
    expect(request.delete).not.toHaveBeenCalled()
  })

  it('scopes document operations to their project', () => {
    const upload = new FormData()
    const folder = { name: 'Designs', parent_id: null }

    documentApi.listDocuments(8, { page: 1 })
    documentApi.uploadDocument(8, upload)
    documentApi.createFolder(8, folder)
    documentApi.downloadDocument(11)
    documentApi.deleteDocument(11)

    expect(request.get).toHaveBeenCalledWith('/projects/8/documents', { params: { page: 1 } })
    expect(request.post).toHaveBeenCalledWith(
      '/projects/8/documents/upload',
      upload,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    expect(request.post).toHaveBeenCalledWith('/projects/8/documents/folder', folder)
    expect(request.get).toHaveBeenCalledWith('/documents/11/download', { responseType: 'blob' })
    expect(request.delete).toHaveBeenCalledWith('/documents/11')
  })

  it('uses project-scoped API document collections and standalone details', () => {
    const data = { api_name: 'Create requirement', request_path: '/requirements', request_method: 'POST' }

    apiDocumentApi.listApiDocuments(8, { page: 1 })
    apiDocumentApi.createApiDocument(8, data)
    apiDocumentApi.getApiDocument(9)
    apiDocumentApi.updateApiDocument(9, data)
    apiDocumentApi.getApiDocumentVersions(9)

    expect(request.get).toHaveBeenCalledWith('/projects/8/api-docs', { params: { page: 1 } })
    expect(request.post).toHaveBeenCalledWith('/projects/8/api-docs', data)
    expect(request.get).toHaveBeenCalledWith('/api-docs/9')
    expect(request.put).toHaveBeenCalledWith('/api-docs/9', data)
    expect(request.get).toHaveBeenCalledWith('/api-docs/9/versions')
  })

  it('uses notification and current-user settings routes', () => {
    const config = { remind_enabled: true }
    const profile = { display_name: '张三' }
    const password = {
      current_password: 'old',
      new_password: 'new-password',
      new_password_confirmation: 'new-password',
    }

    notificationApi.getNotificationConfig()
    notificationApi.updateNotificationConfig(config)
    notificationApi.listNotificationLogs({ page: 1 })
    userApi.updateProfile(profile)
    userApi.changePassword(password)

    expect(request.get).toHaveBeenCalledWith('/notification-configs')
    expect(request.put).toHaveBeenCalledWith('/notification-configs', config)
    expect(request.get).toHaveBeenCalledWith('/notification-logs', { params: { page: 1 } })
    expect(request.put).toHaveBeenCalledWith('/settings/profile', profile)
    expect(request.put).toHaveBeenCalledWith('/settings/password', password)
  })

  it('does not expose methods without backend routes', () => {
    expect(projectApi).not.toHaveProperty('checkProjectDeletable')
    expect(requirementApi).not.toHaveProperty('getRequirementTasks')
    expect(requirementApi).not.toHaveProperty('getRequirementDefects')
    expect(taskApi).not.toHaveProperty('listMyTasks')
    expect(documentApi).not.toHaveProperty('getDocumentTree')
    expect(documentApi).not.toHaveProperty('listTrashDocuments')
    expect(documentApi).not.toHaveProperty('restoreDocument')
    expect(documentApi).not.toHaveProperty('forceDeleteDocument')
    expect(userApi).not.toHaveProperty('enableUser')
    expect(userApi).not.toHaveProperty('getUserProfile')
    expect(notificationApi).not.toHaveProperty('getUnreadCount')
    expect(notificationApi).not.toHaveProperty('listMyNotifications')
    expect(notificationApi).not.toHaveProperty('markNotificationRead')
    expect(notificationApi).not.toHaveProperty('markAllNotificationsRead')
    expect(notificationApi).not.toHaveProperty('sendTestEmail')
    expect(dashboardApi).not.toHaveProperty('getRecentActivities')
    expect(dashboardApi).not.toHaveProperty('getProjectOverview')
  })
})
