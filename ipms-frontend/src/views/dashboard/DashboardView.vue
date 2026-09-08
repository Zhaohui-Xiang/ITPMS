<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Calendar } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { getDashboardSummary } from '@/api/dashboard'
import AsyncState from '@/components/common/AsyncState.vue'

const router = useRouter()
const authStore = useAuthStore()

const loading = ref(true)
const error = ref(null)
const summary = ref({
  metrics: [],
  priority_queue: [],
  release_risks: [],
})

const roleLabels = {
  super_admin: '超级管理员',
  it_pm: '内部 IT 项目经理',
  it_member: '内部 IT 项目成员',
  supplier_pm: '供应商项目经理',
  supplier_dev: '供应商开发人员',
  supplier_tester: '供应商测试人员',
  requester: '系统用户',
}

const typeLabels = {
  requirement: '需求',
  task: '任务',
  defect: '缺陷',
  project_version: '版本',
}

const severityLabels = {
  critical: '紧急',
  high: '高',
  medium: '中',
  low: '低',
}

const currentRoleLabel = computed(() => (
  roleLabels[authStore.currentRole] ?? authStore.currentRole
))

async function loadSummary() {
  loading.value = true
  error.value = null

  try {
    const response = await getDashboardSummary()
    summary.value = response.data.data
  } catch (requestError) {
    error.value = requestError
  } finally {
    loading.value = false
  }
}

function navigate(targetUrl) {
  if (targetUrl) {
    router.push(targetUrl)
  }
}

function severityType(severity) {
  return {
    critical: 'danger',
    high: 'warning',
    medium: '',
    low: 'info',
  }[severity] ?? 'info'
}

function formatDate(value) {
  return value ? String(value).slice(0, 10) : ''
}

onMounted(loadSummary)
</script>

<template>
  <div class="dashboard-page">
    <header class="page-heading">
      <div>
        <h1>工作台</h1>
        <p>当前职责范围内的待办与发布风险</p>
      </div>
      <el-tag
        data-testid="dashboard-role"
        effect="plain"
        size="small"
      >
        {{ currentRoleLabel }}
      </el-tag>
    </header>

    <AsyncState
      :loading="loading"
      :error="error"
      @retry="loadSummary"
    >
      <section
        v-if="summary.metrics.length"
        class="metric-strip"
        aria-label="工作台指标"
      >
        <button
          v-for="metric in summary.metrics"
          :key="metric.key"
          :data-testid="`metric-${metric.key}`"
          class="metric-tile"
          type="button"
          @click="navigate(metric.target_url)"
        >
          <span class="metric-value">{{ metric.value }}</span>
          <span class="metric-label">{{ metric.label }}</span>
        </button>
      </section>

      <div class="work-grid">
        <section class="work-band" aria-labelledby="priority-heading">
          <header class="band-heading">
            <div>
              <h2 id="priority-heading">优先处理</h2>
              <span>{{ summary.priority_queue.length }} 项</span>
            </div>
          </header>

          <ul v-if="summary.priority_queue.length" class="queue-list">
            <li
              v-for="item in summary.priority_queue"
              :key="`${item.type}-${item.id}`"
              class="queue-item"
            >
              <div class="queue-copy">
                <div class="queue-meta">
                  <el-tag :type="severityType(item.severity)" size="small">
                    {{ severityLabels[item.severity] ?? item.severity }}
                  </el-tag>
                  <span>{{ typeLabels[item.type] ?? item.type }}</span>
                  <span v-if="item.project">{{ item.project }}</span>
                </div>
                <strong>{{ item.title }}</strong>
                <span v-if="item.due_at" class="queue-date">
                  <el-icon><Calendar /></el-icon>
                  {{ formatDate(item.due_at) }}
                </span>
              </div>
              <el-button
                :data-testid="`queue-link-${item.type}-${item.id}`"
                class="queue-link"
                text
                circle
                :aria-label="`打开${item.title}`"
                @click="navigate(item.target_url)"
              >
                <el-icon><ArrowRight /></el-icon>
              </el-button>
            </li>
          </ul>
          <el-empty v-else description="暂无优先事项" :image-size="72" />
        </section>

        <section class="work-band work-band--risk" aria-labelledby="risk-heading">
          <header class="band-heading">
            <div>
              <h2 id="risk-heading">发布风险</h2>
              <span>{{ summary.release_risks.length }} 项</span>
            </div>
          </header>

          <ul v-if="summary.release_risks.length" class="queue-list">
            <li
              v-for="item in summary.release_risks"
              :key="`${item.type}-${item.id}`"
              class="queue-item"
            >
              <div class="queue-copy">
                <div class="queue-meta">
                  <el-tag :type="severityType(item.severity)" size="small">
                    {{ severityLabels[item.severity] ?? item.severity }}
                  </el-tag>
                  <span>{{ item.project }}</span>
                </div>
                <strong>{{ item.title }}</strong>
                <span v-if="item.due_at" class="queue-date">
                  <el-icon><Calendar /></el-icon>
                  {{ formatDate(item.due_at) }}
                </span>
              </div>
              <el-button
                :data-testid="`queue-link-${item.type}-${item.id}`"
                class="queue-link"
                text
                circle
                :aria-label="`打开${item.title}`"
                @click="navigate(item.target_url)"
              >
                <el-icon><ArrowRight /></el-icon>
              </el-button>
            </li>
          </ul>
          <el-empty v-else description="暂无发布风险" :image-size="72" />
        </section>
      </div>
    </AsyncState>
  </div>
