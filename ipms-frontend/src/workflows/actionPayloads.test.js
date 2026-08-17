import { describe, expect, it } from 'vitest'
import {
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
