import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import {
  Collapsible,
  CollapsibleTrigger,
  CollapsibleContent,
} from '../collapsible';

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

    const trigger = document.querySelector('[data-slot="collapsible-trigger"]')!;
    const content = document.querySelector('[data-testid="content"]')!;
    expect(content).toHaveAttribute('data-state', 'closed');
    await userEvent.click(trigger);
    expect(content).toHaveAttribute('data-state', 'open');
  });
});
