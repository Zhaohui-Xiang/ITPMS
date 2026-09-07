import { computed, ref } from 'vue'

export function usePagination(options = {}) {
  const page = ref(options.page ?? 1)
  const pageSize = ref(options.pageSize ?? 20)
  const total = ref(0)
  const totalPages = ref(0)

  const requestParams = computed(() => ({
    page: page.value,
    page_size: pageSize.value,
  }))

  function applyPagination(payload = {}) {
    page.value = payload.page ?? page.value
    pageSize.value = payload.page_size ?? pageSize.value
    total.value = payload.total ?? 0
    totalPages.value = payload.total_pages ?? 0
  }

  function setPage(nextPage) {
    page.value = nextPage
  }

  function setPageSize(nextPageSize) {
    pageSize.value = nextPageSize
    page.value = 1
  }

  function resetPage() {
    page.value = 1
  }

  return {
    page,
    pageSize,
    total,
    totalPages,
    requestParams,
    applyPagination,
    setPage,
    setPageSize,
    resetPage,
  }
}
