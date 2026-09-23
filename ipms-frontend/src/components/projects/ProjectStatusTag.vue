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

const normalizedCode = computed(() => props.statusCode.toUpperCase())
const statusClass = computed(() => {
  const supported = ['ACTIVE', 'MAINTENANCE', 'ARCHIVED']

  return supported.includes(normalizedCode.value)
    ? `project-status--${normalizedCode.value.toLowerCase()}`
    : 'project-status--unknown'
})
</script>

<template>
  <span
    class="project-status"
    :class="statusClass"
    :data-status-code="normalizedCode"
  >
    <span class="project-status__dot" aria-hidden="true" />
    {{ statusLabel || statusCode || '-' }}
  </span>
</template>

<style scoped lang="scss">
.project-status {
  display: inline-flex;
  min-height: 24px;
  align-items: center;
  gap: 7px;
  padding: 2px 9px;
  border: 1px solid transparent;
  border-radius: 4px;
  font-size: $font-size-caption;
  font-weight: 600;
  line-height: 18px;
  white-space: nowrap;

  &__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
  }

  &--active {
    color: #027a48;
    background: #ecfdf3;
    border-color: #abefc6;
  }

  &--maintenance {
    color: #854a0e;
    background: #fffaeb;
    border-color: #fedf89;
  }

  &--archived,
  &--unknown {
    color: #667085;
    background: #f2f4f7;
    border-color: #d0d5dd;
  }
}
</style>
