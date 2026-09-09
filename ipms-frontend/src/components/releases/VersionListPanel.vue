<script setup>
import { View } from '@element-plus/icons-vue'
import VersionStatusTag from './VersionStatusTag.vue'

defineProps({
  versions: {
    type: Array,
    default: () => [],
  },
  busyId: {
    type: [Number, String],
    default: null,
  },
})

defineEmits(['open'])

function gateSummary(version) {
  if (version.gate_result?.passed === true) return '门禁通过'
  if (version.gate_result?.passed === false) {
    return `${version.gate_result.blocking?.length ?? 0} 项阻断`
  }
  if (['RELEASED', 'ARCHIVED'].includes(version.status_code)) return '已完成'
  return '进入详情检查'
}
</script>

<template>
  <div v-if="versions.length === 0" class="version-empty">
    <p>暂无发布版本</p>
    <span>当前筛选条件下没有版本记录</span>
  </div>

  <div v-else class="version-table-scroll">
    <table class="version-table">
      <thead>
        <tr>
          <th>版本</th>
          <th>状态</th>
          <th>负责人</th>
          <th>计划发布日期</th>
          <th class="number-column">需求</th>
          <th>门禁摘要</th>
          <th class="action-column">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="version in versions" :key="version.id">
          <td>
            <span class="version-code">{{ version.code }}</span>
            <span class="version-name">{{ version.name }}</span>
          </td>
          <td>
            <VersionStatusTag
              :status-code="version.status_code"
              :status-label="version.status_label"
            />
          </td>
          <td>{{ version.owner?.display_name || '-' }}</td>
          <td>{{ version.planned_release_date || '-' }}</td>
          <td class="number-column">{{ version.counts?.requirements ?? 0 }}</td>
          <td>
            <span
              class="gate-summary"
              :class="{ 'gate-summary--blocked': version.gate_result?.passed === false }"
            >
              {{ gateSummary(version) }}
            </span>
          </td>
          <td class="action-column">
            <el-button
              :data-testid="`open-version-${version.id}`"
              type="primary"
              link
              :icon="View"
              :loading="busyId === version.id"
              @click="$emit('open', version)"
            >
              查看
            </el-button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped lang="scss">
.version-empty {
  display: grid;
  min-height: 220px;
  place-content: center;
  text-align: center;
  color: $color-muted;

  p {
    margin-bottom: 6px;
    color: $color-ink;
    font-size: $font-size-h3;
    font-weight: 600;
  }

  span {
    font-size: $font-size-small;
  }
}

.version-table-scroll {
  width: 100%;
  overflow-x: auto;
}

.version-table {
  width: 100%;
  min-width: 850px;
  border-collapse: collapse;
  table-layout: fixed;

  th,
  td {
    padding: 13px 12px;
    border-bottom: 1px solid $color-border;
    text-align: left;
    vertical-align: middle;
  }

  th {
    color: $color-muted;
    background: $color-canvas;
    font-size: $font-size-caption;
    font-weight: 600;
  }

  td {
    color: $color-ink;
    font-size: $font-size-small;
  }

  tbody tr:hover {
    background: #f8fafc;
  }
}

.version-code,
.version-name {
  display: block;
}

.version-code {
  color: $color-primary;
  font-weight: 700;
}

.version-name {
  margin-top: 3px;
  color: $color-muted;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.number-column {
  width: 74px;
  text-align: center !important;
}

.action-column {
  width: 82px;
  text-align: right !important;
}

.gate-summary {
  color: $color-muted;
  font-size: $font-size-caption;

  &--blocked {
    color: $color-danger;
    font-weight: 600;
  }
}
</style>
