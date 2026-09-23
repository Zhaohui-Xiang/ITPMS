import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ReleaseGatePanel from './ReleaseGatePanel.vue'

function makeResult(checks) {
  return { passed: false, target_status_code: 'READY_TO_RELEASE', checks, blocking: [] }
}

const failedNotesCheck = {
  code: 'release_notes_present',
  label: 'Release notes are present',
  passed: false,
  blocking: true,
  details: { applicable: true, present: false },
}
const failedTasksCheck = {
  code: 'tasks_completed',
  label: 'All scope tasks are completed',
  passed: false,
  blocking: true,
  details: { applicable: true, total_count: 3, incomplete_count: 2, incomplete_task_ids: [101, 102] },
}
const passedDefectsCheck = {
  code: 'severe_defects_closed',
  label: 'Fatal and serious scope defects are closed',
  passed: true,
  blocking: true,
  details: { applicable: true, relevant_count: 1, open_count: 0, open_defect_ids: [] },
}

describe('ReleaseGatePanel resolution guidance', () => {
  it('localizes check names and offers a resolve action for version-field gates', async () => {
    const wrapper = mount(ReleaseGatePanel, {
      props: { result: makeResult([failedNotesCheck, passedDefectsCheck]), projectId: 9, versionId: 42 },
    })

    expect(wrapper.text()).toContain('发布说明已填写')
    expect(wrapper.text()).toContain('严重缺陷全部关闭')
    expect(wrapper.text()).not.toContain('Release notes are present')

    const resolve = wrapper.get('[data-testid="gate-resolve-release_notes_present"]')
    expect(resolve.text()).toContain('前往填写发布说明')
    await resolve.trigger('click')
    expect(wrapper.emitted('resolve')).toEqual([[{
      code: 'release_notes_present',
      field: 'release_notes',
    }]])

    // 已通过的门禁不提供处理入口
    expect(wrapper.find('[data-testid="gate-resolve-severe_defects_closed"]').exists()).toBe(false)
  })

  it('maps version metadata gaps to the exact edit field', async () => {
    const check = {
      code: 'version_metadata',
      label: 'Version owner and planned release date',
      passed: false,
      blocking: true,
      details: { applicable: true, missing: ['owner_id'] },
    }
    const wrapper = mount(ReleaseGatePanel, {
      props: { result: makeResult([check]), projectId: 9, versionId: 42 },
    })

    expect(wrapper.text()).toContain('版本负责人与计划发布日期已设置')
    await wrapper.get('[data-testid="gate-resolve-version_metadata"]').trigger('click')
    expect(wrapper.emitted('resolve')).toEqual([[{ code: 'version_metadata', field: 'owner' }]])
  })

  it('lists blocking records with direct links for record-type gates', () => {
    const wrapper = mount(ReleaseGatePanel, {
      props: { result: makeResult([failedTasksCheck]), projectId: 9, versionId: 42 },
    })

    expect(wrapper.text()).toContain('范围任务全部完成')
    expect(wrapper.text()).toContain('影响记录 2 项')
    expect(wrapper.get('[data-testid="gate-record-tasks_completed-101"]').attributes('href'))
      .toBe('/tasks/101')
    expect(wrapper.get('[data-testid="gate-record-tasks_completed-102"]').attributes('href'))
      .toBe('/tasks/102')
    expect(wrapper.get('[data-testid="gate-link-tasks_completed"]').attributes('href'))
      .toContain('/tasks?')
  })
})
