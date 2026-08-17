import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'

const requirementView = readFileSync('src/views/requirements/RequirementDetail.vue', 'utf8')
const taskView = readFileSync('src/views/tasks/TaskList.vue', 'utf8')

describe('workflow action wiring', () => {
  it('keeps requirement review actions separate from numeric transitions', () => {
    expect(requirementView).toContain("action.key === 'approve' || action.key === 'reject'")
    expect(requirementView).toContain('requirementTransitionPayload(action.key)')
    expect(requirementView).toContain("} from '@/workflows/actionPayloads'")
  })

  it('does not offer unsupported requirement transitions', () => {
    expect(requirementView).not.toContain("key: 'return_review'")
    expect(requirementView).not.toContain("key: 'suspend'")
  })

  it.each([
    ['start', "transitionTask(row.id, taskTransitionPayload('start'))"],
    ['complete', "transitionTask(row.id, taskTransitionPayload('complete'))"],
    ['resume', "transitionTask(row.id, taskTransitionPayload('resume'))"],
  ])('uses the numeric task payload for %s', (_action, expectedCall) => {
    expect(taskView).toContain(expectedCall)
  })

  it('does not send legacy task action payloads', () => {
    expect(taskView).not.toContain("{ action: 'start' }")
    expect(taskView).not.toContain("{ action: 'complete' }")
    expect(taskView).not.toContain("{ action: 'resume' }")

  })
})
