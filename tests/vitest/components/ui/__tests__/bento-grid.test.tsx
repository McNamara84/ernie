import '@testing-library/jest-dom/vitest'

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { BentoGrid, BentoGridItem } from '@/components/ui/bento-grid'

describe('BentoGrid', () => {
  it('renders grid with items and slots', () => {
    const { container } = render(
      <BentoGrid>
        <BentoGridItem>One</BentoGridItem>
        <BentoGridItem>Two</BentoGridItem>
      </BentoGrid>,
    )

    const grid = container.querySelector('[data-slot="bento-grid"]')
    expect(grid).toBeInTheDocument()
    expect(grid?.children).toHaveLength(2)
    const first = screen.getByText('One')
    expect(first).toHaveAttribute('data-slot', 'bento-grid-item')
    expect(first).toHaveClass('self-start')
    expect(grid).toHaveClass('grid', 'gap-4', 'md:grid-cols-2')
    expect(grid).not.toHaveClass('lg:grid-cols-3')
  })
})
