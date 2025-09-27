import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuCheckboxItem,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '../dropdown-menu';

describe('DropdownMenu', () => {
  it('opens menu and renders item', async () => {
    render(
      <DropdownMenu>
        <DropdownMenuTrigger>Open</DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem>Item 1</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
    const user = userEvent.setup();
    await user.click(screen.getByText('Open'));
    expect(await screen.findByText('Item 1')).toBeInTheDocument();
  });

  it('applies inset and variant to item', async () => {
    render(
      <DropdownMenu>
        <DropdownMenuTrigger>Trigger</DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem inset variant="destructive">
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
    const user = userEvent.setup();
    await user.click(screen.getByText('Trigger'));
    const item = await screen.findByText('Delete');
    expect(item).toHaveAttribute('data-inset', 'true');
    expect(item).toHaveAttribute('data-variant', 'destructive');
  });

  it('renders the full dropdown structure with checkbox, radio and sub menus', () => {
    render(
      <DropdownMenu defaultOpen>
        <DropdownMenuTrigger>Advanced</DropdownMenuTrigger>
        <DropdownMenuContent className="custom-content" forceMount>
          <DropdownMenuLabel>Appearance</DropdownMenuLabel>
          <DropdownMenuGroup>
            <DropdownMenuCheckboxItem checked>
              Show hidden files
            </DropdownMenuCheckboxItem>
            <DropdownMenuRadioGroup value="system" onValueChange={() => {}}>
              <DropdownMenuRadioItem value="light">
                Light
              </DropdownMenuRadioItem>
              <DropdownMenuRadioItem value="system">
                System
              </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuItem>
            Save
            <DropdownMenuShortcut>⌘S</DropdownMenuShortcut>
          </DropdownMenuItem>
          <DropdownMenuSub defaultOpen>
            <DropdownMenuSubTrigger inset>More options</DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="custom-sub" forceMount>
              <DropdownMenuItem>Export</DropdownMenuItem>
            </DropdownMenuSubContent>
          </DropdownMenuSub>
        </DropdownMenuContent>
      </DropdownMenu>
    );

    const content = document.querySelector(
      '[data-slot="dropdown-menu-content"]'
    );
    expect(content).toHaveClass('custom-content');

    const label = screen.getByText('Appearance');
    expect(label).toHaveAttribute('data-slot', 'dropdown-menu-label');

    const checkbox = screen.getByRole('menuitemcheckbox', {
      name: 'Show hidden files',
    });
    expect(checkbox).toHaveAttribute('data-slot', 'dropdown-menu-checkbox-item');
    expect(checkbox).toHaveAttribute('aria-checked', 'true');

    const radioItems = screen.getAllByRole('menuitemradio');
    expect(radioItems).toHaveLength(2);
    expect(radioItems[1]).toHaveAttribute('aria-checked', 'true');

    const separator = document.querySelector(
      '[data-slot="dropdown-menu-separator"]'
    );
    expect(separator).toBeInTheDocument();

    const shortcut = screen.getByText('⌘S');
    expect(shortcut).toHaveAttribute('data-slot', 'dropdown-menu-shortcut');

    const subTrigger = screen.getByText('More options');
    expect(subTrigger).toHaveAttribute('data-inset', 'true');

    const subContent = document.querySelector(
      '[data-slot="dropdown-menu-sub-content"]'
    );
    expect(subContent).toHaveClass('custom-sub');
    expect(screen.getByText('Export')).toBeInTheDocument();
  });
});
