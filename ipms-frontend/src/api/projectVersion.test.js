import { beforeEach, describe, expect, it, vi } from 'vitest'

const request = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('./index', () => ({ default: request }))

import {
  checkProjectVersionGate,
  createProjectVersion,
  deleteProjectVersion,
  getProjectVersion,
  listProjectVersionHistory,
  listProjectVersions,
  listUnplannedRequirements,
  planRequirementVersion,
  releaseProjectVersion,
  transitionProjectVersion,
  unplanRequirementVersion,
  updateProjectVersion,
} from './projectVersion'

describe('project version API contract', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('uses the exact project-version collection and item endpoints', () => {
    const filters = {
      page: 2,
      page_size: 25,
      status: 4,
      owner_id: 7,
      planned_release_from: '2026-09-01',
      planned_release_to: '2026-09-30',
      keyword: 'R2',
    }

    listProjectVersions(12, filters)
    createProjectVersion(12, { code: 'R2.1' })
    getProjectVersion(31)
    updateProjectVersion(31, { name: 'Release 2.1', lock_version: 3 })
    deleteProjectVersion(31, { lock_version: 3 })

    expect(request.get).toHaveBeenNthCalledWith(1, '/projects/12/versions', { params: filters })
    expect(request.post).toHaveBeenCalledWith('/projects/12/versions', { code: 'R2.1' })
    expect(request.get).toHaveBeenNthCalledWith(2, '/project-versions/31')
    expect(request.put).toHaveBeenCalledWith('/project-versions/31', {
      name: 'Release 2.1',
      lock_version: 3,
    })
    expect(request.delete).toHaveBeenCalledWith('/project-versions/31', {
      data: { lock_version: 3 },
    })
  })

  it('uses the exact status, gate, release, history, plan, and unplan endpoints', () => {
    transitionProjectVersion(31, { status: 4, lock_version: 3 })
    checkProjectVersionGate(31, { target_status: 5 })
    releaseProjectVersion(31, { lock_version: 3, release_notes: 'Ready' })
    listProjectVersionHistory(31, { page: 1, page_size: 20 })
    planRequirementVersion(81, 12, {
      project_version_id: 31,
      lock_version: 3,
    })
    unplanRequirementVersion(81, 12, {
      project_version_id: 31,
      lock_version: 4,
      reason: 'Replan',
    })

    expect(request.post).toHaveBeenNthCalledWith(
      1,
      '/project-versions/31/status',
      { status: 4, lock_version: 3 },
    )
    expect(request.get).toHaveBeenNthCalledWith(
      1,
      '/project-versions/31/gate-check',
      { params: { target_status: 5 } },
    )
    expect(request.post).toHaveBeenNthCalledWith(
      2,
      '/project-versions/31/release',
      { lock_version: 3, release_notes: 'Ready' },
    )
    expect(request.get).toHaveBeenNthCalledWith(
      2,
      '/project-versions/31/history',
      { params: { page: 1, page_size: 20 } },
    )
    expect(request.put).toHaveBeenCalledWith(
      '/requirements/81/projects/12/version',
      { project_version_id: 31, lock_version: 3 },
    )
    expect(request.delete).toHaveBeenCalledWith(
      '/requirements/81/projects/12/version',
      {
        data: {
          project_version_id: 31,
          lock_version: 4,
          reason: 'Replan',
        },
      },
    )
  })

  it('requests the unplanned pool with normalized pagination parameters', () => {
    listUnplannedRequirements(12, {
      page: 3,
      page_size: 10,
      keyword: 'invoice',
    })

    expect(request.get).toHaveBeenCalledWith('/requirements', {
      params: {
        project_id: 12,
        version_scope: 'unplanned',
        page: 3,
        page_size: 10,
        keyword: 'invoice',
      },
    })
  })
})
