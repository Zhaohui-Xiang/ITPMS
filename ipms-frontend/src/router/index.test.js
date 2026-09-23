import { describe, expect, it } from 'vitest'
import router from './index'

function flatten(routes) {
  return routes.flatMap((route) => [route, ...flatten(route.children ?? [])])
}

describe('operations shell routes', () => {
  it('places authenticated application routes under AppLayout', () => {
    const shell = router.options.routes.find((route) => route.name === 'OperationsShell')
    const childPaths = shell?.children?.map((route) => route.path) ?? []

    expect(shell).toBeDefined()
    expect(childPaths).toEqual(expect.arrayContaining([
      'dashboard',
      'projects',
      'requirements',
      'tasks',
      'defects',
      'documents',
      'audit-logs',
      'organizations',
    ]))
  })

  it('removes invalid settings and profile routes', () => {
    const paths = flatten(router.options.routes).map((route) => route.path)

    expect(paths).not.toContain('/settings')
    expect(paths).not.toContain('/profile')
    expect(paths).not.toContain('settings')
    expect(paths).not.toContain('profile')
  })

  it('serves project versions as a project child page without a top-level menu item', () => {
    const shell = router.options.routes.find((route) => route.name === 'OperationsShell')
    const versions = shell.children.find((route) => route.name === 'ProjectVersions')

    expect(versions.path).toBe('projects/:projectId/versions')
    expect(typeof versions.component).toBe('function')
  })

  it('only matches numeric requirement ids so /requirements/create falls through to 404', () => {
    expect(router.resolve('/requirements/42').name).toBe('RequirementDetail')
    expect(router.resolve('/requirements/create').name).toBe('NotFound')
  })

  it('serves task detail only for numeric ids', () => {
    expect(router.resolve('/tasks/5').name).toBe('TaskDetail')
    expect(router.resolve('/tasks/create').name).toBe('NotFound')
  })

  it('serves defect detail only for numeric ids', () => {
    expect(router.resolve('/defects/7').name).toBe('DefectDetail')
    expect(router.resolve('/defects/create').name).toBe('NotFound')
  })

  it('serves the project version release workspace inside the operations shell', () => {
    const shell = router.options.routes.find((route) => route.name === 'OperationsShell')
    const detail = shell.children.find((route) => route.name === 'ProjectVersionDetail')

    expect(detail.path).toBe('project-versions/:id')
    expect(typeof detail.component).toBe('function')
    expect(detail.meta.title).toBe('版本详情')
  })
})
