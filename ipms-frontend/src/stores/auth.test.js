import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'

const { csrfGet, mockFetchUser, mockLogin, mockLogout, mockPush, requestPost } = vi.hoisted(() => ({
  csrfGet: vi.fn(),
  mockFetchUser: vi.fn(),
  mockLogin: vi.fn(),
  mockLogout: vi.fn(),
  mockPush: vi.fn(),
  requestPost: vi.fn(),
}))

vi.mock('axios', async (importActual) => {
  const axios = await importActual()
  return { default: Object.assign(axios.default, { get: csrfGet }) }
})
vi.mock('@/api/index', () => ({ default: { post: requestPost } }))
vi.mock('@/api/auth', () => ({
  login: mockLogin,
  logout: mockLogout,
  fetchUser: mockFetchUser,
}))
vi.mock('@/router', () => ({ default: { push: mockPush } }))

const makeUser = (overrides = {}) => ({
  id: 1,
  display_name: '张三',
  roles: ['it_pm'],
  permissions: ['requirements.view'],
  user_type: 'internal',
  ...overrides,
})

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockLogin.mockResolvedValue({ data: { data: { user: makeUser() } } })
    mockLogout.mockResolvedValue({ data: { data: null } })
    mockFetchUser.mockResolvedValue({ data: { data: { user: makeUser() } } })
    localStorage.clear()
  })

  it('initializes a same-origin credentialed session before login', async () => {
    const authApi = await vi.importActual('@/api/auth')
    const credentials = { username: 'pm@example.test', password: 'Secret123' }

    await authApi.login(credentials)

    expect(csrfGet).toHaveBeenCalledWith('/sanctum/csrf-cookie', { withCredentials: true })
    expect(requestPost).toHaveBeenCalledWith('/login', credentials)
  })

  it('configures the real Axios client for credentials without bearer authorization', async () => {
    const { default: service } = await vi.importActual('@/api/index')

    expect(service.defaults.withCredentials).toBe(true)
    expect(service.defaults.headers.common).not.toHaveProperty('Authorization')
    expect(service.defaults.headers.common).not.toHaveProperty('authorization')
    expect(JSON.stringify(service.defaults.headers)).not.toContain('Bearer')
  })

  it('stores the session user without localStorage token data', async () => {
    const tokenWriter = vi.spyOn(Storage.prototype, 'setItem')
    const store = useAuthStore()

    await store.login({ username: 'pm@example.test', password: 'Secret123' })

    expect(store.userName).toBe('张三')
    expect(localStorage.getItem('ipms_token')).toBeNull()
    expect(tokenWriter).not.toHaveBeenCalled()
    tokenWriter.mockRestore()
  })

  it('returns the Task 3 login payload wrapper used by LoginView', async () => {
    const store = useAuthStore()
    const user = makeUser({ must_change_password: true })
    mockLogin.mockResolvedValueOnce({ data: { data: { user } } })

    await expect(store.login({ username: 'pm@example.test', password: 'Secret123' }))
      .resolves.toEqual({ data: { user } })
  })

  it('exposes user getters and role checks from the session user', async () => {
    const store = useAuthStore()
    await store.login({ username: 'pm@example.test', password: 'Secret123' })

    expect(store.isAuthenticated).toBe(true)
    expect(store.roles).toEqual(['it_pm'])
    expect(store.currentRole).toBe('it_pm')
    expect(store.permissions).toEqual(['requirements.view'])
    expect(store.userType).toBe('internal')
    expect(store.isSuperAdmin).toBe(false)
    expect(store.mustChangePassword).toBe(false)
    expect(store.hasPermission('requirements.view')).toBe(true)
    expect(store.hasRole('it_pm')).toBe(true)
    expect(store.isUserType('internal')).toBe(true)
  })

  it('updates the session from fetchUser and clears it when fetching fails', async () => {
    const store = useAuthStore()
    const response = { data: { data: { user: makeUser({ display_name: '李四' }) } } }
    mockFetchUser.mockResolvedValueOnce(response)

    await expect(store.fetchUser()).resolves.toBe(response)
    expect(store.userName).toBe('李四')

    const error = new Error('Unauthorized')
    mockFetchUser.mockRejectedValueOnce(error)

    await expect(store.fetchUser()).rejects.toThrow(error)
    expect(store.user).toBeNull()
  })

  it('clears the session and returns to login when logout fails', async () => {
    const store = useAuthStore()
    await store.login({ username: 'pm@example.test', password: 'Secret123' })
    mockLogout.mockRejectedValueOnce(new Error('Network error'))

    await store.logout()

    expect(mockLogout).toHaveBeenCalledOnce()
    expect(store.user).toBeNull()
    expect(mockPush).toHaveBeenCalledWith('/login')
  })
})
