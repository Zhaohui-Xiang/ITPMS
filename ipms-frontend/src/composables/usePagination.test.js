import { describe, expect, it } from 'vitest'
import { usePagination } from './usePagination'

describe('usePagination', () => {
  it('uses page_size in request parameters without adding pageSize', () => {
    const pagination = usePagination({ page: 2, pageSize: 25 })

    expect(pagination.requestParams.value).toEqual({ page: 2, page_size: 25 })
    expect(pagination.requestParams.value).not.toHaveProperty('pageSize')
  })

  it('maps the normalized server pagination response', () => {
    const pagination = usePagination()

    pagination.applyPagination({
      page: 3,
      page_size: 5,
      total: 14,
      total_pages: 3,
    })

    expect(pagination.page.value).toBe(3)
    expect(pagination.pageSize.value).toBe(5)
    expect(pagination.total.value).toBe(14)
    expect(pagination.totalPages.value).toBe(3)
    expect(pagination.requestParams.value).toEqual({ page: 3, page_size: 5 })
  })

  it('returns to the first page when page size changes', () => {
    const pagination = usePagination({ page: 4 })

    pagination.setPageSize(50)

    expect(pagination.page.value).toBe(1)
    expect(pagination.pageSize.value).toBe(50)
  })
})
