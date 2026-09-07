<script setup>
import { ref, reactive } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Lock, User } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import BrandMark from '@/components/layout/BrandMark.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const loginFormRef = ref(null)
const loading = ref(false)
const showPassword = ref(false)

const loginForm = reactive({
  username: '',
  password: '',
  remember: false
})

const loginRules = {
  username: [
    { required: true, message: '请输入账号', trigger: 'blur' },
    { min: 2, max: 50, message: '账号长度在 2 到 50 个字符', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' }
  ]
}

// 登录
async function handleLogin() {
  if (!loginFormRef.value) return

  try {
    await loginFormRef.value.validate()
  } catch {
    return
  }

  loading.value = true
  try {
    const response = await authStore.login({
      username: loginForm.username,
      password: loginForm.password
    })

    const userData = response.data?.user || response.data
    if (userData?.must_change_password) {
      ElMessage.warning('首次登录，请先修改密码')
    } else {
      ElMessage.success('登录成功')
    }

    const redirect = route.query.redirect || '/dashboard'
    router.push(redirect)
  } catch (error) {
    const status = error.response?.status
    const message = error.response?.data?.message

    if (status === 401) {
      ElMessage.error('账号或密码错误，请重试')
    } else if (status === 423) {
      ElMessage.error('账号已被锁定，请15分钟后再试')
    } else if (status === 403) {
      ElMessage.error('账号已被禁用，请联系系统管理员')
    } else {
      ElMessage.error(message || '登录失败，请检查网络连接')
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <!-- Logo + 标题 -->
      <div class="login-header">
        <BrandMark />
        <h1 class="login-title">IT 项目管理系统</h1>
      </div>

      <!-- 登录表单 -->
      <el-form
        ref="loginFormRef"
        :model="loginForm"
        :rules="loginRules"
        class="login-form"
        @keyup.enter="handleLogin"
      >
        <el-form-item prop="username">
          <el-input
            v-model="loginForm.username"
            placeholder="请输入账号"
            :prefix-icon="User"
            size="large"
            clearable
            :disabled="loading"
            autocomplete="username"
          />
        </el-form-item>

        <el-form-item prop="password">
          <el-input
            v-model="loginForm.password"
            :type="showPassword ? 'text' : 'password'"
            placeholder="请输入密码"
            :prefix-icon="Lock"
            size="large"
            :disabled="loading"
            autocomplete="current-password"
          >
            <template #suffix>
              <el-icon
                class="password-toggle"
                @click="showPassword = !showPassword"
              >
                <component :is="showPassword ? 'View' : 'Hide'" />
              </el-icon>
            </template>
          </el-input>
        </el-form-item>

        <el-form-item>
          <el-checkbox v-model="loginForm.remember">记住账号</el-checkbox>
        </el-form-item>

        <el-form-item>
          <el-button
            type="primary"
            size="large"
            :loading="loading"
            class="login-btn"
            @click="handleLogin"
          >
            {{ loading ? '登录中...' : '登  录' }}
          </el-button>
        </el-form-item>
      </el-form>

      <div class="login-footer">
        <span>忘记密码？请联系系统管理员</span>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.login-page {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: $color-canvas;
  overflow: hidden;
}

.login-card {
  position: relative;
  width: 420px;
  background: #fff;
  border: 1px solid $color-border;
  border-radius: $border-radius-md;
  padding: 44px 40px 36px;
  box-shadow: 0 12px 32px rgba(23, 33, 43, 0.1);
  z-index: 1;
}

.login-header {
  text-align: center;
  margin-bottom: 36px;

  :deep(.brand-mark) {
    margin: 0 auto 18px;
  }

  .login-title {
    color: $color-ink;
    font-size: $font-size-h2;
    font-weight: 600;
  }
}

.login-form {
  :deep(.el-input--large) {
    height: 46px;

    .el-input__wrapper {
      border-radius: 8px;
      box-shadow: 0 0 0 1px $gray-300 inset;
      padding: 1px 12px;

      &:hover {
        box-shadow: 0 0 0 1px $gray-500 inset;
      }

      &.is-focus {
        box-shadow: 0 0 0 1px $color-primary inset;
      }
    }
  }

  :deep(.el-form-item) {
    margin-bottom: 22px;
  }

  :deep(.el-form-item__error) {
    font-size: 12px;
  }

  .password-toggle {
    cursor: pointer;
    color: $gray-500;
    font-size: 16px;
    transition: color 0.2s;

    &:hover {
      color: $gray-700;
    }
  }
}

.login-btn {
  width: 100%;
  height: 46px;
  font-size: 16px;
  font-weight: 500;
  letter-spacing: 0;
  border-radius: 8px;
  background: $color-primary;
  border-color: $color-primary;

  &:hover {
    background: $color-primary-hover;
    border-color: $color-primary-hover;
  }
}

.login-footer {
  text-align: center;
  margin-top: 24px;
  font-size: $font-size-caption;
  color: $gray-500;
  line-height: 1.5;
}

// 响应式
@media (max-width: 480px) {
  .login-card {
    width: 92%;
    padding: 32px 24px 28px;
    border-radius: $border-radius-md;
  }

  .login-header .login-title {
    font-size: 20px;
  }
}
</style>
