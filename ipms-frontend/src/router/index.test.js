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
})
