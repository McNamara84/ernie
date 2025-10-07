import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect,it } from 'vitest';

import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';

describe('Collapsible', () => {
  it('toggles content when trigger is clicked', async () => {
    render(
      <Collapsible>
        <CollapsibleTrigger>Toggle</CollapsibleTrigger>
        <CollapsibleContent data-testid="content">
          Hidden
        </CollapsibleContent>
      </Collapsible>
    );

    const trigger = screen.getByRole('button', { name: 'Toggle' });
    const content = screen.getByTestId('content');
    expect(content).toHaveAttribute('data-state', 'closed');
    await userEvent.click(trigger);
    expect(content).toHaveAttribute('data-state', 'open');
  });
});
