<script setup>
import { computed, ref, watch } from 'vue'
import { WarningFilled } from '@element-plus/icons-vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'transition',
    validator: (value) => ['transition', 'release', 'force'].includes(value),
  },
  version: {
    type: Object,
    default: null,
  },
  targetStatus: {
    type: Object,
    default: null,
  },
  busy: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'confirm'])

const reason = ref('')
const releaseNotes = ref('')
const localError = ref('')

const isRollback = computed(() => (
  props.mode === 'transition'
  && Number(props.targetStatus?.value) < Number(props.version?.status)
))

const title = computed(() => {
  if (props.mode === 'force') return '确认强制发布'
  if (props.mode === 'release') return '确认发布'
  return `确认变更为${props.targetStatus?.label ?? '目标状态'}`
})

const confirmLabel = computed(() => {
  if (props.mode === 'force') return '强制发布'
  if (props.mode === 'release') return '正式发布'
  return '确认变更'
})

function reset() {
  reason.value = ''
  releaseNotes.value = props.version?.release_notes ?? ''
  localError.value = ''
}

function close() {
  if (props.busy) return
  emit('update:modelValue', false)
}

function submit() {
  localError.value = ''
  const normalizedReason = reason.value.trim()

  if (props.mode === 'force' && !normalizedReason) {
    localError.value = '强制发布原因不能为空'
    return
  }
  if (isRollback.value && !normalizedReason) {
    localError.value = '状态回退必须填写原因'
    return
  }

  if (props.mode === 'transition') {
    emit('confirm', {
      status: props.targetStatus?.value,
      lock_version: props.version?.lock_version,
      ...(normalizedReason ? { reason: normalizedReason } : {}),
    })
    return
  }

  emit('confirm', {
    lock_version: props.version?.lock_version,
    force: props.mode === 'force',
    release_notes: releaseNotes.value.trim() || null,
    ...(props.mode === 'force' ? { force_reason: normalizedReason } : {}),
  })
}

watch(
  () => props.modelValue,
  (visible) => {
    if (visible) reset()
  },
  { immediate: true },
)
</script>

<template>
  <div
    v-if="modelValue"
    class="command-overlay"
    role="presentation"
    @click.self="close"
  >
    <section
      class="command-dialog"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
    >
      <header>
        <div>
          <span>版本 {{ version?.code }}</span>
          <h2>{{ title }}</h2>
        </div>
        <button
          type="button"
          class="close-button"
          aria-label="关闭"
          :disabled="busy"
          @click="close"
        >
          ×
        </button>
      </header>

      <div v-if="mode === 'transition'" class="impact-band">
        <strong>{{ version?.status_label }} → {{ targetStatus?.label }}</strong>
        <p>
          {{ isRollback ? '状态回退将写入版本审计历史。' : '状态推进前将重新检查目标状态门禁。' }}
        </p>
      </div>

      <div v-if="mode === 'force'" class="warning-band">
        <WarningFilled aria-hidden="true" />
        <div>
          <strong>超管例外发布</strong>
          <p>该操作会绕过未通过的门禁，并记录操作人、原因和发布快照。</p>
        </div>
      </div>

      <label v-if="mode !== 'transition'" class="form-field">
        <span>发布说明</span>
        <el-input
          v-model="releaseNotes"
          type="textarea"
          :rows="4"
          maxlength="10000"
          placeholder="填写本次发布说明"
        />
      </label>

      <label v-if="mode === 'force'" class="form-field">
        <span>强制发布原因</span>
        <el-input
          v-model="reason"
          data-testid="force-reason"
          type="textarea"
          :rows="3"
          maxlength="1000"
          placeholder="必填，将进入审计记录"
        />
      </label>

      <label v-if="mode === 'transition'" class="form-field">
        <span>{{ isRollback ? '回退原因' : '变更说明' }}</span>
        <el-input
          v-model="reason"
          type="textarea"
          :rows="3"
          maxlength="1000"
          :placeholder="isRollback ? '必填' : '选填'"
        />
      </label>

      <p v-if="localError" class="form-error" role="alert">
        {{ localError }}
      </p>

      <footer>
        <el-button :disabled="busy" @click="close">取消</el-button>
        <el-button
          data-testid="confirm-release-command"
          :type="mode === 'force' ? 'danger' : 'primary'"
          :loading="busy"
          @click="submit"
        >
          {{ confirmLabel }}
        </el-button>
      </footer>
    </section>
  </div>
</template>

<style scoped lang="scss">
.command-overlay {
  position: fixed;
  z-index: 2100;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(23 33 43 / 45%);
}

.form-error {
  margin: 12px 0 0;
  padding: 9px 11px;
  border-left: 3px solid #c43d3d;
  color: #c43d3d;
  background: #fff1f1;
  font-size: 13px;
}

.command-dialog {
  width: min(560px, 100%);
  max-height: calc(100vh - 48px);
  overflow-y: auto;
  border-radius: $border-radius-md;
  background: #fff;
  box-shadow: 0 18px 48px rgb(23 33 43 / 22%);

  > header,
  > footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px;
  }

  > header {
    border-bottom: 1px solid $color-border;

    span {
      color: $color-muted;
      font-size: $font-size-caption;
    }

    h2 {
      margin: 3px 0 0;
      color: $color-ink;
      font-size: $font-size-h3;
    }
  }

  > footer {
    justify-content: flex-end;
    border-top: 1px solid $color-border;
  }
}

.close-button {
  display: grid;
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  place-items: center;
  border: 0;
  color: $color-muted;
  background: transparent;
  cursor: pointer;
  font-size: 24px;
  line-height: 1;

  &:hover {
    color: $color-ink;
    background: $color-canvas;
  }
}

.impact-band,
.warning-band {
  margin: 18px 20px 0;
  padding: 13px 14px;
  border-left: 3px solid $color-primary;
  background: $color-primary-soft;

  strong {
    color: $color-ink;
    font-size: $font-size-small;
  }

  p {
    margin: 4px 0 0;
    color: $color-muted;
    font-size: $font-size-caption;
  }
}

.warning-band {
  display: grid;
  grid-template-columns: 20px minmax(0, 1fr);
  gap: 10px;
  border-left-color: $color-danger;
  background: #fff1f1;

  > svg {
    width: 18px;
    color: $color-danger;
  }
}

.form-field {
  display: grid;
  gap: 7px;
  margin: 18px 20px;

  > span {
    color: $color-ink;
    font-size: $font-size-small;
    font-weight: 600;
  }
}

:deep(.el-alert) {
  width: auto;
  margin: 0 20px 18px;
}
</style>
