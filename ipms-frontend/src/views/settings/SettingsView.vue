<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'

// ── Active section tracking ──────────────────────────────────────────────
const activeSection = ref('general')

const sections = [
  { key: 'general', label: '基本设置', icon: 'Setting' },
  { key: 'notification', label: '通知配置', icon: 'Bell' },
  { key: 'security', label: '安全设置', icon: 'Lock' },
  { key: 'file', label: '文件管理', icon: 'Folder' }
]

// ── 基本设置 ────────────────────────────────────────────────────────────
const generalForm = reactive({
  systemName: 'IPMS 项目管理系统',
  logoUrl: '',
  defaultLanguage: 'zh',
  timezone: 'Asia/Shanghai'
})

const languageOptions = [
  { label: '中文', value: 'zh' },
  { label: 'English', value: 'en' }
]

const timezoneOptions = [
  { label: 'Asia/Shanghai (UTC+8)', value: 'Asia/Shanghai' },
  { label: 'Asia/Tokyo (UTC+9)', value: 'Asia/Tokyo' },
  { label: 'Asia/Seoul (UTC+9)', value: 'Asia/Seoul' },
  { label: 'Asia/Singapore (UTC+8)', value: 'Asia/Singapore' },
  { label: 'Asia/Kolkata (UTC+5:30)', value: 'Asia/Kolkata' },
  { label: 'Europe/London (UTC+0)', value: 'Europe/London' },
  { label: 'Europe/Berlin (UTC+1)', value: 'Europe/Berlin' },
  { label: 'America/New_York (UTC-5)', value: 'America/New_York' },
  { label: 'America/Los_Angeles (UTC-8)', value: 'America/Los_Angeles' },
  { label: 'Pacific/Auckland (UTC+12)', value: 'Pacific/Auckland' }
]

const logoUploadRef = ref(null)

function handleLogoChange(file) {
  const reader = new FileReader()
  reader.onload = (e) => {
    generalForm.logoUrl = e.target.result
  }
  reader.readAsDataURL(file.raw)
  return false
}

function handleRemoveLogo() {
  generalForm.logoUrl = ''
}

// ── 通知配置 ────────────────────────────────────────────────────────────
const notificationForm = reactive({
  smtpHost: 'smtp.office365.com',
  smtpPort: 587,
  smtpUsername: '',
  smtpPassword: '',
  encryption: 'TLS',
  senderAddress: 'ipms@company.com',
  remindEnabled: true,
  dailyRemindTime: '09:00',
  defaultRemindDays: 3,
})

const encryptionOptions = [
  { label: 'TLS', value: 'TLS' },
  { label: 'SSL', value: 'SSL' },
  { label: 'NONE', value: 'NONE' }
]


// ── 安全设置 ────────────────────────────────────────────────────────────
const securityForm = reactive({
  minPasswordLength: 8,
  sessionTimeout: 30,
  maxLoginAttempts: 5,
  lockDuration: 15,
  firstLoginChangePassword: true,
  requireNumber: true,
  requireLetter: true
})

// ── 文件管理 ────────────────────────────────────────────────────────────
const fileForm = reactive({
  maxFileSize: 20,
  allowedFileTypes: 'jpg,png,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar',
  recycleBinDays: 30
})

// ── Form label widths ───────────────────────────────────────────────────
const formLabelWidth = '150px'

// ── Save handlers ───────────────────────────────────────────────────────
const sectionLabels = {
  general: '基本设置',
  notification: '通知配置',
  security: '安全设置',
  file: '文件管理'
}

