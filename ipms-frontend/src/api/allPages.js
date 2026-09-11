export async function allPages(list, filters = {}) {
  const items = []
  let page = 1
  let lastPage = 1
  do {
    const { data } = await list({ ...filters, page, page_size: 100 })
    if (!Array.isArray(data.data?.items)) throw new Error('列表响应格式不正确')
    items.push(...data.data.items)
    lastPage = data.data.total_pages
    page++
  } while (page <= lastPage)
  return items
}
