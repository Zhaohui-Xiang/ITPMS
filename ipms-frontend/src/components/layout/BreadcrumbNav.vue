<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const breadcrumbs = computed(() => {
  const matched = route.matched.filter((r) => r.meta?.title)
  return matched.map((r) => ({
    title: r.meta.title,
    path: r.path
  }))
})

function handleClick(item, index) {
  if (index < breadcrumbs.value.length - 1) {
    router.push(item.path)
  }
}
</script>

<template>
  <div class="breadcrumb-nav" v-if="breadcrumbs.length > 1">
    <el-breadcrumb separator=">">
      <el-breadcrumb-item
        v-for="(item, index) in breadcrumbs"
        :key="item.path"
        :to="index < breadcrumbs.length - 1 ? item.path : undefined"
      >
        {{ item.title }}
      </el-breadcrumb-item>
    </el-breadcrumb>
  </div>
</template>

<style scoped lang="scss">
.breadcrumb-nav {
  padding: 12px 24px;
  background: #fff;
  border-bottom: 1px solid $gray-200;
  flex-shrink: 0;
}
</style>
