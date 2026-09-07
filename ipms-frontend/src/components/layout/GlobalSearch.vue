<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { listProjects } from '@/api/project'
import { listRequirements } from '@/api/requirement'

const router = useRouter()
const query = ref('')
const loading = ref(false)
const searched = ref(false)
const projects = ref([])
const requirements = ref([])
const activeIndex = ref(-1)
let debounceTimer
let requestVersion = 0

const records = computed(() => [
  ...projects.value.map((project) => ({
    type: 'project',
    id: project.id,
    label: project.name,
    path: `/projects/${project.id}`,
  })),
  ...requirements.value.map((requirement) => ({
    type: 'requirement',
    id: requirement.id,
    label: requirement.title,
    path: `/requirements/${requirement.id}`,
  })),
])
const showResults = computed(() => (
  query.value.trim().length >= 2
  && (loading.value || searched.value)
))

function responseItems(response) {
  return response?.data?.data?.items ?? []
}

function clearResults() {
  projects.value = []
  requirements.value = []
  searched.value = false
  activeIndex.value = -1
}

async function search(term, version) {
  loading.value = true
  try {
    const [projectResponse, requirementResponse] = await Promise.all([
      listProjects({ keyword: term, page_size: 5 }),
      listRequirements({ keyword: term, page_size: 5 }),
    ])
    if (version !== requestVersion) return

    projects.value = responseItems(projectResponse)
    requirements.value = responseItems(requirementResponse)
    searched.value = true
    activeIndex.value = records.value.length ? 0 : -1
  } catch {
    if (version !== requestVersion) return
    projects.value = []
    requirements.value = []
    searched.value = true
    activeIndex.value = -1
  } finally {
    if (version === requestVersion) {
      loading.value = false
    }
  }
}

function scheduleSearch(value) {
  requestVersion += 1
  const version = requestVersion
  const term = value.trim()
  clearTimeout(debounceTimer)

  if (term.length < 2) {
    loading.value = false
    clearResults()
    return
  }

  loading.value = true
  searched.value = false
  debounceTimer = setTimeout(() => search(term, version), 250)
}

function navigate(record) {
  if (!record) return
  query.value = ''
  clearResults()
  router.push(record.path)
}

function handleKeydown(event) {
  if (!records.value.length) return

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = (activeIndex.value + 1) % records.value.length
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = activeIndex.value <= 0
      ? records.value.length - 1
      : activeIndex.value - 1
  } else if (event.key === 'Enter') {
    event.preventDefault()
    navigate(records.value[activeIndex.value])
  } else if (event.key === 'Escape') {
    query.value = ''
    clearResults()
  }
}

watch(query, scheduleSearch)
onBeforeUnmount(() => clearTimeout(debounceTimer))
</script>

<template>
  <div class="global-search">
    <el-input
      v-model="query"
      data-testid="global-search-input"
      clearable
      placeholder="搜索项目或需求"
      aria-label="全局搜索"
      @keydown="handleKeydown"
    >
      <template #prefix>
        <el-icon><Search /></el-icon>
      </template>
      <template v-if="loading" #suffix>
        <el-icon class="is-loading"><Loading /></el-icon>
      </template>
    </el-input>

    <div v-if="showResults" class="global-search__results">
      <template v-if="records.length">
        <section v-if="projects.length" class="global-search__group">
          <div class="global-search__group-title">项目</div>
          <button
            v-for="(project, index) in projects"
            :key="`project-${project.id}`"
            type="button"
            class="global-search__item"
            :class="{ active: activeIndex === index }"
            @mousedown.prevent="navigate(records[index])"
          >
            <span class="global-search__item-type">P</span>
            <span>{{ project.name }}</span>
          </button>
        </section>

        <section v-if="requirements.length" class="global-search__group">
          <div class="global-search__group-title">需求</div>
          <button
            v-for="(requirement, index) in requirements"
            :key="`requirement-${requirement.id}`"
            type="button"
            class="global-search__item"
            :class="{ active: activeIndex === projects.length + index }"
            @mousedown.prevent="navigate(records[projects.length + index])"
          >
            <span class="global-search__item-type">R</span>
            <span>{{ requirement.title }}</span>
          </button>
        </section>
      </template>
      <div v-else-if="!loading" class="global-search__empty">未找到匹配结果</div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.global-search {
  position: relative;
  width: min(380px, 36vw);
  min-width: 260px;

  &__results {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    z-index: 2100;
    overflow: hidden;
    max-height: 420px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid $color-border;
    border-radius: $border-radius-md;
    box-shadow: 0 10px 28px rgba(23, 33, 43, 0.14);
  }

  &__group + &__group {
    border-top: 1px solid $color-border;
  }

  &__group-title {
    padding: 9px 12px 5px;
    color: $color-muted;
    font-size: $font-size-caption;
    font-weight: 600;
  }

  &__item {
    width: 100%;
    min-height: 38px;
    padding: 7px 12px;
    display: flex;
    align-items: center;
    gap: 9px;
    border: 0;
    background: transparent;
    color: $color-ink;
    cursor: pointer;
    font: inherit;
    text-align: left;

    &:hover,
    &.active {
      background: $color-primary-soft;
      color: $color-primary;
    }
  }

  &__item-type {
    display: inline-flex;
    width: 22px;
    height: 22px;
    align-items: center;
    justify-content: center;
    flex: 0 0 22px;
    border-radius: 4px;
    background: $color-canvas;
    color: $color-muted;
    font-size: 11px;
    font-weight: 700;
  }

  &__empty {
    padding: 24px 16px;
    color: $color-muted;
    text-align: center;
  }
}
</style>
