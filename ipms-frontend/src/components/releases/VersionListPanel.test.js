import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VersionListPanel from './VersionListPanel.vue'

const ElButton = defineComponent({
  name: 'ElButton',
  emits: ['click'],
  setup(_props, { attrs, emit, slots }) {
    return () => h('button', {
      ...attrs,
      type: 'button',
      onClick: () => emit('click'),
    }, slots.default?.())
  },
})

const versions = [
  {
    id: 31,
    code: 'R2.1',
    name: 'September release',
    status_code: 'IN_TESTING',
    status_label: '测试中',
    owner: { id: 7, display_name: '周项目经理' },
    planned_release_date: '2026-09-30',
    counts: { requirements: 4, tasks: 9, defects: 2 },
    gate_result: { passed: false, blocking: [{ code: 'tasks_completed' }] },
  },
]

describe('VersionListPanel', () => {
  it('renders server values and exposes one clear row action', async () => {
    const wrapper = mount(VersionListPanel, {
      props: { versions },
      global: {
        stubs: {
          ElButton,
          VersionStatusTag: {
            props: ['statusCode', 'statusLabel'],
            template: '<span>{{ statusLabel }}</span>',
          },
        },
      },
    })

    expect(wrapper.text()).toContain('R2.1')
    expect(wrapper.text()).toContain('September release')
    expect(wrapper.text()).toContain('测试中')
    expect(wrapper.text()).toContain('周项目经理')
    expect(wrapper.text()).toContain('2026-09-30')
    expect(wrapper.text()).toContain('4')
    expect(wrapper.text()).toContain('1 项阻断')
    expect(wrapper.findAll('button')).toHaveLength(1)

    await wrapper.get('[data-testid="open-version-31"]').trigger('click')
    expect(wrapper.emitted('open')).toEqual([[versions[0]]])
  })

  it('renders a stable empty state without mock releases', () => {
    const wrapper = mount(VersionListPanel, {
      props: { versions: [] },
      global: { stubs: { ElButton } },
    })

    expect(wrapper.text()).toContain('暂无发布版本')
    expect(wrapper.text()).not.toContain('SAP B1')
  })
})
