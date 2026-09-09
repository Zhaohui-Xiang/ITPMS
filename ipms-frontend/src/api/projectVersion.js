import request from './index'

export function listProjectVersions(projectId, params = {}) {
  return request.get(`/projects/${projectId}/versions`, { params })
}

export function createProjectVersion(projectId, data) {
  return request.post(`/projects/${projectId}/versions`, data)
}

export function getProjectVersion(id) {
  return request.get(`/project-versions/${id}`)
}

export function updateProjectVersion(id, data) {
  return request.put(`/project-versions/${id}`, data)
}

export function deleteProjectVersion(id, data) {
  return request.delete(`/project-versions/${id}`, { data })
}

export function transitionProjectVersion(id, data) {
  return request.post(`/project-versions/${id}/status`, data)
}

export function checkProjectVersionGate(id, params = {}) {
  return request.get(`/project-versions/${id}/gate-check`, { params })
}

export function releaseProjectVersion(id, data) {
  return request.post(`/project-versions/${id}/release`, data)
}

export function listProjectVersionHistory(id, params = {}) {
  return request.get(`/project-versions/${id}/history`, { params })
}

export function planRequirementVersion(requirementId, projectId, data) {
  return request.put(
    `/requirements/${requirementId}/projects/${projectId}/version`,
    data,
  )
}

export function unplanRequirementVersion(requirementId, projectId, data) {
  return request.delete(
    `/requirements/${requirementId}/projects/${projectId}/version`,
    { data },
  )
}

export function listUnplannedRequirements(projectId, params = {}) {
  return request.get('/requirements', {
    params: {
      project_id: projectId,
      version_scope: 'unplanned',
      ...params,
    },
  })
}