function handleSave(section) {
  const label = sectionLabels[section] || section
  ElMessage.success(`${label} 配置已保存`)
}
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h2 class="page-title">系统设置</h2>
      <p class="page-description">管理系统全局配置参数</p>
    </div>

    <div class="settings-layout">
      <!-- 左侧分类导航 -->
      <div class="settings-nav">
        <div
          v-for="section in sections"
          :key="section.key"
          class="settings-nav-item"
          :class="{ active: activeSection === section.key }"
          @click="activeSection = section.key"
        >
          <el-icon><component :is="section.icon" /></el-icon>
          <span>{{ section.label }}</span>
        </div>
      </div>

      <!-- 右侧配置内容 -->
      <div class="settings-content">
        <!-- ━━━━━━━━━━━━━━ 基本设置 ━━━━━━━━━━━━━━ -->
        <div v-show="activeSection === 'general'">
          <el-card class="content-card">
            <template #header>
              <span class="card-header-title">基本设置</span>
            </template>
            <el-form :model="generalForm" :label-width="formLabelWidth">
              <el-form-item label="系统名称">
                <el-input v-model="generalForm.systemName" style="width: 400px" placeholder="请输入系统名称" />
              </el-form-item>

              <el-form-item label="Logo 上传">
                <div class="logo-upload-wrapper">
                  <el-upload
                    ref="logoUploadRef"
                    :auto-upload="false"
                    :show-file-list="false"
                    :on-change="handleLogoChange"
                    accept="image/png,image/jpeg,image/svg+xml"
                    drag
                  >
                    <div v-if="generalForm.logoUrl" class="logo-preview">
                      <img :src="generalForm.logoUrl" alt="Logo Preview" class="logo-preview-img" />
                    </div>
                    <div v-else class="logo-placeholder">
                      <el-icon class="logo-upload-icon"><UploadFilled /></el-icon>
                      <div class="logo-upload-text">拖拽或点击上传 Logo</div>
                      <div class="logo-upload-hint">支持 PNG / JPG / SVG，建议尺寸 200x60px</div>
                    </div>
                  </el-upload>
                  <el-button
                    v-if="generalForm.logoUrl"
                    type="danger"
                    plain
                    size="small"
                    class="logo-remove-btn"
                    @click="handleRemoveLogo"
                  >
                    移除 Logo
                  </el-button>
                </div>
              </el-form-item>

              <el-form-item label="默认语言">
                <el-select v-model="generalForm.defaultLanguage" style="width: 240px">
                  <el-option
                    v-for="item in languageOptions"
                    :key="item.value"
                    :label="item.label"
                    :value="item.value"
                  />
                </el-select>
              </el-form-item>

              <el-form-item label="时区">
                <el-select v-model="generalForm.timezone" style="width: 320px" filterable>
                  <el-option
                    v-for="item in timezoneOptions"
                    :key="item.value"
                    :label="item.label"
                    :value="item.value"
                  />
                </el-select>
              </el-form-item>

              <el-form-item>
                <el-button type="primary" @click="handleSave('general')">保存配置</el-button>
              </el-form-item>
            </el-form>
          </el-card>
        </div>

        <!-- ━━━━━━━━━━━━━━ 通知配置 ━━━━━━━━━━━━━━ -->
        <div v-show="activeSection === 'notification'">
          <el-card class="content-card">
            <template #header>
              <span class="card-header-title">通知配置</span>
            </template>
            <el-form :model="notificationForm" :label-width="formLabelWidth">
              <el-form-item label="SMTP 服务器">
                <el-input v-model="notificationForm.smtpHost" style="width: 360px" placeholder="smtp.office365.com" />
              </el-form-item>

              <el-form-item label="SMTP 端口">
                <el-input-number v-model="notificationForm.smtpPort" :min="1" :max="65535" style="width: 180px" />
              </el-form-item>

              <el-form-item label="SMTP 用户名">
                <el-input v-model="notificationForm.smtpUsername" style="width: 360px" placeholder="输入 SMTP 用户名" />
              </el-form-item>

              <el-form-item label="SMTP 密码">
                <el-input
                  v-model="notificationForm.smtpPassword"
                  type="password"
                  show-password
                  style="width: 360px"
                  placeholder="输入 SMTP 密码"
                />
              </el-form-item>

              <el-form-item label="加密方式">
                <el-select v-model="notificationForm.encryption" style="width: 180px">
                  <el-option
                    v-for="item in encryptionOptions"
                    :key="item.value"
                    :label="item.label"
                    :value="item.value"
                  />
                </el-select>
              </el-form-item>

              <el-form-item label="发件人地址">
                <el-input v-model="notificationForm.senderAddress" style="width: 360px" placeholder="ipms@company.com" />
              </el-form-item>

              <el-divider content-position="left">提醒设置</el-divider>

              <el-form-item label="全局提醒开关">
                <el-switch v-model="notificationForm.remindEnabled" active-text="已启用" inactive-text="已关闭" />
                <div class="form-hint">关闭后所有定时提醒将停止发送</div>
              </el-form-item>

              <el-form-item label="每日提醒发送时间">
                <el-time-picker
                  v-model="notificationForm.dailyRemindTime"
                  format="HH:mm"
                  value-format="HH:mm"
                  placeholder="选择时间"
                />
                <div class="form-hint">每天在此时间集中发送提醒邮件</div>
              </el-form-item>

              <el-form-item label="默认提前提醒天数">
                <el-input-number v-model="notificationForm.defaultRemindDays" :min="1" :max="30" style="width: 180px" />
                <span class="form-unit">天</span>
                <div class="form-hint">任务截止日期前 N 天开始提醒</div>
              </el-form-item>


              <el-form-item>
                <el-button type="primary" @click="handleSave('notification')">保存配置</el-button>
              </el-form-item>
            </el-form>
          </el-card>
        </div>

        <!-- ━━━━━━━━━━━━━━ 安全设置 ━━━━━━━━━━━━━━ -->
        <div v-show="activeSection === 'security'">
          <el-card class="content-card">
            <template #header>
              <span class="card-header-title">安全设置</span>
            </template>
            <el-form :model="securityForm" :label-width="formLabelWidth">
              <el-form-item label="最小密码长度">
                <el-input-number v-model="securityForm.minPasswordLength" :min="6" :max="32" style="width: 180px" />
                <span class="form-unit">位</span>
              </el-form-item>

              <el-form-item label="会话超时时间">
                <el-input-number v-model="securityForm.sessionTimeout" :min="5" :max="120" style="width: 180px" />
                <span class="form-unit">分钟</span>
                <div class="form-hint">无操作超过此时长后自动退出登录</div>
              </el-form-item>

              <el-form-item label="最大登录尝试次数">
                <el-input-number v-model="securityForm.maxLoginAttempts" :min="3" :max="10" style="width: 180px" />
                <span class="form-unit">次</span>
                <div class="form-hint">连续登录失败超过此次数后触发账号锁定</div>
              </el-form-item>

              <el-form-item label="锁定持续时长">
                <el-input-number v-model="securityForm.lockDuration" :min="5" :max="1440" style="width: 180px" />
                <span class="form-unit">分钟</span>
                <div class="form-hint">账号锁定后自动解锁的等待时长</div>
              </el-form-item>

              <el-divider content-position="left">密码策略</el-divider>

              <el-form-item label="首次登录强制改密">
                <el-switch v-model="securityForm.firstLoginChangePassword" active-text="是" inactive-text="否" />
                <div class="form-hint">新用户首次登录时必须修改密码</div>
              </el-form-item>

              <el-form-item label="密码需包含数字">
                <el-switch v-model="securityForm.requireNumber" active-text="是" inactive-text="否" />
              </el-form-item>

              <el-form-item label="密码需包含字母">
                <el-switch v-model="securityForm.requireLetter" active-text="是" inactive-text="否" />
              </el-form-item>

              <el-form-item>
                <el-button type="primary" @click="handleSave('security')">保存配置</el-button>
              </el-form-item>
            </el-form>
          </el-card>
        </div>

        <!-- ━━━━━━━━━━━━━━ 文件管理 ━━━━━━━━━━━━━━ -->
        <div v-show="activeSection === 'file'">
          <el-card class="content-card">
            <template #header>
              <span class="card-header-title">文件管理</span>
            </template>
            <el-form :model="fileForm" :label-width="formLabelWidth">
              <el-form-item label="单文件最大上传大小">
                <el-input-number v-model="fileForm.maxFileSize" :min="1" :max="100" style="width: 180px" />
                <span class="form-unit">MB</span>
              </el-form-item>

              <el-form-item label="允许的文件类型">
                <el-input v-model="fileForm.allowedFileTypes" style="width: 480px" placeholder="多个类型用英文逗号分隔" />
                <div class="form-hint">逗号分隔的扩展名列表，不含点号。留空表示允许所有类型</div>
              </el-form-item>

              <el-form-item label="回收站保留天数">
                <el-input-number v-model="fileForm.recycleBinDays" :min="7" :max="90" style="width: 180px" />
                <span class="form-unit">天</span>
                <div class="form-hint">文件删除后进入回收站，超过此天数自动永久清理</div>
              </el-form-item>

              <el-form-item>
                <el-button type="primary" @click="handleSave('file')">保存配置</el-button>
              </el-form-item>
            </el-form>
          </el-card>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
