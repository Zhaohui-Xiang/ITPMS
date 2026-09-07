import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { usePermission } from './usePermission'

describe('usePermission', () => {
  let auth

  beforeEach(() => {
    setActivePinia(createPinia())
    auth = useAuthStore()
  })

  it.each([
    ['requester', ['/dashboard', '/requirements', '/defects']],
    ['supplier_dev', ['/dashboard', '/tasks', '/defects', '/documents']],
    ['supplier_tester', ['/dashboard', '/tasks', '/defects', '/documents']],
    ['supplier_pm', ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents']],
    ['it_member', ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents']],
    ['it_pm', ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents', '/audit-logs']],
  ])('shows the expected menu for %s', (role, paths) => {
    auth.user = {
      roles: [role],
      user_type: role === 'requester' ? 3 : role.startsWith('supplier_') ? 2 : 1,
    }

    expect(usePermission().visibleMenuItems.value.map((item) => item.index)).toEqual(paths)
  })

  it('shows organization and audit administration to super_admin without dead links', () => {
    auth.user = {
      roles: ['super_admin'],
      user_type: 1,
      is_super_admin: true,
    }

    const paths = usePermission().visibleMenuItems.value.map((item) => item.index)

    expect(paths).toContain('/organizations')
    expect(paths).toContain('/audit-logs')
    expect(paths).not.toContain('/profile')
    expect(paths).not.toContain('/settings')
  })

  it.each([
    ['requester', 3],
    ['supplier_pm', 2],
    ['it_pm', 1],
  ])('never exposes profile or settings menu links to %s', (role, userType) => {
    auth.user = { roles: [role], user_type: userType }

    const paths = usePermission().visibleMenuItems.value.map((item) => item.index)

    expect(paths).not.toContain('/profile')
    expect(paths).not.toContain('/settings')
  })

  it('requires both the local check and server allowed_actions for state changes', () => {
    auth.user = { roles: ['it_pm'], user_type: 1 }
    const { canPerform } = usePermission()
    const resource = { allowed_actions: ['archive'] }

    expect(canPerform(resource, 'archive', true)).toBe(true)
    expect(canPerform(resource, 'archive', false)).toBe(false)
    expect(canPerform(resource, 'delete', true)).toBe(false)
  })

  it.each([
    ['requester', 3, ['requirement', 'defect'], ['requirement']],
    ['supplier_dev', 2, ['document'], []],
    ['supplier_tester', 2, ['defect', 'document'], ['defect']],
    ['supplier_pm', 2, ['task', 'defect', 'document'], ['task', 'defect', 'document']],
    ['it_member', 1, ['requirement', 'task', 'defect', 'document'], ['requirement', 'task', 'defect', 'document']],
    ['it_pm', 1, ['requirement', 'task', 'defect', 'document'], ['requirement', 'task', 'document']],
  ])('matches backend create and edit permissions for %s', (role, userType, creatable, editable) => {
    auth.user = { roles: [role], user_type: userType }
    const { canCreate, canEdit } = usePermission()
    const modules = ['project', 'requirement', 'task', 'defect', 'document']

    expect(modules.filter(canCreate)).toEqual(creatable)
    expect(modules.filter(canEdit)).toEqual(editable)
  })

  it('reserves project mutation and deletion for super administrators', () => {
    auth.user = { roles: ['super_admin'], user_type: 1, is_super_admin: true }
    const { canCreate, canEdit, canDelete } = usePermission()

    expect(canCreate('project')).toBe(true)
    expect(canEdit('project')).toBe(true)
    expect(canDelete('project')).toBe(true)
  })
})
