import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/login/LoginView.vue'),
    meta: { requiresAuth: false, title: '登录' }
  },
  {
    path: '/',
    redirect: '/dashboard',
    meta: { requiresAuth: true }
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: () => import('@/views/dashboard/DashboardView.vue'),
    meta: { requiresAuth: true, title: '首页', icon: 'HomeFilled' }
  },
  {
    path: '/projects',
    name: 'ProjectList',
    component: () => import('@/views/projects/ProjectList.vue'),
    meta: { requiresAuth: true, title: '项目管理', icon: 'Folder' }
  },
  {
    path: '/projects/:id',
    name: 'ProjectDetail',
    component: () => import('@/views/projects/ProjectDetail.vue'),
    meta: { requiresAuth: true, title: '项目详情' }
  },
  {
    path: '/requirements',
    name: 'RequirementList',
    component: () => import('@/views/requirements/RequirementList.vue'),
    meta: { requiresAuth: true, title: '需求管理', icon: 'Document' }
  },
  {
    path: '/requirements/:id',
    name: 'RequirementDetail',
    component: () => import('@/views/requirements/RequirementDetail.vue'),
    meta: { requiresAuth: true, title: '需求详情' }
  },
  {
    path: '/tasks',
    name: 'TaskList',
    component: () => import('@/views/tasks/TaskList.vue'),
    meta: { requiresAuth: true, title: '任务管理', icon: 'List' }
  },
  {
    path: '/defects',
    name: 'DefectList',
    component: () => import('@/views/defects/DefectList.vue'),
    meta: { requiresAuth: true, title: '缺陷管理', icon: 'Warning' }
  },
  {
    path: '/documents',
    name: 'DocumentView',
    component: () => import('@/views/documents/DocumentView.vue'),
    meta: { requiresAuth: true, title: '文档管理', icon: 'Files' }
  },
  {
    path: '/organizations',
    name: 'OrganizationView',
    component: () => import('@/views/organizations/OrganizationView.vue'),
    meta: {
      requiresAuth: true,
      title: '组织架构',
      icon: 'OfficeBuilding',
      requiresSuperAdmin: true
    }
  },
  {
    path: '/audit-logs',
    name: 'AuditLogView',
    component: () => import('@/views/audit/AuditLogView.vue'),
    meta: { requiresAuth: true, title: '操作日志', icon: 'Tickets' }
  },
  {
    path: '/settings',
    name: 'SettingsView',
    component: () => import('@/views/settings/SettingsView.vue'),
    meta: {
      requiresAuth: true,
      title: '系统设置',
      icon: 'Setting',
      requiresSuperAdmin: true
    }
  },
  // 错误页面路由
  {
    path: '/403',
    name: 'Forbidden',
    component: () => import('@/views/error/ForbiddenView.vue'),
    meta: { requiresAuth: false, title: '无权限' }
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: () => import('@/views/error/NotFoundView.vue'),
    meta: { requiresAuth: false, title: '页面未找到' }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 })
})

// ===== 全局导航守卫 =====
router.beforeEach(async (to, from, next) => {
  // 设置页面标题
  document.title = to.meta.title ? `${to.meta.title} - IPMS` : 'IPMS 项目管理系统'

  const authStore = useAuthStore()

  // 公开页面直接放行
  if (!to.meta.requiresAuth) {
    // 如果已登录用户访问登录页，重定向到首页
    if (to.path === '/login' && authStore.isAuthenticated) {
      return next('/dashboard')
    }
    return next()
  }

  // 需要认证的页面
  if (!authStore.isAuthenticated) {
    // 尝试从服务器获取用户信息
    try {
      await authStore.fetchUser()
    } catch (error) {
      // 未登录，跳转登录页
      return next({ path: '/login', query: { redirect: to.fullPath } })
    }
  }

  // 检查超管权限
  if (to.meta.requiresSuperAdmin && !authStore.isSuperAdmin) {
    return next('/403')
  }

  next()
})

export default router
