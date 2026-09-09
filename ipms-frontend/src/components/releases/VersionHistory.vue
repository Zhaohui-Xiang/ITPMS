<script setup>
import { computed } from 'vue'
import { Clock, WarningFilled } from '@element-plus/icons-vue'

const props = defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  snapshot: {
    type: Object,
    default: null,
  },
})

const statusLabels = {
  DRAFT: '草稿',
  PLANNED: '已计划',
  IN_DEVELOPMENT: '开发中',
  IN_TESTING: '测试中',
  READY_TO_RELEASE: '待发布',
  RELEASED: '已发布',
  ARCHIVED: '已归档',
}

const orderedItems = computed(() => [...props.items].sort((left, right) => (
  new Date(right.created_at ?? 0) - new Date(left.created_at ?? 0)
)))

function statusLabel(code, fallback) {
  return statusLabels[code] ?? fallback ?? '-'
}

function eventLabel(item) {
  const labels = {
    created: '创建版本',
    updated: '更新版本',
    status_forward: '状态推进',
    status_rollback: '状态回退',
    requirement_assigned: '需求加入范围',
    requirement_unassigned: '需求移出范围',
    release: '正式发布',
    force_release: '强制发布',
  }
  return labels[item.event_type] ?? item.event_type ?? '版本变更'
}

function formatTime(value) {
  if (!value) return '-'
  return new Intl.DateTimeFormat('zh-CN', {
    dateStyle: 'medium',
    timeStyle: 'short',
    hour12: false,
  }).format(new Date(value))
}

function metadataSummary(metadata) {
  if (!metadata || typeof metadata !== 'object') return ''
  const ignored = new Set(['old_values', 'new_values', 'sequence'])
  return Object.entries(metadata)
    .filter(([key, value]) => !ignored.has(key) && value !== null)
    .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : String(value)}`)
    .join(' · ')
}
</script>

<template>
  <section class="version-history" aria-label="版本历史">
    <article v-if="snapshot" class="snapshot-band">
      <div class="snapshot-band__heading">
        <div>
          <span>不可变发布快照</span>
          <strong>{{ snapshot.released_at ? formatTime(snapshot.released_at) : '-' }}</strong>
        </div>
        <span v-if="snapshot.is_override" class="override-mark">
          <WarningFilled aria-hidden="true" />
          超管例外
        </span>
      </div>
      <dl class="snapshot-counts">
        <div>
          <dt>需求范围</dt>
          <dd>{{ snapshot.requirement_scope?.length ?? 0 }}</dd>
        </div>
        <div>
          <dt>任务</dt>
          <dd>{{ snapshot.task_count ?? 0 }}</dd>
        </div>
        <div>
          <dt>缺陷</dt>
          <dd>{{ snapshot.defect_count ?? 0 }}</dd>
        </div>
        <div>
          <dt>发布人</dt>
          <dd>{{ snapshot.released_by?.display_name || '-' }}</dd>
        </div>
      </dl>
      <p v-if="snapshot.override_reason" class="snapshot-reason">
        例外原因：{{ snapshot.override_reason }}
      </p>
    </article>

    <div v-if="orderedItems.length === 0" class="history-empty">
      暂无版本历史
    </div>
    <ol v-else class="history-list">
      <li v-for="item in orderedItems" :key="item.id">
        <span class="history-marker">
          <Clock aria-hidden="true" />
        </span>
        <div class="history-content">
          <header>
            <strong>{{ eventLabel(item) }}</strong>
            <span>{{ formatTime(item.created_at) }}</span>
          </header>
          <p v-if="item.from_status_code || item.to_status_code" class="status-change">
            {{ statusLabel(item.from_status_code, item.from_status) }}
            <span aria-hidden="true">→</span>
            {{ statusLabel(item.to_status_code, item.to_status) }}
          </p>
          <p>
            操作人：{{ item.actor?.display_name || '系统' }}
            <template v-if="item.reason"> · 原因：{{ item.reason }}</template>
          </p>
          <p v-if="metadataSummary(item.metadata)" class="metadata">
            {{ metadataSummary(item.metadata) }}
          </p>
          <span v-if="item.event_type === 'force_release'" class="forced-event">
            已审计强制发布
          </span>
        </div>
      </li>
    </ol>
  </section>
</template>

<style scoped lang="scss">
.version-history {
  width: 100%;
}

.snapshot-band {
  padding: 16px;
  border-left: 3px solid $color-primary;
  background: $color-canvas;

  &__heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;

    span,
    strong {
      display: block;
    }

    span {
      color: $color-muted;
      font-size: $font-size-caption;
    }

    strong {
      margin-top: 3px;
      color: $color-ink;
      font-size: $font-size-small;
    }
  }
}

.override-mark,
.forced-event {
  display: inline-flex !important;
  align-items: center;
  gap: 5px;
  color: $color-danger !important;
  font-weight: 700;

  svg {
    width: 15px;
  }
}

.snapshot-counts {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin: 16px 0 0;

  div {
    min-width: 0;
  }

  dt {
    color: $color-muted;
    font-size: $font-size-caption;
  }

  dd {
    margin: 4px 0 0;
    overflow: hidden;
    color: $color-ink;
    font-size: $font-size-body;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.snapshot-reason {
  margin: 14px 0 0;
  color: $color-danger;
  font-size: $font-size-small;
}

.history-list {
  margin: 18px 0 0;
  padding: 0;
  list-style: none;

  li {
    display: grid;
    grid-template-columns: 30px minmax(0, 1fr);
    gap: 12px;
    padding-bottom: 20px;
  }
}

.history-marker {
  display: grid;
  width: 28px;
  height: 28px;
  place-items: center;
  border: 1px solid $color-border;
  border-radius: 50%;
  color: $color-primary;
  background: #fff;

  svg {
    width: 14px;
  }
}

.history-content {
  min-width: 0;
  padding-bottom: 16px;
  border-bottom: 1px solid $color-border;

  header {
    display: flex;
    justify-content: space-between;
    gap: 16px;

    strong {
      color: $color-ink;
      font-size: $font-size-small;
    }

    span {
      color: $color-muted;
      font-size: $font-size-caption;
    }
  }

  p {
    margin: 6px 0 0;
    color: $color-muted;
    font-size: $font-size-small;
  }

  .status-change {
    color: $color-ink;
    font-weight: 600;
  }

  .metadata {
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: $font-size-caption;
    overflow-wrap: anywhere;
  }
}

.forced-event {
  margin-top: 7px;
  font-size: $font-size-caption;
}

.history-empty {
  display: grid;
  min-height: 140px;
  place-items: center;
  color: $color-muted;
}

@media (max-width: 680px) {
  .snapshot-counts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .history-content header {
    align-items: flex-start;
    flex-direction: column;
    gap: 3px;
  }
}
</style>