// ── SCSS variables (fallback to values if globals not loaded) ───────────
$color-primary: var(--el-color-primary, #409eff) !default;
$color-primary-light: var(--el-color-primary-light-9, #ecf5ff) !default;
$gray-50: #f8f9fa !default;
$gray-100: #f0f1f3 !default;
$gray-200: #e9ecef !default;
$gray-500: #909399 !default;
$gray-700: #606266 !default;
$gray-900: #303133 !default;
$border-radius-md: 8px !default;
$font-size-caption: 12px !default;
$font-size-body: 14px !default;
$shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06) !default;

.settings-layout {
  display: flex;
  gap: 16px;
  min-height: 520px;
}

// ── Left navigation ─────────────────────────────────────────────────────
.settings-nav {
  width: 180px;
  flex-shrink: 0;
  background: #fff;
  border-radius: $border-radius-md;
  box-shadow: $shadow-sm;
  padding: 8px 0;
  align-self: flex-start;
}

.settings-nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 20px;
  font-size: $font-size-body;
  color: $gray-700;
  cursor: pointer;
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
  user-select: none;

  &:hover {
    background-color: $gray-50;
    color: $color-primary;
  }

  &.active {
    color: $color-primary;
    background-color: $color-primary-light;
    border-left-color: $color-primary;
    font-weight: 500;
  }

  .el-icon {
    font-size: 16px;
  }
}

