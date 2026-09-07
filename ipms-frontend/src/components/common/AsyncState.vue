<script setup>
import { computed } from 'vue'

const props = defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: [String, Object],
    default: null,
  },
  empty: {
    type: Boolean,
    default: false,
  },
  emptyTitle: {
    type: String,
    default: '暂无数据',
  },
  emptyDescription: {
    type: String,
    default: '',
  },
})

defineEmits(['retry'])

const errorMessage = computed(() => {
  if (typeof props.error === 'string') return props.error
  return props.error?.message
    ?? props.error?.response?.data?.message
    ?? '数据加载失败，请稍后重试'
})
</script>

<template>
  <div v-if="loading" class="async-state async-state--loading" data-state="loading">
    <el-skeleton :rows="4" animated />
  </div>

  <div v-else-if="error" class="async-state async-state--error" data-state="error">
    <el-icon class="async-state__error-icon" :size="28"><WarningFilled /></el-icon>
    <p class="async-state__title">加载失败</p>
    <p class="async-state__description">{{ errorMessage }}</p>
    <el-button data-testid="retry" type="primary" plain @click="$emit('retry')">
      重试
    </el-button>
  </div>

  <div v-else-if="empty" class="async-state async-state--empty" data-state="empty">
    <el-empty description="">
      <p class="async-state__title">{{ emptyTitle }}</p>
      <p v-if="emptyDescription" class="async-state__description">{{ emptyDescription }}</p>
    </el-empty>
  </div>

  <slot v-else />
</template>

<style scoped lang="scss">
.async-state {
  display: flex;
  min-height: 220px;
  align-items: center;
  justify-content: center;
  padding: 32px 24px;
  background: #fff;

  &--loading {
    display: block;
  }

  &--error {
    flex-direction: column;
    text-align: center;
  }

  &__error-icon {
    color: $color-danger;
    margin-bottom: 10px;
  }

  &__title {
    color: $color-ink;
    font-size: $font-size-h3;
    font-weight: 600;
    margin-bottom: 6px;
  }

  &__description {
    color: $color-muted;
    font-size: $font-size-small;
    line-height: 20px;
    margin-bottom: 16px;
  }
}
</style>
