import { describe, expect, it, vi } from 'vitest'
import {
  executeRequirementAction,
  getRequirementCommentField,
  getRequirementStatusActions,
  requirementTransitionPayload,
  taskTransitionPayload,
} from './actionPayloads'

describe('requirement workflow payloads', () => {
  it.each([
    ['start_dev', 3],
    ['complete_dev', 4],
    ['pass_test', 5],
    ['fail_test', 3],
    ['confirm_online', 6],
    ['confirm_accept', 7],
  ])('maps %s to backend status %i', (action, status) => {
    expect(requirementTransitionPayload(action)).toEqual({ status })
  })

  it.each(['return_review', 'suspend', 'approve', 'reject'])(
    'rejects unsupported transition action %s',
    (action) => {
      expect(() => requirementTransitionPayload(action)).toThrow(RangeError)
    },
  )

  it('only exposes actions backed by requirement routes', () => {
    expect(getRequirementStatusActions('pending_review').map(({ key }) => key)).toEqual(['approve', 'reject'])
    expect(getRequirementStatusActions('assigned').map(({ key }) => key)).toEqual(['start_dev'])
    expect(getRequirementStatusActions('developing').map(({ key }) => key)).toEqual(['complete_dev'])
    expect(getRequirementStatusActions('testing').map(({ key }) => key)).toEqual(['pass_test', 'fail_test'])
    expect(getRequirementStatusActions('pending_online').map(({ key }) => key)).toEqual(['confirm_online'])
    expect(getRequirementStatusActions('online').map(({ key }) => key)).toEqual(['confirm_accept'])
    expect(getRequirementStatusActions('accepted')).toEqual([])
  })
})

describe('task workflow payloads', () => {
  it.each([
    ['start', 2],
    ['complete', 3],
    ['resume', 2],
  ])('maps %s to backend status %i', (action, status) => {
    expect(taskTransitionPayload(action)).toEqual({ status })
  })

  it('rejects unsupported task actions', () => {
    expect(() => taskTransitionPayload('suspend')).toThrow(RangeError)
  })
})

describe('requirement review execution', () => {
  function createApiSpies() {
    return {
      reviewRequirement: vi.fn().mockResolvedValue({}),
      transitionRequirement: vi.fn().mockResolvedValue({}),
    }
  }

  it.each(['', '   ', '\t\n'])(
    'blocks a reject request when the comment is blank',
    async (comment) => {
      const api = createApiSpies()

      await expect(
        executeRequirementAction(42, 'reject', comment, api),
      ).rejects.toThrow('驳回时必须填写审核意见')
      expect(api.reviewRequirement).not.toHaveBeenCalled()
      expect(api.transitionRequirement).not.toHaveBeenCalled()
    },
  )

  it('trims a valid rejection comment and sends one exact review request', async () => {
    const api = createApiSpies()

    await executeRequirementAction(42, 'reject', '  需求范围不清晰  ', api)

    expect(api.reviewRequirement).toHaveBeenCalledTimes(1)
    expect(api.reviewRequirement).toHaveBeenCalledWith(42, {
      action: 'reject',
      comment: '需求范围不清晰',
    })
    expect(api.transitionRequirement).not.toHaveBeenCalled()
  })

  it('keeps an approval comment optional', async () => {
    const api = createApiSpies()

    await executeRequirementAction(42, 'approve', '   ', api)

    expect(api.reviewRequirement).toHaveBeenCalledTimes(1)
    expect(api.reviewRequirement).toHaveBeenCalledWith(42, { action: 'approve' })
    expect(api.transitionRequirement).not.toHaveBeenCalled()
  })

  it('keeps status transitions on the numeric transition API', async () => {
    const api = createApiSpies()

    await executeRequirementAction(42, 'start_dev', 'ignored', api)

    expect(api.transitionRequirement).toHaveBeenCalledTimes(1)
    expect(api.transitionRequirement).toHaveBeenCalledWith(42, { status: 3 })
    expect(api.reviewRequirement).not.toHaveBeenCalled()
  })
})

describe('requirement comment field', () => {
  it('marks the rejection comment as required', () => {
    expect(getRequirementCommentField('reject')).toEqual({
      label: '驳回备注',
      placeholder: '请输入驳回原因（必填）',
      required: true,
    })
  })

  it('keeps comments optional for other actions', () => {
    expect(getRequirementCommentField('approve')).toEqual({
      label: '操作备注',
      placeholder: '请输入备注（可选）',
      required: false,
    })
  })
})
