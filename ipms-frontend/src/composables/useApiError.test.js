import { describe, expect, it, vi } from 'vitest'
import { mapApiError, useApiError } from './useApiError'

const error = (errorCode, extra = {}) => ({
  response: {
    status: extra.status ?? 409,
    data: {
      error_code: errorCode,
      message: extra.message ?? 'server message',
      errors: extra.errors ?? [],
    },
  },
})

describe('useApiError', () => {
  it.each([
    ['STALE_VERSION', { requiresReload: true }],
    ['RELEASE_GATE_FAILED', { targetTab: 'release-gate' }],
    ['VERSION_LOCKED', { readOnly: true }],
    ['FORBIDDEN', { forbidden: true }],
    ['UNAUTHENTICATED', { unauthenticated: true }],
  ])('maps %s to its approved recovery behavior', (code, behavior) => {
    expect(mapApiError(error(code))).toMatchObject({ code, ...behavior })
  })

  it('keeps every release-gate blocker for presentation', () => {
    const blockers = [
      { code: 'OPEN_TASKS', message: '存在未完成任务' },
      { code: 'SEVERE_DEFECTS', message: '存在严重缺陷' },
    ]

    expect(mapApiError(error('RELEASE_GATE_FAILED', { errors: blockers })).blockers).toEqual(blockers)
  })

  it('clears local auth state and redirects to login for UNAUTHENTICATED', () => {
    const authStore = { user: { id: 1 } }
    const router = { replace: vi.fn() }
    const { handleApiError } = useApiError({ authStore, router })

    handleApiError(error('UNAUTHENTICATED', { status: 401 }))

    expect(authStore.user).toBeNull()
    expect(router.replace).toHaveBeenCalledWith('/login')
  })
})