</template>

<style scoped lang="scss">
.dashboard-page {
  min-width: 0;
}

.page-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;

  h1 {
    margin: 0;
    color: $color-ink;
    font-size: $font-size-h1;
    line-height: 32px;
  }

  p {
    margin: 3px 0 0;
    color: $color-muted;
    font-size: $font-size-small;
    line-height: 20px;
  }
}

.metric-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
  gap: 10px;
  margin-bottom: 22px;
}

.metric-tile {
  display: flex;
  min-width: 0;
  min-height: 82px;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  padding: 12px 15px;
  border: 1px solid $color-border;
  border-radius: 6px;
  background: #fff;
  color: $color-ink;
  cursor: pointer;
  font: inherit;
  text-align: left;
  transition: border-color 0.2s, background-color 0.2s;

  &:hover,
  &:focus-visible {
    border-color: $color-primary;
    background: $color-primary-soft;
    outline: none;
  }
}

.metric-value {
  font-size: 26px;
  font-weight: 700;
  line-height: 30px;
}

.metric-label {
  margin-top: 5px;
  color: $color-muted;
  font-size: $font-size-small;
  line-height: 18px;
}

.work-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 24px;
}

.work-band {
  min-width: 0;
  border-top: 2px solid $color-primary;
  background: #fff;

  &--risk {
    border-top-color: $color-warning;
  }

  :deep(.el-empty) {
    min-height: 230px;
    padding: 32px 16px;
  }
}

.band-heading {
  min-height: 54px;
  padding: 13px 4px 11px;
  border-bottom: 1px solid $color-border;

  > div {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
  }

  h2 {
    margin: 0;
    color: $color-ink;
    font-size: $font-size-h3;
    line-height: 24px;
  }

  span {
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.queue-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.queue-item {
  display: flex;
  min-height: 82px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 4px;
  border-bottom: 1px solid $color-border;

  &:last-child {
    border-bottom: 0;
  }
}

.queue-copy {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 5px;

  strong {
    overflow: hidden;
    color: $color-ink;
    font-size: $font-size-body;
    font-weight: 600;
    line-height: 20px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.queue-meta {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 8px;
  color: $color-muted;
  font-size: $font-size-caption;

  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.queue-date {
  display: flex;
  align-items: center;
  gap: 4px;
  color: $color-muted;
  font-size: $font-size-caption;
  line-height: 18px;
}

.queue-link {
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  color: $color-primary;

  &:hover {
    background: $color-primary-soft;
  }
}

@media (max-width: 1180px) {
  .work-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .queue-copy strong {
    white-space: normal;
  }
}
</style>
