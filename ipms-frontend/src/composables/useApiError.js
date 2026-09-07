import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const ERROR_BEHAVIORS = {
  STALE_VERSION: { requiresReload: true },
  RELEASE_GATE_FAILED: { targetTab: 'release-gate' },
  VERSION_LOCKED: { readOnly: true },
  FORBIDDEN: { forbidden: true },
  UNAUTHENTICATED: { unauthenticated: true },
}

function normalizeBlockers(errors) {
  if (Array.isArray(errors)) return errors
  if (!errors || typeof errors !== 'object') return []

  return Object.entries(errors).flatMap(([field, messages]) => {
    const values = Array.isArray(messages) ? messages : [messages]
    return values.map((message) => ({ field, message }))
  })
}

export function mapApiError(error) {
  const response = error?.response
  const data = response?.data ?? {}
  const code = data.error_code ?? 'UNKNOWN_ERROR'
  const behavior = ERROR_BEHAVIORS[code] ?? {}

  return {
    code,
    status: response?.status ?? null,
    message: data.message ?? error?.message ?? '请求失败',
    fieldErrors: data.errors ?? {},
    blockers: code === 'RELEASE_GATE_FAILED' ? normalizeBlockers(data.errors) : [],
    requiresReload: false,
    targetTab: null,
    readOnly: false,
    forbidden: false,
    unauthenticated: false,
    ...behavior,
  }
}

export function useApiError(dependencies = {}) {
  const authStore = dependencies.authStore ?? useAuthStore()
  const router = dependencies.router ?? useRouter()
  const lastError = ref(null)

  function handleApiError(error) {
    const mapped = mapApiError(error)
    lastError.value = mapped

    if (mapped.unauthenticated) {
      authStore.user = null
      router.replace('/login')
    }

    return mapped
  }

  function clearApiError() {
    lastError.value = null
  }

  return {
    lastError,
    handleApiError,
    clearApiError,
  }
}
