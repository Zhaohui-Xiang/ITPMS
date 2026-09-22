<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, RefreshLeft, Remove } from '@element-plus/icons-vue'
import {
  listUnplannedRequirements,
  planRequirementVersion,
  unplanRequirementVersion,
} from '@/api/projectVersion'

const props = defineProps({
  version: {
    type: Object,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['changed', 'stale', 'busy'])

const readonlyStatuses = ['READY_TO_RELEASE', 'RELEASED', 'ARCHIVED']
const unplanned = ref([])
const loading = ref(false)
const busyKey = ref('')
const reason = ref('')
const currentLock = ref(props.version.lock_version)

const isReadOnly = computed(() => readonlyStatuses.includes(props.version.status_code))
const requiresReason = computed(() => props.version.status_code === 'IN_TESTING')
const canMutate = computed(() => (
  !isReadOnly.value
  && (props.version.allowed_actions ?? []).includes('plan_requirements')
))

const effectiveScope = computed(() => {
  const liveScope = props.version.scope ?? []
  const snapshotScope = props.version.release_snapshot?.requirement_scope ?? []

  if (!['RELEASED', 'ARCHIVED'].includes(props.version.status_code) || snapshotScope.length === 0) {
    return liveScope
  }

  return snapshotScope.map((snapshotItem) => {
    const liveItem = liveScope.find((item) => (
      Number(item.requirement_id) === Number(snapshotItem.requirement_id)
    ))

    return {
      ...snapshotItem,
      requirement: liveItem?.requirement ?? {
        id: snapshotItem.requirement_id,
        title: `需求 #${snapshotItem.requirement_id}`,
      },
    }
  })
})

function normalizedReason() {
  return reason.value.trim()
}

function validateReason() {
  if (requiresReason.value && !normalizedReason()) {
    ElMessage.error('测试阶段调整范围必须填写原因')
    return false
  }
  return true
}

function mutationPayload() {
  return {
    project_version_id: props.version.id,
    lock_version: currentLock.value,
    ...(normalizedReason() ? { reason: normalizedReason() } : {}),
  }
}

async function loadPool() {
  if (!canMutate.value || !props.version.project?.id) {
    unplanned.value = []
    return
  }

  loading.value = true
  try {
    const response = await listUnplannedRequirements(props.version.project.id, {
      page: 1,
      page_size: 100,
    })
    unplanned.value = response?.data?.data?.items ?? []
  } catch (error) {
    unplanned.value = []
    ElMessage.error(error?.response?.data?.message ?? '加载未规划需求失败')
  } finally {
    loading.value = false
  }
}

async function refreshPool() {
  if (props.disabled || busyKey.value || loading.value) return
  await loadPool()
}

async function plan(requirement) {
  if (!canMutate.value || props.disabled || busyKey.value) return
  if (!validateReason()) return

  const key = `plan-${requirement.id}`
  busyKey.value = key
  emit('busy', true)
  try {
    const response = await planRequirementVersion(
      requirement.id,
      props.version.project.id,
      mutationPayload(),
    )
    currentLock.value = response?.data?.data?.lock_version ?? currentLock.value
    unplanned.value = unplanned.value.filter((item) => item.id !== requirement.id)
    ElMessage.success('需求已加入版本范围')
    emit('changed')
  } catch (error) {
    if (error?.response?.data?.error_code === 'STALE_VERSION') {
      emit('stale', error.response.data.message)
      return
    }
    ElMessage.error(error?.response?.data?.message ?? '规划需求失败')
  } finally {
    busyKey.value = ''
    emit('busy', false)
  }
}

async function unplan(scopeItem) {
  if (!canMutate.value || props.disabled || busyKey.value) return
  if (!validateReason()) return

  const requirementId = scopeItem.requirement_id ?? scopeItem.requirement?.id
  const key = `unplan-${requirementId}`
  busyKey.value = key
  emit('busy', true)
  try {
    const response = await unplanRequirementVersion(
      requirementId,
      props.version.project.id,
      mutationPayload(),
    )
    currentLock.value = response?.data?.data?.lock_version ?? currentLock.value
    ElMessage.success('需求已移回未规划池')
    emit('changed')
  } catch (error) {
    if (error?.response?.data?.error_code === 'STALE_VERSION') {
      emit('stale', error.response.data.message)
      return
    }
    ElMessage.error(error?.response?.data?.message ?? '移出版本范围失败')
  } finally {
    busyKey.value = ''
    emit('busy', false)
  }
}

watch(
  () => props.version.lock_version,
  (value) => {
    currentLock.value = value
  },
)

watch(
  () => [props.version.id, props.version.status_code],
  () => loadPool(),
)

onMounted(loadPool)
</script>

<template>
  <section class="requirement-planner" aria-label="版本需求范围">
    <header class="planner-heading">
      <div>
        <h2>版本范围</h2>
        <p>单个项目需求只能规划到一个目标版本。</p>
      </div>
      <span v-if="isReadOnly" class="readonly-badge">只读</span>
      <el-tooltip v-else content="刷新未规划需求" placement="top">
        <el-button
          aria-label="刷新未规划需求"
          :icon="RefreshLeft"
          :loading="loading"
          :disabled="disabled || Boolean(busyKey)"
          @click="refreshPool"
        />
      </el-tooltip>
    </header>

    <div v-if="requiresReason && canMutate" class="reason-band">
      <label for="planning-reason">范围调整原因</label>
      <el-input
        id="planning-reason"
        v-model="reason"
        data-testid="planning-reason"
        maxlength="1000"
        show-word-limit
        placeholder="测试阶段调整范围时必填"
      />
    </div>

    <div class="planner-columns" :class="{ 'is-readonly': isReadOnly }">
      <section class="scope-column">
        <div class="column-heading">
          <h3>已规划需求</h3>
          <span>{{ effectiveScope.length }} 项</span>
        </div>
        <div v-if="effectiveScope.length === 0" class="column-empty">
          当前版本范围为空
        </div>
        <article
          v-for="item in effectiveScope"
          :key="item.requirement_project_id ?? item.requirement_id"
          class="requirement-row"
        >
          <div>
            <strong>{{ item.requirement?.title || `需求 #${item.requirement_id}` }}</strong>
            <span>#{{ item.requirement_id }}</span>
          </div>
          <el-button
            v-if="canMutate"
            :data-testid="`unplan-requirement-${item.requirement_id}`"
            type="danger"
            link
            :icon="Remove"
            :loading="busyKey === `unplan-${item.requirement_id}`"
            :disabled="disabled || Boolean(busyKey)"
            @click="unplan(item)"
          >
            移出
          </el-button>
        </article>
      </section>

      <section v-if="canMutate" class="scope-column">
        <div class="column-heading">
          <h3>未规划需求</h3>
          <span>{{ unplanned.length }} 项</span>
        </div>
        <div v-if="loading" class="column-empty">正在加载...</div>
        <div v-else-if="unplanned.length === 0" class="column-empty">
          没有可加入的需求
        </div>
        <article
          v-for="requirement in unplanned"
          :key="requirement.id"
          class="requirement-row"
        >
          <div>
            <strong>{{ requirement.title }}</strong>
            <span>{{ requirement.priority_label || '-' }} · {{ requirement.status_label || '-' }}</span>
          </div>
          <el-button
            :data-testid="`plan-requirement-${requirement.id}`"
            type="primary"
            link
            :icon="Plus"
            :loading="busyKey === `plan-${requirement.id}`"
            :disabled="disabled || Boolean(busyKey)"
            @click="plan(requirement)"
          >
            加入
          </el-button>
        </article>
      </section>
    </div>
  </section>
</template>

<style scoped lang="scss">
.requirement-planner {
  width: 100%;
}

.planner-heading,
.column-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.planner-heading {
  padding-bottom: 16px;
  border-bottom: 1px solid $color-border;

  h2,
  p {
    margin: 0;
  }

  h2 {
    color: $color-ink;
    font-size: $font-size-h3;
  }

  p {
    margin-top: 4px;
    color: $color-muted;
    font-size: $font-size-small;
  }
}

.readonly-badge {
  padding: 3px 8px;
  border: 1px solid $color-border;
  border-radius: 4px;
  color: $color-muted;
  background: $color-canvas;
  font-size: $font-size-caption;
}

.reason-band {
  display: grid;
  grid-template-columns: 132px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  padding: 14px 0;
  border-bottom: 1px solid $color-border;

  label {
    color: $color-ink;
    font-size: $font-size-small;
    font-weight: 600;
  }
}

.planner-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px;
  padding-top: 18px;

  &.is-readonly {
    grid-template-columns: minmax(0, 1fr);
  }
}

.scope-column {
  min-width: 0;
}

.column-heading {
  margin-bottom: 10px;

  h3 {
    margin: 0;
    color: $color-ink;
    font-size: $font-size-body;
  }

  span {
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.requirement-row {
  display: flex;
  min-height: 58px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid $color-border;

  div {
    min-width: 0;
  }

  strong,
  span {
    display: block;
  }

  strong {
    overflow: hidden;
    color: $color-ink;
    font-size: $font-size-small;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  span {
    margin-top: 4px;
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.column-empty {
  display: grid;
  min-height: 100px;
  place-items: center;
  color: $color-muted;
  background: $color-canvas;
  font-size: $font-size-small;
}

@media (max-width: 820px) {
  .planner-columns {
    grid-template-columns: minmax(0, 1fr);
  }

  .reason-band {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
