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
  const supported = [
    'DRAFT',
    'PLANNED',
    'IN_DEVELOPMENT',
    'IN_TESTING',
    'READY_TO_RELEASE',
    'RELEASED',
    'ARCHIVED',
  ]

  return supported.includes(normalizedCode.value)
    ? `version-status--${normalizedCode.value.toLowerCase()}`
    : 'version-status--unknown'
})
</script>

<template>
  <span
    class="version-status"
    :class="statusClass"
    :data-status-code="normalizedCode"
  >
    <span class="version-status__dot" aria-hidden="true" />
    {{ statusLabel || statusCode || '-' }}
  </span>
</template>

<style scoped lang="scss">
.version-status {
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

  &--draft,
  &--archived,
  &--unknown {
    color: #667085;
    background: #f2f4f7;
    border-color: #d0d5dd;
  }

  &--planned {
    color: #175cd3;
    background: #eff8ff;
    border-color: #b2ddff;
  }

  &--in_development {
    color: #9a3412;
    background: #fff7ed;
    border-color: #fed7aa;
  }

  &--in_testing {
    color: #6941c6;
    background: #f9f5ff;
    border-color: #d6bbfb;
  }

  &--ready_to_release {
    color: #854a0e;
    background: #fffaeb;
    border-color: #fedf89;
  }

  &--released {
    color: #027a48;
    background: #ecfdf3;
    border-color: #abefc6;
  }
}
</style>
