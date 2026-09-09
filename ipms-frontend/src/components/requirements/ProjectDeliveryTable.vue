<script setup>
import { computed } from 'vue'
import { ArrowRight, View } from '@element-plus/icons-vue'

const props = defineProps({
  deliveries: {
    type: Array,
    default: () => [],
  },
})

defineEmits(['transition'])

const nextLabels = {
  ASSIGNED: '推进至开发中',
  IN_DEVELOPMENT: '推进至测试中',
  IN_TESTING: '推进至待上线',
  DEPLOYED: '确认验收',
}

const rows = computed(() => props.deliveries.filter((item) => item.project))

function progressValue(delivery) {
  const total = Number(delivery.task_progress?.total ?? 0)
  const completed = Number(delivery.task_progress?.completed ?? 0)
  if (total === 0) return 0
  return Math.min(Math.round((completed / total) * 100), 100)
}

function canOpenVersion(delivery) {
  return delivery.can_view_project !== false
    && Boolean(delivery.target_version?.id)
}

function canTransition(delivery) {
  return (delivery.allowed_actions ?? []).includes('transition')
    && Boolean(nextLabels[delivery.delivery_status_code])
}

function deliveryClass(code) {
  return `delivery-status--${String(code ?? 'unknown').toLowerCase()}`
}
</script>

<template>
  <div v-if="rows.length === 0" class="delivery-empty">
    暂无项目交付记录
  </div>

  <div v-else class="delivery-table-scroll">
    <table class="delivery-table">
      <thead>
        <tr>
          <th>项目</th>
          <th>交付状态</th>
          <th>目标版本</th>
          <th>项目负责人</th>
          <th>任务进度</th>
          <th>未关闭严重缺陷</th>
          <th class="action-column">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="delivery in rows" :key="delivery.project.id">
          <td>
            <strong>{{ delivery.project.name }}</strong>
          </td>
          <td>
            <span
              class="delivery-status"
              :class="deliveryClass(delivery.delivery_status_code)"
            >
              {{ delivery.delivery_status_label || '-' }}
            </span>
          </td>
          <td>
            <a
              v-if="canOpenVersion(delivery)"
              data-testid="target-version-link"
              class="version-link"
              :href="`/project-versions/${delivery.target_version.id}`"
            >
              <span>{{ delivery.target_version.code }}</span>
              <small>{{ delivery.target_version.name }}</small>
            </a>
            <span v-else class="muted-value">
              {{ delivery.target_version?.code || '未规划' }}
            </span>
          </td>
          <td>{{ delivery.owner?.display_name || '-' }}</td>
          <td>
            <div class="task-progress">
              <span>
                {{ delivery.task_progress?.completed ?? 0 }} /
                {{ delivery.task_progress?.total ?? 0 }}
              </span>
              <progress
                :value="progressValue(delivery)"
                max="100"
                :aria-label="`${delivery.project.name}任务进度`"
              />
            </div>
          </td>
          <td>
            <span
              class="defect-count"
              :class="{ 'has-open': delivery.open_severe_defect_count > 0 }"
            >
              {{ delivery.open_severe_defect_count ?? 0 }}
            </span>
          </td>
          <td class="action-column">
            <el-button
              v-if="canTransition(delivery)"
              :data-testid="`transition-project-${delivery.project.id}`"
              type="primary"
              link
              :icon="ArrowRight"
              @click="$emit('transition', delivery)"
            >
              {{ nextLabels[delivery.delivery_status_code] }}
            </el-button>
            <a
              v-else-if="delivery.can_view_project"
              class="project-link"
              :href="`/projects/${delivery.project.id}`"
              aria-label="查看项目"
            >
              <View aria-hidden="true" />
            </a>
            <span v-else class="muted-value">-</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped lang="scss">
.delivery-empty {
  display: grid;
  min-height: 140px;
  place-items: center;
  color: $color-muted;
  background: $color-canvas;
  font-size: $font-size-small;
}

.delivery-table-scroll {
  width: 100%;
  overflow-x: auto;
}

.delivery-table {
  width: 100%;
  min-width: 1040px;
  border-collapse: collapse;
  table-layout: fixed;

  th,
  td {
    padding: 13px 12px;
    border-bottom: 1px solid $color-border;
    color: $color-ink;
    font-size: $font-size-small;
    text-align: left;
    vertical-align: middle;
  }

  th {
    color: $color-muted;
    background: $color-canvas;
    font-size: $font-size-caption;
    font-weight: 600;
  }

  tbody tr:hover {
    background: #f8fafc;
  }

  strong {
    font-weight: 600;
  }
}

.delivery-status {
  display: inline-flex;
  min-height: 24px;
  align-items: center;
  padding: 2px 8px;
  border: 1px solid $color-border;
  border-radius: 4px;
  color: $color-muted;
  background: #fff;
  font-size: $font-size-caption;
  font-weight: 600;

  &--in_development,
  &--pending_deploy {
    color: $color-warning;
    background: #fff8eb;
    border-color: #efd49b;
  }

  &--in_testing {
    color: #6941c6;
    background: #f9f5ff;
    border-color: #d6bbfb;
  }

  &--deployed,
  &--accepted {
    color: $color-success;
    background: #edf8f0;
    border-color: #b7dfc2;
  }
}

.version-link {
  display: block;
  color: $color-primary;
  text-decoration: none;

  span,
  small {
    display: block;
  }

  span {
    font-weight: 700;
  }

  small {
    margin-top: 3px;
    overflow: hidden;
    color: $color-muted;
    font-size: $font-size-caption;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.task-progress {
  display: grid;
  gap: 6px;

  span {
    color: $color-muted;
    font-size: $font-size-caption;
  }

  progress {
    width: 100%;
    height: 5px;
    border: 0;
    accent-color: $color-primary;
  }
}

.defect-count {
  font-weight: 700;

  &.has-open {
    color: $color-danger;
  }
}

.action-column {
  width: 140px;
  text-align: right !important;
}

.project-link {
  display: inline-grid;
  width: 30px;
  height: 30px;
  place-items: center;
  color: $color-primary;

  svg {
    width: 17px;
  }
}

.muted-value {
  color: $color-muted;
  font-size: $font-size-caption;
}
</style>
