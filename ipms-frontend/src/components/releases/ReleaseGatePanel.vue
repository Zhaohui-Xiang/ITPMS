<script setup>
import { computed } from 'vue'
import { CircleCheck, CircleClose, Link as LinkIcon, Right } from '@element-plus/icons-vue'

const props = defineProps({
  result: {
    type: Object,
    default: null,
  },
  projectId: {
    type: [Number, String],
    default: null,
  },
  versionId: {
    type: [Number, String],
    default: null,
  },
})

const emit = defineEmits(['resolve'])

// 门禁项中文化与解决引导：version-field=动作型（打开版本编辑弹窗聚焦字段），
// records=记录型（列出具体记录并可跳转详情），link=仅提供列表入口
const CHECK_META = {
  version_metadata: {
    label: '版本负责人与计划发布日期已设置',
    kind: 'version-field',
    resolveLabel: '前往补充',
  },
  release_notes_present: {
    label: '发布说明已填写',
    kind: 'version-field',
    field: 'release_notes',
    resolveLabel: '前往填写发布说明',
  },
  non_empty_scope: {
    label: '版本范围非空',
    kind: 'link',
    target: '/requirements',
  },
  reviewed_assigned_scope: {
    label: '范围内需求已审核且有执行负责人',
    kind: 'link',
    target: '/requirements',
  },
  project_delivery: {
    label: '项目交付进度达到目标阶段',
    kind: 'link',
    target: '/requirements',
  },
  tasks_completed: {
    label: '范围任务全部完成',
    kind: 'records',
    field: 'incomplete_task_ids',
    path: '/tasks/',
    recordLabel: '任务',
    listTarget: '/tasks',
  },
  severe_defects_closed: {
    label: '严重缺陷全部关闭',
    kind: 'records',
    field: 'open_defect_ids',
    path: '/defects/',
    recordLabel: '缺陷',
    listTarget: '/defects',
  },
  acceptance_complete: {
    label: '范围内需求全部验收完成',
    kind: 'link',
    target: '/requirements',
  },
}

const visibleChecks = computed(() => {
  const checks = props.result?.checks ?? []
  const applicable = checks.filter((check) => (
    check.blocking || check.details?.applicable
  ))

  if (applicable.length > 0) return applicable
  return props.result?.blocking ?? []
})

const failedChecks = computed(() => visibleChecks.value.filter((check) => !check.passed))

function checkCount(check) {
  const details = check.details ?? {}
  const countFields = [
    'incomplete_count',
    'open_count',
    'requirement_project_count',
    'relevant_count',
  ]
  const direct = countFields.find((field) => Number.isFinite(Number(details[field])))
  if (direct) return Number(details[direct])

  const listFields = [
    'missing',
    'unapproved_requirement_project_ids',
    'missing_execution_owner_requirement_project_ids',
    'failing_requirement_project_ids',
    'incomplete_task_ids',
    'open_defect_ids',
  ]

  return listFields.reduce((total, field) => (
    total + (Array.isArray(details[field]) ? details[field].length : 0)
  ), 0)
}

function metaFor(check) {
  return CHECK_META[check.code] ?? { label: check.label, kind: 'link', target: null }
}

function labelFor(check) {
  return metaFor(check).label ?? check.label
}

function resolveFieldFor(check) {
  if (check.code === 'version_metadata') {
    const missing = check.details?.missing ?? []
    return missing.includes('owner_id') ? 'owner' : 'planned_release_date'
  }
  return metaFor(check).field
}

function recordIdsFor(check) {
  const field = metaFor(check).field
  const ids = check.details?.[field]
  return Array.isArray(ids) ? ids.slice(0, 8) : []
}

function totalRecordsFor(check) {
  const field = metaFor(check).field
  const ids = check.details?.[field]
  return Array.isArray(ids) ? ids.length : 0
}

function targetFor(check) {
  const meta = metaFor(check)
  const base = meta.listTarget ?? meta.target
  if (!base) return null
  const query = new URLSearchParams({
    project_id: String(props.projectId ?? ''),
    project_version_id: String(props.versionId ?? ''),
  }).toString()

  return `${base}?${query}`
}

function stateLabel(check) {
  if (check.passed) return '通过'
  return check.blocking ? '阻断' : '未通过'
}
</script>

