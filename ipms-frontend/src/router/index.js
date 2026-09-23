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
    name: 'OperationsShell',
    component: () => import('@/components/layout/AppLayout.vue'),
    redirect: '/dashboard',
    meta: { requiresAuth: true },
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/DashboardView.vue'),
        meta: { title: '工作台', icon: 'HomeFilled' },
      },
      {
        path: 'projects',
        name: 'ProjectList',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: { title: '项目管理', icon: 'Folder' },
      },
      {
        path: 'projects/:id',
        name: 'ProjectDetail',
        component: () => import('@/views/projects/ProjectDetail.vue'),
        meta: { title: '项目详情' },
      },
      {
        path: 'projects/:projectId/versions',
        name: 'ProjectVersions',
        component: () => import('@/views/projects/ProjectVersionsView.vue'),
        meta: { title: '发布版本' },
      },
      {
        path: 'project-versions/:id',
        name: 'ProjectVersionDetail',
        component: () => import('@/views/releases/ProjectVersionDetail.vue'),
        meta: { title: '版本详情' },
      },
      {
        path: 'requirements',
        name: 'RequirementList',
        component: () => import('@/views/requirements/RequirementList.vue'),
        meta: { title: '需求管理', icon: 'Document' },
      },
      {
        path: 'requirements/:id(\\d+)',
        name: 'RequirementDetail',
        component: () => import('@/views/requirements/RequirementDetail.vue'),
        meta: { title: '需求详情' },
      },
      {
        path: 'tasks',
        name: 'TaskList',
        component: () => import('@/views/tasks/TaskList.vue'),
        meta: { title: '任务管理', icon: 'List' },
      },
      {
        path: 'tasks/:id(\\d+)',
        name: 'TaskDetail',
        component: () => import('@/views/tasks/TaskDetail.vue'),
        meta: { title: '任务详情' },
      },
      {
        path: 'defects',
        name: 'DefectList',
        component: () => import('@/views/defects/DefectList.vue'),
        meta: { title: '缺陷管理', icon: 'Warning' },
      },
      {
        path: 'defects/:id(\\d+)',
        name: 'DefectDetail',
        component: () => import('@/views/defects/DefectDetail.vue'),
        meta: { title: '缺陷详情' },
      },
      {
        path: 'documents',
        name: 'DocumentView',
        component: () => import('@/views/documents/DocumentView.vue'),
        meta: { title: '文档管理', icon: 'Files' },
      },
      {
        path: 'audit-logs',
        name: 'AuditLogView',
        component: () => import('@/views/audit/AuditLogView.vue'),
        meta: { title: '审计日志', icon: 'Tickets' },
      },
      {
        path: 'organizations',
        name: 'OrganizationView',
        component: () => import('@/views/organizations/OrganizationView.vue'),
        meta: {
          title: '组织架构',
          icon: 'OfficeBuilding',
          requiresSuperAdmin: true,
        },
      },
    ],
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
  document.title = to.meta.title ? `${to.meta.title} - Voltage IPMS` : 'Voltage IPMS'

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

  if (authStore.mustChangePassword && to.name !== 'Dashboard') {
    return next('/dashboard')
  }

  // 检查超管权限
  if (to.meta.requiresSuperAdmin && !authStore.isSuperAdmin) {
    return next('/403')
  }

  next()
})

export default router
