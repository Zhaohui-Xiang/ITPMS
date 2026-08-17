const requirementTransitionStatuses = Object.freeze({
  start_dev: 3,
  complete_dev: 4,
  pass_test: 5,
  fail_test: 3,
  confirm_online: 6,
  confirm_accept: 7,
})

const requirementActionsByStatus = Object.freeze({
  pending_review: Object.freeze([
    { key: 'approve', label: '审核通过', type: 'success' },
    { key: 'reject', label: '驳回', type: 'danger', plain: true },
  ]),
  assigned: Object.freeze([
    { key: 'start_dev', label: '开始开发', type: 'primary' },
  ]),
  developing: Object.freeze([
    { key: 'complete_dev', label: '完成开发', type: 'success' },
  ]),
  testing: Object.freeze([
    { key: 'pass_test', label: '测试通过', type: 'success' },
    { key: 'fail_test', label: '打回开发', type: 'danger', plain: true },
  ]),
  pending_online: Object.freeze([
    { key: 'confirm_online', label: '确认上线', type: 'success' },
  ]),
  online: Object.freeze([
    { key: 'confirm_accept', label: '发起验收', type: 'success' },
  ]),
  accepted: Object.freeze([]),
})

const taskTransitionStatuses = Object.freeze({
  start: 2,
  complete: 3,
  resume: 2,
})

function statusPayload(statuses, action, workflow) {
  if (!Object.prototype.hasOwnProperty.call(statuses, action)) {
    throw new RangeError(`Unsupported ${workflow} action: ${action}`)
  }

  return { status: statuses[action] }
}

export function getRequirementStatusActions(status) {
  return requirementActionsByStatus[status] || []
}

export function requirementTransitionPayload(action) {
  return statusPayload(requirementTransitionStatuses, action, 'requirement')
}

export function taskTransitionPayload(action) {
  return statusPayload(taskTransitionStatuses, action, 'task')
}

export class RequirementActionValidationError extends Error {
  constructor(message) {
    super(message)
    this.name = 'RequirementActionValidationError'
  }
}

export function getRequirementCommentField(action) {
  if (action === 'reject') {
    return {
      label: '驳回备注',
      placeholder: '请输入驳回原因（必填）',
      required: true,
    }
  }

  return {
    label: '操作备注',
    placeholder: '请输入备注（可选）',
    required: false,
  }
}

export function requirementReviewPayload(action, comment) {
  if (action !== 'approve' && action !== 'reject') {
    throw new RangeError(`Unsupported requirement review action: ${action}`)
  }

  const trimmedComment = typeof comment === 'string' ? comment.trim() : ''
  if (action === 'reject' && !trimmedComment) {
    throw new RequirementActionValidationError('驳回时必须填写审核意见')
  }

  return trimmedComment ? { action, comment: trimmedComment } : { action }
}

export async function executeRequirementAction(id, action, comment, api) {
  if (action === 'approve' || action === 'reject') {
    return api.reviewRequirement(id, requirementReviewPayload(action, comment))
  }

  return api.transitionRequirement(id, requirementTransitionPayload(action))
}
