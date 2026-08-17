<script setup>
import { ref, reactive } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { changePassword } from '@/api/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const loginFormRef = ref(null)
const passwordFormRef = ref(null)
const loading = ref(false)
const showPassword = ref(false)
const passwordChanging = ref(false)

// 首次登录修改密码弹窗
const showPasswordDialog = ref(false)
const showNewPassword = ref(false)
const showNewPasswordConfirm = ref(false)

const loginForm = reactive({
  username: '',
  password: '',
  remember: false
})

const passwordForm = reactive({
  currentPassword: '',
  newPassword: '',
  newPasswordConfirmation: ''
})

const loginRules = {
  username: [
    { required: true, message: '请输入账号', trigger: 'blur' },
    { min: 2, max: 50, message: '账号长度在 2 到 50 个字符', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, max: 32, message: '密码长度不能少于6位', trigger: 'blur' }
  ]
}

const passwordRules = {
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '密码长度至少 8 位', trigger: 'blur' },
    {
      pattern: /^(?=.*[A-Za-z])(?=.*\d).+$/,
      message: '密码必须包含字母和数字',
      trigger: 'blur'
    },
    {
      validator: (_rule, value, callback) => {
        if (value === passwordForm.currentPassword) {
          callback(new Error('新密码不能与当前密码相同'))
        }
        callback()
      },
      trigger: 'blur'
    }
  ],
  newPasswordConfirmation: [
    { required: true, message: '请确认新密码', trigger: 'blur' },
    {
      validator: (_rule, value, callback) => {
        if (value !== passwordForm.newPassword) {
          callback(new Error('两次输入的密码不一致'))
        }
        callback()
      },
      trigger: 'blur'
    }
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

    // 检查是否需要首次改密
    const userData = response.data?.user || response.data
    if (userData?.must_change_password) {
      passwordForm.currentPassword = loginForm.password
      showPasswordDialog.value = true
      ElMessage.warning('首次登录，请修改密码')
      return
    }

    ElMessage.success('登录成功')
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

// 修改密码并继续登录
async function handlePasswordChange() {
  if (!passwordFormRef.value) return

  try {
    await passwordFormRef.value.validate()
  } catch {
    return
  }

  passwordChanging.value = true
  try {
    await changePassword({
      current_password: passwordForm.currentPassword,
      new_password: passwordForm.newPassword,
      new_password_confirmation: passwordForm.newPasswordConfirmation
    })

    ElMessage.success('密码修改成功，正在进入系统...')
    showPasswordDialog.value = false

    // 短暂延迟后跳转
    setTimeout(() => {
      const redirect = route.query.redirect || '/dashboard'
      router.push(redirect)
    }, 800)
  } catch (error) {
    const message = error.response?.data?.message || '修改密码失败，请重试'
    ElMessage.error(message)
  } finally {
    passwordChanging.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <!-- 背景装饰 -->
    <div class="login-bg-decoration">
      <div class="bg-circle bg-circle-1"></div>
      <div class="bg-circle bg-circle-2"></div>
      <div class="bg-circle bg-circle-3"></div>
    </div>

    <div class="login-card">
      <!-- Logo + 标题 -->
      <div class="login-header">
        <div class="login-logo">
          <el-icon :size="40"><Platform /></el-icon>
        </div>
        <h1 class="login-title">IPMS 项目管理系统</h1>
        <p class="login-subtitle">项目需求开发管理平台</p>
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

    <!-- 首次登录改密弹窗 -->
    <el-dialog
      v-model="showPasswordDialog"
      title="首次登录 - 修改密码"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      :show-close="false"
      width="480px"
      class="password-dialog"
    >
      <div class="password-dialog-hint">
        <el-icon color="#E6A23C"><WarningFilled /></el-icon>
        <span>为保证账号安全，首次登录需修改密码</span>
      </div>

      <el-form
        ref="passwordFormRef"
        :model="passwordForm"
        :rules="passwordRules"
        label-width="0"
        class="password-form"
      >
        <el-form-item prop="newPassword">
          <el-input
            v-model="passwordForm.newPassword"
            :type="showNewPassword ? 'text' : 'password'"
            placeholder="请输入新密码（至少8位，含字母和数字）"
            :prefix-icon="Lock"
            size="large"
          >
            <template #suffix>
              <el-icon
                class="password-toggle"
                @click="showNewPassword = !showNewPassword"
              >
                <component :is="showNewPassword ? 'View' : 'Hide'" />
              </el-icon>
            </template>
          </el-input>
        </el-form-item>

        <el-form-item prop="newPasswordConfirmation">
          <el-input
            v-model="passwordForm.newPasswordConfirmation"
            :type="showNewPasswordConfirm ? 'text' : 'password'"
            placeholder="请再次输入新密码"
            :prefix-icon="Lock"
            size="large"
          >
            <template #suffix>
              <el-icon
                class="password-toggle"
                @click="showNewPasswordConfirm = !showNewPasswordConfirm"
              >
                <component :is="showNewPasswordConfirm ? 'View' : 'Hide'" />
              </el-icon>
            </template>
          </el-input>
        </el-form-item>

        <!-- 密码强度提示 -->
        <div class="password-strength">
          <span class="strength-label">密码要求：</span>
          <ul class="strength-rules">
            <li :class="{ met: passwordForm.newPassword.length >= 8 }">至少 8 个字符</li>
            <li :class="{ met: /[A-Za-z]/.test(passwordForm.newPassword) }">包含字母</li>
            <li :class="{ met: /\d/.test(passwordForm.newPassword) }">包含数字</li>
          </ul>
        </div>
      </el-form>

      <template #footer>
        <div class="dialog-footer">
          <el-button
            type="primary"
            size="large"
            :loading="passwordChanging"
            class="password-submit-btn"
            @click="handlePasswordChange"
          >
            {{ passwordChanging ? '修改中...' : '确认修改并登录' }}
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.login-page {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  overflow: hidden;
}

// 背景装饰圆形
.login-bg-decoration {
  position: absolute;
  inset: 0;
  pointer-events: none;

  .bg-circle {
    position: absolute;
    border-radius: 50%;
    opacity: 0.1;
    background: #fff;

    &.bg-circle-1 {
      width: 400px;
      height: 400px;
      top: -100px;
      right: -100px;
    }

    &.bg-circle-2 {
      width: 300px;
      height: 300px;
      bottom: -80px;
      left: -80px;
    }

    &.bg-circle-3 {
      width: 200px;
      height: 200px;
      top: 50%;
      left: 10%;
    }
  }
}

.login-card {
  position: relative;
  width: 420px;
  background: #fff;
  border-radius: $border-radius-lg;
  padding: 44px 40px 36px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
  z-index: 1;
}

.login-header {
  text-align: center;
  margin-bottom: 36px;

  .login-logo {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #667eea, #409EFF);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    margin: 0 auto 18px;
    box-shadow: 0 4px 12px rgba(64, 158, 255, 0.35);
  }

  .login-title {
    font-size: 24px;
    font-weight: 700;
    color: $gray-900;
    margin-bottom: 6px;
    letter-spacing: 2px;
  }

  .login-subtitle {
    font-size: $font-size-small;
    color: $gray-500;
    letter-spacing: 4px;
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
  letter-spacing: 6px;
  border-radius: 8px;
  background: linear-gradient(135deg, #667eea, #409EFF);
  border: none;

  &:hover {
    background: linear-gradient(135deg, #5a6fd6, #3a8ee6);
  }
}

.login-footer {
  text-align: center;
  margin-top: 24px;
  font-size: $font-size-caption;
  color: $gray-500;
  line-height: 1.5;
}

// 改密弹窗
.password-dialog {
  :deep(.el-dialog__header) {
    padding: 20px 24px 0;
  }

  :deep(.el-dialog__body) {
    padding: 20px 24px;
  }

  :deep(.el-dialog__footer) {
    padding: 0 24px 20px;
  }
}

.password-dialog-hint {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  background: #fef7e0;
  border-radius: 6px;
  margin-bottom: 20px;
  font-size: $font-size-small;
  color: #5f4b00;
}

.password-form {
  :deep(.el-input--large) {
    height: 44px;
  }
}

.password-strength {
  margin-top: -8px;
  margin-bottom: 8px;

  .strength-label {
    font-size: $font-size-caption;
    color: $gray-500;
    display: block;
    margin-bottom: 6px;
  }

  .strength-rules {
    list-style: none;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;

    li {
      font-size: $font-size-caption;
      color: $gray-500;
      padding: 2px 10px;
      border-radius: 9999px;
      background: $gray-100;
      transition: all 0.3s;

      &.met {
        color: $color-success;
        background: #e6f4ea;
      }
    }
  }
}

.dialog-footer {
  text-align: center;
}

.password-submit-btn {
  width: 100%;
  height: 44px;
  font-size: 15px;
  letter-spacing: 2px;
}

// 响应式
@media (max-width: 480px) {
  .login-card {
    width: 92%;
    padding: 32px 24px 28px;
    border-radius: 12px;
  }

  .login-header .login-title {
    font-size: 20px;
  }
}
</style>
