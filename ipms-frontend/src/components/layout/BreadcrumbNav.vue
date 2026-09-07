<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const breadcrumbs = computed(() => {
  const matched = route.matched.filter((r) => r.meta?.title)
  return matched.map((r) => ({
    title: r.meta.title,
    to: r.name ? { name: r.name, params: route.params } : undefined,
  }))
})
</script>

<template>
  <div v-if="breadcrumbs.length" class="breadcrumb-nav">
    <el-breadcrumb separator=">">
      <el-breadcrumb-item
        v-for="(item, index) in breadcrumbs"
        :key="item.title"
        :to="index < breadcrumbs.length - 1 ? item.to : undefined"
      >
        {{ item.title }}
      </el-breadcrumb-item>
    </el-breadcrumb>
  </div>
</template>

<style scoped lang="scss">
.breadcrumb-nav {
  padding: 12px 24px;
  background: $color-canvas;
  border-bottom: 1px solid $color-border;
  flex-shrink: 0;
}
</style>
