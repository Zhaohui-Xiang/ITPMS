<script setup>
import AsyncState from './AsyncState.vue'

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: [String, Object],
    default: null,
  },
  rows: {
    type: Array,
    default: () => [],
  },
  total: {
    type: Number,
    default: 0,
  },
  page: {
    type: Number,
    default: 1,
  },
  pageSize: {
    type: Number,
    default: 20,
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

defineEmits(['page-change', 'page-size-change', 'retry'])
</script>

<template>
  <section class="paginated-table">
    <AsyncState
      :loading="loading"
      :error="error"
      :empty="!loading && !error && rows.length === 0"
      :empty-title="emptyTitle"
      :empty-description="emptyDescription"
      @retry="$emit('retry')"
    >
      <div class="paginated-table__scroll">
        <slot :rows="rows" />
      </div>
      <footer class="paginated-table__footer">
        <span class="paginated-table__count">共 {{ total }} 条</span>
        <el-pagination
          :current-page="page"
          :page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          layout="sizes, prev, pager, next"
          background
          @current-change="$emit('page-change', $event)"
          @size-change="$emit('page-size-change', $event)"
        />
      </footer>
    </AsyncState>
  </section>
</template>

<style scoped lang="scss">
.paginated-table {
  min-width: 0;
  border: 1px solid $color-border;
  border-radius: 6px;
  overflow: hidden;
  background: #fff;

  &__scroll {
    width: 100%;
    overflow-x: auto;
  }

  &__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 60px;
    padding: 10px 16px;
    border-top: 1px solid $color-border;
  }

  &__count {
    color: $color-muted;
    font-size: $font-size-small;
    white-space: nowrap;
  }
}

@media (max-width: 760px) {
  .paginated-table__footer {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