// ── Right content area ──────────────────────────────────────────────────
.settings-content {
  flex: 1;
  min-width: 0;
}

.content-card {
  border-radius: $border-radius-md;
  box-shadow: $shadow-sm;

  :deep(.el-card__header) {
    padding: 14px 20px;
    background: $gray-50;
    border-bottom: 1px solid $gray-200;
  }
}

.card-header-title {
  font-size: 15px;
  font-weight: 600;
  color: $gray-900;
}

// ── Form helpers ────────────────────────────────────────────────────────
.form-hint {
  font-size: $font-size-caption;
  color: $gray-500;
  margin-top: 4px;
  margin-left: 8px;
  display: inline-block;
}

.form-unit {
  font-size: $font-size-body;
  color: $gray-700;
  margin-left: 6px;
}

// ── Logo upload ─────────────────────────────────────────────────────────
.logo-upload-wrapper {
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-items: flex-start;
}

.logo-preview {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.logo-preview-img {
  max-width: 100%;
  max-height: 110px;
  object-fit: contain;
}

.logo-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 16px 0;
}

.logo-upload-icon {
  font-size: 28px;
  color: $gray-500;
  margin-bottom: 4px;
}

.logo-upload-text {
  font-size: $font-size-body;
  color: $gray-700;
}

.logo-upload-hint {
  font-size: $font-size-caption;
  color: $gray-500;
  margin-top: 4px;
}

.logo-remove-btn {
  align-self: flex-start;
}

// ── Responsive ──────────────────────────────────────────────────────────
@media (max-width: 768px) {
  .settings-layout {
    flex-direction: column;
  }

  .settings-nav {
    width: 100%;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 6px;
  }

  .settings-nav-item {
    border-left: none;
    border-bottom: 3px solid transparent;
    padding: 8px 14px;

    &.active {
      border-left-color: transparent;
      border-bottom-color: $color-primary;
    }
  }
}
</style>
