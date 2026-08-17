<script setup>
import { useRouter } from 'vue-router'

const router = useRouter()

function goHome() {
  router.push('/dashboard')
}
</script>

<template>
  <div class="error-page">
    <div class="error-content">
      <div class="error-icon">
        <el-icon :size="80"><Lock /></el-icon>
      </div>
      <h1 class="error-code">403</h1>
      <h2 class="error-title">无访问权限</h2>
      <p class="error-desc">
        抱歉，您没有权限访问此页面或执行此操作。<br />
        如需访问，请联系系统管理员申请相应权限。
      </p>
      <el-button type="primary" @click="goHome">返回首页</el-button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.error-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: #F5F7FA;
}

.error-content {
  text-align: center;

  .error-icon {
    color: #F56C6C;
    margin-bottom: 24px;
  }

  .error-code {
    font-size: 72px;
    font-weight: 700;
    color: $gray-300;
    line-height: 1;
    margin-bottom: 12px;
  }

  .error-title {
    font-size: $font-size-h1;
    color: $gray-900;
    margin-bottom: 12px;
  }

  .error-desc {
    font-size: $font-size-body;
    color: $gray-500;
    line-height: 24px;
    margin-bottom: 32px;
  }
}
</style>
