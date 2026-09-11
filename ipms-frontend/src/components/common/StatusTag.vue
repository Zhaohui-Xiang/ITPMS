<script setup>
import { computed } from 'vue'

const props = defineProps({
  statusCode: {
    type: String,
    default: '',
  },
  statusLabel: {
    type: String,
    default: '',
  },
})

const tone = computed(() => {
  const code = props.statusCode.toUpperCase()

  if (['ACCEPTED', 'DEPLOYED', 'COMPLETED', 'CLOSED', 'RELEASED', 'ACTIVE'].includes(code)) {
    return 'success'
  }
  if (['REJECTED', 'REOPENED', 'FAILED', 'BLOCKED'].includes(code)) {
    return 'danger'
  }
  if (['PENDING_REVIEW', 'PENDING_DEPLOY', 'PENDING_RETEST', 'TODO'].includes(code)) {
    return 'warning'
  }
  return 'info'
})
</script>

<template>
  <span class="status-tag" :class="`status-tag--${tone}`">
    <span class="status-tag__dot" aria-hidden="true" />
    {{ statusLabel || statusCode || '-' }}
  </span>
</template>

<style scoped lang="scss">
.status-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 24px;
  color: $color-ink;
  font-size: $font-size-small;
  font-weight: 600;
  white-space: nowrap;

  &__dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: $color-muted;
  }

  &--success .status-tag__dot {
    background: $color-success;
  }

  &--warning .status-tag__dot {
    background: $color-warning;
  }

  &--danger .status-tag__dot {
    background: $color-danger;
  }

  &--info .status-tag__dot {
    background: $color-primary;
  }
}
</style>
