import request from './index'

export function listAssigneeOptions(projectId, type) {
  return request.get(`/projects/${projectId}/assignee-options`, { params: { type } })
}
