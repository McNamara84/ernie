import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Label } from '../label';

describe('Label', () => {
  it('renders with default styles and custom class', () => {
    render(
      <Label data-testid="label" className="custom">
        Username
      </Label>
    );
    const label = screen.getByTestId('label');
    expect(label).toHaveAttribute('data-slot', 'label');
    expect(label).toHaveClass('text-sm');
    expect(label).toHaveClass('font-medium');
    expect(label).toHaveClass('custom');
    expect(label).toHaveTextContent('Username');
  });
});