<template>
  <section class="gate-panel" aria-label="发布门禁">
    <header v-if="result" class="gate-panel__summary">
      <div>
        <span class="eyebrow">目标状态门禁</span>
        <h2>{{ result?.passed ? '当前门禁已通过' : '当前存在发布阻断' }}</h2>
      </div>
      <span
        class="gate-result"
        :class="result?.passed ? 'is-passed' : 'is-blocked'"
      >
        {{ result?.passed ? '通过' : `${failedChecks.length} 项阻断` }}
      </span>
    </header>

    <div v-if="!result" class="gate-unavailable">
      当前角色不可查看发布门禁结果
    </div>

    <div v-else-if="visibleChecks.length > 0" class="gate-list">
      <article
        v-for="check in visibleChecks"
        :key="check.code"
        class="gate-item"
        :class="{ 'is-failed': !check.passed }"
      >
        <component
          :is="check.passed ? CircleCheck : CircleClose"
          class="gate-item__icon"
          aria-hidden="true"
        />
        <div class="gate-item__content">
          <div class="gate-item__heading">
            <strong>{{ labelFor(check) }}</strong>
            <span>{{ stateLabel(check) }}</span>
          </div>
          <template v-if="!check.passed">
            <p v-if="checkCount(check) > 0">
              影响记录 {{ checkCount(check) }} 项，请处理后重新检查。
            </p>
            <ul
              v-if="metaFor(check).kind === 'records' && recordIdsFor(check).length > 0"
              class="gate-item__records"
            >
              <li v-for="id in recordIdsFor(check)" :key="id">
                <a :href="`${metaFor(check).path}${id}`" :data-testid="`gate-record-${check.code}-${id}`">
                  {{ metaFor(check).recordLabel }} #{{ id }}
                </a>
              </li>
              <li v-if="totalRecordsFor(check) > recordIdsFor(check).length" class="gate-item__more">
                其余 {{ totalRecordsFor(check) - recordIdsFor(check).length }} 项请从列表查看
              </li>
            </ul>
          </template>
        </div>
        <button
          v-if="!check.passed && metaFor(check).kind === 'version-field'"
          type="button"
          class="gate-item__link gate-item__resolve"
          :data-testid="`gate-resolve-${check.code}`"
          @click="emit('resolve', { code: check.code, field: resolveFieldFor(check) })"
        >
          <Right aria-hidden="true" />
          {{ metaFor(check).resolveLabel }}
        </button>
        <a
          v-else-if="targetFor(check)"
          :href="targetFor(check)"
          :data-testid="`gate-link-${check.code}`"
          class="gate-item__link"
        >
          <LinkIcon aria-hidden="true" />
          查看
        </a>
      </article>
    </div>

    <div v-else class="gate-empty">
      <CircleCheck aria-hidden="true" />
      <span>当前目标状态没有需要处理的门禁项</span>
    </div>
  </section>
</template>

<style scoped lang="scss">
.gate-panel {
  width: 100%;
}

.gate-panel__summary {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid $color-border;

  h2 {
    margin: 3px 0 0;
    color: $color-ink;
    font-size: $font-size-h3;
  }
}

.eyebrow {
  color: $color-muted;
  font-size: $font-size-caption;
}

.gate-result {
  flex: 0 0 auto;
  padding: 4px 9px;
  border: 1px solid;
  border-radius: 4px;
  font-size: $font-size-caption;
  font-weight: 700;

  &.is-passed {
    color: $color-success;
    background: #edf8f0;
    border-color: #b7dfc2;
  }

  &.is-blocked {
    color: $color-danger;
    background: #fff1f1;
    border-color: #f0b8b8;
  }
}

.gate-list {
  display: grid;
}

.gate-item {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) auto;
  align-items: start;
  gap: 12px;
  padding: 16px 0;
  border-bottom: 1px solid $color-border;

  &:last-child {
    border-bottom: 0;
  }

  &__icon {
    width: 20px;
    color: $color-success;
  }

  &.is-failed &__icon {
    color: $color-danger;
  }

  &__heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;

    strong {
      color: $color-ink;
      font-size: $font-size-body;
    }

    span {
      color: $color-muted;
      font-size: $font-size-caption;
    }
  }

  p {
    margin: 5px 0 0;
    color: $color-muted;
    font-size: $font-size-small;
  }

  &__link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: $color-primary;
    font-size: $font-size-small;
    text-decoration: none;

    svg {
      width: 15px;
    }
  }

  &__resolve {
    padding: 0;
    background: none;
    border: 0;
    cursor: pointer;
    font-family: inherit;
  }

  &__records {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    margin: 8px 0 0;
    padding: 0;
    list-style: none;

    a {
      color: $color-primary;
      font-size: $font-size-caption;
      text-decoration: none;

      &:hover {
        text-decoration: underline;
      }
    }
  }

  &__more {
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.gate-empty,
.gate-unavailable {
  display: flex;
  min-height: 120px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: $color-success;

  svg {
    width: 20px;
  }
}

@media (max-width: 680px) {
  .gate-panel__summary {
    align-items: stretch;
    flex-direction: column;
  }

  .gate-result {
    align-self: flex-start;
  }

  .gate-item {
    grid-template-columns: 24px minmax(0, 1fr);
  }

  .gate-item__link {
    grid-column: 2;
  }
}
</style>
