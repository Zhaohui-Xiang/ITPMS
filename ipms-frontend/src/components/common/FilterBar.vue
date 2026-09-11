<script setup>
import { RefreshLeft, Search } from '@element-plus/icons-vue'

defineProps({
  busy: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['search', 'reset'])
</script>

<template>
  <section class="filter-bar" aria-label="列表筛选">
    <div class="filter-bar__fields">
      <slot />
    </div>
    <div class="filter-bar__actions">
      <el-button type="primary" :icon="Search" :loading="busy" @click="$emit('search')">
        查询
      </el-button>
      <el-tooltip content="清除全部筛选条件" placement="top">
        <el-button
          :icon="RefreshLeft"
          aria-label="重置筛选"
          data-testid="reset-filters"
          @click="$emit('reset')"
        >
          重置
        </el-button>
      </el-tooltip>
    </div>
  </section>
</template>

<style scoped lang="scss">
.filter-bar {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  padding: 16px 20px;
  border: 1px solid $color-border;
  border-radius: 6px;
  background: #fff;

  &__fields {
    display: flex;
    flex: 1;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    min-width: 0;
  }

  &__actions {
    display: flex;
    flex: 0 0 auto;
    gap: 8px;
  }

  :deep(.el-button + .el-button) {
    margin-left: 0;
  }
}

@media (max-width: 760px) {
  .filter-bar {
    align-items: stretch;
    flex-direction: column;

    &__fields,
    &__actions {
      width: 100%;
    }

    &__actions :deep(.el-button) {
      flex: 1;
    }
  }
}
</style>
