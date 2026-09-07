<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { changePassword, updateProfile } from '@/api/user'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  forcePasswordChange: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue'])
const authStore = useAuthStore()
const profileFormRef = ref(null)
const passwordFormRef = ref(null)
const activeTab = ref('profile')
const savingProfile = ref(false)
const savingPassword = ref(false)
const passwordChanged = ref(false)
const refreshingUser = ref(false)
const fieldErrors = ref({})

const profileForm = reactive({
  display_name: '',
  email: '',
  phone: '',
})
const passwordForm = reactive({
  currentPassword: '',
  newPassword: '',
  newPasswordConfirmation: '',
})

const visible = computed(() => props.modelValue || props.forcePasswordChange)
const isForced = computed(() => props.forcePasswordChange || authStore.mustChangePassword)
const serverMessages = computed(() => Object.values(fieldErrors.value).flat())

const profileRules = {
  display_name: [{ max: 100, message: '姓名不能超过 100 个字符', trigger: 'blur' }],
  email: [{ type: 'email', message: '请输入有效邮箱', trigger: 'blur' }],
  phone: [{ max: 20, message: '电话不能超过 20 个字符', trigger: 'blur' }],
}
const passwordRules = {
  currentPassword: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '新密码至少 8 位', trigger: 'blur' },
    { max: 128, message: '新密码不能超过 128 位', trigger: 'blur' },
  ],
  newPasswordConfirmation: [
    { required: true, message: '请确认新密码', trigger: 'blur' },
    {
      validator: (_rule, value, callback) => {
        if (value !== passwordForm.newPassword) {
          callback(new Error('两次输入的密码不一致'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}

function syncProfileForm() {
  const user = authStore.user ?? {}
  profileForm.display_name = user.display_name ?? ''
  profileForm.email = user.email ?? ''
  profileForm.phone = user.phone ?? ''
}

function resetPasswordForm() {
  passwordForm.currentPassword = ''
  passwordForm.newPassword = ''
  passwordForm.newPasswordConfirmation = ''
}

function validationErrors(error) {
  return error?.response?.data?.errors ?? {
    general: [error?.response?.data?.message ?? error?.message ?? '保存失败，请稍后重试'],
  }
}

function requestClose() {
  if (isForced.value) return
  emit('update:modelValue', false)
}

function beforeClose(done) {
  if (isForced.value) return
  emit('update:modelValue', false)
  done()
}

async function refreshUserState() {
  const lockedUser = authStore.user
  refreshingUser.value = true
  fieldErrors.value = {}

  try {
    await authStore.fetchUser()
    if (authStore.mustChangePassword) {
      authStore.user = lockedUser
      fieldErrors.value = { general: ['密码已修改，但用户状态尚未更新，请重试'] }
      return
    }

    passwordChanged.value = false
    ElMessage.success('密码已更新')
    emit('update:modelValue', false)
  } catch {
    authStore.user = lockedUser
    fieldErrors.value = { general: ['密码已修改，但用户状态刷新失败，请重试'] }
    ElMessage.warning('密码已修改，请重试状态刷新')
  } finally {
    refreshingUser.value = false
  }
}

async function saveProfile() {
  fieldErrors.value = {}
  try {
    await profileFormRef.value?.validate()
  } catch {
    return
  }

  savingProfile.value = true
  try {
    const response = await updateProfile({ ...profileForm })
    const updatedUser = response?.data?.data ?? {}
    authStore.user = { ...authStore.user, ...updatedUser }
    ElMessage.success('个人信息已更新')
    emit('update:modelValue', false)
  } catch (error) {
    fieldErrors.value = validationErrors(error)
    ElMessage.error(error?.response?.data?.message ?? '个人信息保存失败')
  } finally {
    savingProfile.value = false
  }
}

async function savePassword() {
  fieldErrors.value = {}
  try {
    await passwordFormRef.value?.validate()
  } catch {
    return
  }

  savingPassword.value = true
  try {
    await changePassword({
      current_password: passwordForm.currentPassword,
      new_password: passwordForm.newPassword,
      new_password_confirmation: passwordForm.newPasswordConfirmation,
    })
    resetPasswordForm()
    passwordChanged.value = true
    await refreshUserState()
  } catch (error) {
    fieldErrors.value = validationErrors(error)
    ElMessage.error(error?.response?.data?.message ?? '密码修改失败')
  } finally {
    savingPassword.value = false
  }
}

watch(
  () => [props.modelValue, props.forcePasswordChange],
  ([isOpen, forcePasswordChange]) => {
    if (!isOpen && !forcePasswordChange) return
    fieldErrors.value = {}
    syncProfileForm()
    if (forcePasswordChange) {
      activeTab.value = 'password'
    }
  },
  { immediate: true },
)
</script>

<template>
  <el-dialog
    :model-value="visible"
    title="个人中心"
    width="520px"
    :show-close="!isForced"
    :close-on-click-modal="!isForced"
    :close-on-press-escape="!isForced"
    :before-close="beforeClose"
    @update:model-value="requestClose"
  >
    <el-alert
      v-if="serverMessages.length"
      type="error"
      :closable="false"
      show-icon
      class="profile-dialog__alert"
    >
      <span>{{ serverMessages.join('；') }}</span>
    </el-alert>

    <el-tabs v-model="activeTab">
      <el-tab-pane label="个人资料" name="profile" :disabled="isForced">
        <el-form
          ref="profileFormRef"
          :model="profileForm"
          :rules="profileRules"
          label-position="top"
        >
          <el-form-item label="姓名" prop="display_name">
            <el-input
              v-model="profileForm.display_name"
              data-testid="profile-name"
              autocomplete="name"
            />
          </el-form-item>
          <el-form-item label="邮箱" prop="email">
            <el-input
              v-model="profileForm.email"
              data-testid="profile-email"
              autocomplete="email"
            />
          </el-form-item>
          <el-form-item label="电话" prop="phone">
            <el-input
              v-model="profileForm.phone"
              data-testid="profile-phone"
              autocomplete="tel"
            />
          </el-form-item>
        </el-form>
      </el-tab-pane>

      <el-tab-pane label="修改密码" name="password">
        <el-form
          ref="passwordFormRef"
          :model="passwordForm"
          :rules="passwordRules"
          label-position="top"
        >
          <el-form-item label="当前密码" prop="currentPassword">
            <el-input
              v-model="passwordForm.currentPassword"
              data-testid="current-password"
              type="password"
              show-password
              autocomplete="current-password"
            />
          </el-form-item>
          <el-form-item label="新密码" prop="newPassword">
            <el-input
              v-model="passwordForm.newPassword"
              data-testid="new-password"
              type="password"
              show-password
              autocomplete="new-password"
            />
          </el-form-item>
          <el-form-item label="确认新密码" prop="newPasswordConfirmation">
            <el-input
              v-model="passwordForm.newPasswordConfirmation"
              data-testid="confirm-password"
              type="password"
              show-password
              autocomplete="new-password"
            />
          </el-form-item>
        </el-form>
      </el-tab-pane>
    </el-tabs>

    <template #footer>
      <el-button
        v-if="!isForced"
        data-testid="profile-cancel"
        @click="requestClose"
      >
        取消
      </el-button>
      <el-button
        v-if="activeTab === 'profile'"
        data-testid="profile-save"
        type="primary"
        :loading="savingProfile"
        @click="saveProfile"
      >
        保存资料
      </el-button>
      <el-button
        v-else-if="passwordChanged"
        data-testid="password-refresh"
        type="primary"
        :loading="refreshingUser"
        @click="refreshUserState"
      >
        重新验证状态
      </el-button>
      <el-button
        v-else
        data-testid="password-save"
        type="primary"
        :loading="savingPassword"
        @click="savePassword"
      >
        更新密码
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.profile-dialog__alert {
  margin-bottom: 16px;
}

:deep(.el-tabs__content) {
  min-height: 286px;
}
</style>
