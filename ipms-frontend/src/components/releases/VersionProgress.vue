<script setup>
import { computed } from 'vue'

const props = defineProps({
  statusCode: {
    type: String,
    default: 'DRAFT',
  },
})

const steps = [
  { code: 'DRAFT', label: '草稿' },
  { code: 'PLANNED', label: '已计划' },
  { code: 'IN_DEVELOPMENT', label: '开发中' },
  { code: 'IN_TESTING', label: '测试中' },
  { code: 'READY_TO_RELEASE', label: '待发布' },
  { code: 'RELEASED', label: '已发布' },
  { code: 'ARCHIVED', label: '已归档' },
]

const currentIndex = computed(() => Math.max(
  steps.findIndex((step) => step.code === props.statusCode),
  0,
))
</script>

<template>
  <div class="version-progress" aria-label="版本发布进度">
    <ol class="version-progress__track">
      <li
        v-for="(step, index) in steps"
        :key="step.code"
        data-testid="version-progress-step"
        class="version-progress__step"
        :class="{
          'is-complete': index < currentIndex,
          'is-current': index === currentIndex,
        }"
        :aria-current="index === currentIndex ? 'step' : undefined"
      >
        <span class="version-progress__marker">
          <span>{{ index + 1 }}</span>
        </span>
        <strong data-testid="version-progress-label">{{ step.label }}</strong>
      </li>
    </ol>
  </div>
</template>

<style scoped lang="scss">
.version-progress {
  width: 100%;
  overflow-x: auto;
  padding: 4px 0 8px;
}

.version-progress__track {
  display: grid;
  width: 100%;
  min-width: 980px;
  grid-template-columns: repeat(7, minmax(120px, 1fr));
  margin: 0;
  padding: 0;
  list-style: none;
}

.version-progress__step {
  position: relative;
  display: grid;
  justify-items: center;
  gap: 8px;
  color: $color-muted;
  font-size: $font-size-caption;
  text-align: center;

  &::before,
  &::after {
    position: absolute;
    top: 15px;
    width: 50%;
    height: 2px;
    background: $color-border;
    content: '';
  }

  &::before {
    left: 0;
  }

  &::after {
    right: 0;
  }

  &:first-child::before,
  &:last-child::after {
    display: none;
  }

  &.is-complete,
  &.is-current {
    color: $color-primary;

    &::before,
    &::after {
      background: $color-primary;
    }
  }

  &.is-current::after {
    background: $color-border;
  }

  strong {
    font-weight: 600;
  }
}

.version-progress__marker {
  position: relative;
  z-index: 1;
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border: 2px solid $color-border;
  border-radius: 50%;
  background: #fff;
  font-weight: 700;

  .is-complete &,
  .is-current & {
    border-color: $color-primary;
  }

  .is-complete & {
    color: #fff;
    background: $color-primary;
  }

  .is-current & {
    box-shadow: 0 0 0 4px $color-primary-soft;
  }
}
</style>
