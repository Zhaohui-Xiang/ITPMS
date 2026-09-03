import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BrandMark from './BrandMark.vue'

describe('BrandMark', () => {
  it('renders the Voltage wordmark when expanded', () => {
    const wrapper = mount(BrandMark)
    const image = wrapper.get('img')

    expect(image.attributes('src')).toContain('voltage-wordmark.png')
    expect(image.attributes('alt')).toBe('Voltage')
  })

  it('renders the Voltage V mark when compact', () => {
    const wrapper = mount(BrandMark, {
      props: { compact: true },
    })

    expect(wrapper.get('img').attributes('src')).toContain('voltage-v.png')
  })
})
