import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import { Toggle } from '../toggle';

describe('Toggle', () => {
  it('applies variant and size classes', () => {
    render(
      <Toggle variant="outline" size="sm" data-testid="toggle">
        A
      </Toggle>
    );
    const toggle = screen.getByTestId('toggle');
    expect(toggle).toHaveClass('border');
    expect(toggle).toHaveClass('h-8');
    expect(toggle).toHaveAttribute('data-slot', 'toggle');
  });

  it('changes state when clicked', async () => {
    render(
      <Toggle data-testid="toggle" aria-label="toggle">
        A
      </Toggle>
    );
    const toggle = screen.getByTestId('toggle');
    expect(toggle).toHaveAttribute('data-state', 'off');
    await userEvent.click(toggle);
    expect(toggle).toHaveAttribute('data-state', 'on');
  });
});
