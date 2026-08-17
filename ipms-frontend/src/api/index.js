import axios from 'axios'
import { ElMessage } from 'element-plus'

const service = axios.create({
  baseURL: '/api',
  timeout: 30000,
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }
})

// ===== 响应拦截器 =====
service.interceptors.response.use(
  (response) => {
    return response
  },
  (error) => {
    if (error.response) {
      const { status, data } = error.response

      switch (status) {
        case 401:
          // 使用 window.location 避免循环依赖 router
          if (window.location.pathname !== '/login') {
            ElMessage.error('登录已过期，请重新登录')
            window.location.href = '/login'
          }
          break

        case 403:
          ElMessage.error(data.message || '无权限访问此资源')
          break

        case 404:
          ElMessage.error('请求的资源不存在')
          break

        case 422:
          // 表单验证错误，由调用方处理
          break

        case 429:
          ElMessage.warning('请求过于频繁，请稍后再试')
          break

        case 500:
          ElMessage.error('服务器内部错误，请稍后重试')
          break

        default:
          ElMessage.error(data.message || '请求失败')
      }
    } else if (error.code === 'ECONNABORTED') {
      ElMessage.error('请求超时，请检查网络连接')
    } else {
      ElMessage.error('网络连接异常')
    }

    return Promise.reject(error)
  }
)

export default service
