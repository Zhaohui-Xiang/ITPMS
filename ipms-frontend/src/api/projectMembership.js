import request from './index'

export const listProjectMembers = id => request.get(`/projects/${id}/members`)
export const listProjectMemberOptions = id => request.get(`/projects/${id}/member-options`)
export const addProjectMember = (id, data) => request.post(`/projects/${id}/members`, data)
export const removeProjectMember = (id, userId) => request.delete(`/projects/${id}/members/${userId}`)
