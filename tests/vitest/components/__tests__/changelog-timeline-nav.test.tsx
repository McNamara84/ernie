import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ChangelogTimelineNav } from '@/components/changelog-timeline-nav';

const releases = [
    { version: '2.0.0', date: '2025-01-15' },
    { version: '1.1.0', date: '2025-01-01' },
    { version: '1.0.1', date: '2024-12-20' },
    { version: '1.0.0', date: '2024-12-01' },
];

describe('ChangelogTimelineNav', () => {
    const onNavigate = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(window, 'innerWidth', { value: 1440, writable: true });
    });

    it('renders nothing without releases', () => {
        const { container } = render(<ChangelogTimelineNav releases={[]} activeIndex={null} onNavigate={onNavigate} />);
        expect(container.firstChild).toBeNull();
    });

    it('shows a scrollable desktop outline with version labels, active state and legend', () => {
        render(<ChangelogTimelineNav releases={releases} activeIndex={1} onNavigate={onNavigate} />);

        const nav = screen.getByRole('navigation', { name: 'Version timeline navigation' });
        expect(nav).toHaveClass('sticky', 'overflow-y-auto', 'max-h-[calc(100vh-6rem)]');
        expect(within(nav).getByText('Versions')).toBeInTheDocument();
        expect(within(nav).getByLabelText('Version color legend')).toHaveTextContent('MajorMinorPatch');

        const buttons = within(nav).getAllByRole('button');
        expect(buttons).toHaveLength(4);
        expect(buttons.map((button) => button.textContent)).toEqual(['v2.0.0', 'v1.1.0', 'v1.0.1', 'v1.0.0']);
        expect(buttons[0]).toHaveClass('h-10');
        expect(buttons[0]).toHaveAttribute('data-variant', 'outline');
        expect(buttons[1]).toHaveAttribute('aria-current', 'true');
        expect(buttons[0]).not.toHaveAttribute('aria-current');
    });

    it('uses the older release to classify major, minor, patch and oldest versions', () => {
        render(<ChangelogTimelineNav releases={releases} activeIndex={0} onNavigate={onNavigate} />);

        const dots = screen.getAllByTestId('timeline-dot');
        expect(dots[0]).toHaveClass('bg-green-500');
        expect(dots[1]).toHaveClass('bg-blue-500');
        expect(dots[2]).toHaveClass('bg-red-500');
        expect(dots[3]).toHaveClass('bg-green-500');
    });

    it('opens the selected desktop version', async () => {
        const user = userEvent.setup();
        render(<ChangelogTimelineNav releases={releases} activeIndex={0} onNavigate={onNavigate} />);

        await user.click(screen.getByRole('button', { name: 'Navigate to version 1.1.0' }));
        expect(onNavigate).toHaveBeenCalledWith(1);
    });

    it('opens a labeled mobile menu and closes it after selecting a version', async () => {
        Object.defineProperty(window, 'innerWidth', { value: 500, writable: true });
        const user = userEvent.setup();
        render(<ChangelogTimelineNav releases={releases} activeIndex={0} onNavigate={onNavigate} />);

        const toggle = await screen.findByRole('button', { name: 'Toggle timeline navigation' });
        expect(toggle).toHaveTextContent('Versions');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('button', { name: 'Navigate to version 1.1.0' })).not.toBeInTheDocument();

        await user.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByLabelText('Version color legend')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Navigate to version 1.1.0' }));
        expect(onNavigate).toHaveBeenCalledWith(1);
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(toggle).toHaveFocus();
    });

    it('closes the mobile version menu with Escape', async () => {
        Object.defineProperty(window, 'innerWidth', { value: 500, writable: true });
        const user = userEvent.setup();
        render(<ChangelogTimelineNav releases={releases} activeIndex={0} onNavigate={onNavigate} />);

        const toggle = await screen.findByRole('button', { name: 'Toggle timeline navigation' });
        await user.click(toggle);
        fireEvent.keyDown(screen.getByRole('navigation'), { key: 'Escape' });

        await waitFor(() => expect(toggle).toHaveAttribute('aria-expanded', 'false'));
        expect(toggle).toHaveFocus();
    });
});
