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
        <el-icon :size="80"><DocumentDelete /></el-icon>
      </div>
      <h1 class="error-code">404</h1>
      <h2 class="error-title">页面未找到</h2>
      <p class="error-desc">
        您访问的页面不存在或已被移除。<br />
        请检查链接是否正确，或返回首页。
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
    color: $gray-300;
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
