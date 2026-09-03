<script setup>
import voltageWordmark from '@/assets/brand/voltage-wordmark.png'
import voltageV from '@/assets/brand/voltage-v.png'

defineProps({
  compact: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <span
    class="brand-mark"
    :class="{ 'brand-mark--compact': compact }"
  >
    <img
      class="brand-mark__image"
      :src="compact ? voltageV : voltageWordmark"
      alt="Voltage"
    >
  </span>
</template>

<style scoped lang="scss">
.brand-mark {
  display: inline-flex;
  flex: 0 0 132px;
  align-items: center;
  justify-content: center;
  width: 132px;
  height: 28px;

  &--compact {
    flex-basis: 30px;
    width: 30px;
    height: 30px;
  }

  &__image {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
  }
}
</style>
