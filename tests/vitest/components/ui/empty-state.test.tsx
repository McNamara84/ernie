import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Calendar, MapPin } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';

import { EmptyState } from '@/components/ui/empty-state';

describe('EmptyState', () => {
    describe('Rendering', () => {
        it('should render title', () => {
            render(<EmptyState title="No items yet" />);

            expect(screen.getByText('No items yet')).toBeInTheDocument();
        });

        it('should render description when provided', () => {
            render(<EmptyState title="No items yet" description="Add your first item to get started." />);

            expect(screen.getByText('Add your first item to get started.')).toBeInTheDocument();
        });

        it('should not render description when not provided', () => {
            render(<EmptyState title="No items yet" />);

            expect(screen.queryByText('Add your first item')).not.toBeInTheDocument();
        });

        it('should render icon when provided', () => {
            render(<EmptyState title="No dates added" icon={<Calendar data-testid="calendar-icon" />} />);

            expect(screen.getByTestId('calendar-icon')).toBeInTheDocument();
        });

        it('should render with data-testid', () => {
            render(<EmptyState title="Empty" data-testid="empty-state-test" />);

            expect(screen.getByTestId('empty-state-test')).toBeInTheDocument();
        });

        it('should have correct aria-label', () => {
            render(<EmptyState title="No coverage entries" />);

            expect(screen.getByRole('status')).toHaveAttribute('aria-label', 'No coverage entries');
        });
    });

    describe('Variants', () => {
        it('should render with dashed border in default variant', () => {
            const { container } = render(<EmptyState title="Empty" />);

            expect(container.firstChild).toHaveClass('border-dashed');
        });

        it('should not have dashed border in compact variant', () => {
            const { container } = render(<EmptyState title="Empty" variant="compact" />);

            expect(container.firstChild).not.toHaveClass('border-dashed');
        });

        it('should have less padding in compact variant', () => {
            const { container } = render(<EmptyState title="Empty" variant="compact" />);

            expect(container.firstChild).toHaveClass('py-6');
        });

        it('should have more padding in default variant', () => {
            const { container } = render(<EmptyState title="Empty" variant="default" />);

            expect(container.firstChild).toHaveClass('py-8');
        });
    });

    describe('Actions', () => {
        it('should render primary action button', () => {
            const handleClick = vi.fn();
            render(
                <EmptyState
                    title="No items"
                    action={{
                        label: 'Add Item',
                        onClick: handleClick,
                    }}
                />,
            );

            expect(screen.getByRole('button', { name: /add item/i })).toBeInTheDocument();
        });

        it('should call onClick when primary action is clicked', async () => {
            const user = userEvent.setup();
            const handleClick = vi.fn();

            render(
                <EmptyState
                    title="No items"
                    action={{
                        label: 'Add Item',
                        onClick: handleClick,
                    }}
                />,
            );

            await user.click(screen.getByRole('button', { name: /add item/i }));

            expect(handleClick).toHaveBeenCalledTimes(1);
        });

        it('should render custom icon in action button', () => {
            render(
                <EmptyState
                    title="No coverage"
                    action={{
                        label: 'Add Coverage',
                        onClick: vi.fn(),
                        icon: <MapPin data-testid="map-icon" className="mr-2 h-4 w-4" />,
                    }}
                />,
            );

            expect(screen.getByTestId('map-icon')).toBeInTheDocument();
        });

        it('should render secondary action button', () => {
            render(
                <EmptyState
                    title="No items"
                    action={{
                        label: 'Add Item',
                        onClick: vi.fn(),
                    }}
                    secondaryAction={{
                        label: 'Import CSV',
                        onClick: vi.fn(),
                    }}
                />,
            );

            expect(screen.getByRole('button', { name: /import csv/i })).toBeInTheDocument();
        });

        it('should call onClick when secondary action is clicked', async () => {
            const user = userEvent.setup();
            const handleSecondaryClick = vi.fn();

            render(
                <EmptyState
                    title="No items"
                    action={{
                        label: 'Add Item',
                        onClick: vi.fn(),
                    }}
                    secondaryAction={{
                        label: 'Import CSV',
                        onClick: handleSecondaryClick,
                    }}
                />,
            );

            await user.click(screen.getByRole('button', { name: /import csv/i }));

            expect(handleSecondaryClick).toHaveBeenCalledTimes(1);
        });

        it('should not render actions container when no actions provided', () => {
            const { container } = render(<EmptyState title="No items" />);

            expect(container.querySelector('button')).not.toBeInTheDocument();
        });
    });

    describe('Children', () => {
        it('should render children when provided', () => {
            render(
                <EmptyState title="No authors">
                    <button type="button">Custom Action</button>
                </EmptyState>,
            );

            expect(screen.getByRole('button', { name: /custom action/i })).toBeInTheDocument();
        });

        it('should prefer children over built-in actions when both provided', () => {
            render(
                <EmptyState
                    title="No authors"
                    action={{
                        label: 'Built-in Action',
                        onClick: vi.fn(),
                    }}
                >
                    <button type="button">Custom Action</button>
                </EmptyState>,
            );

            expect(screen.getByRole('button', { name: /custom action/i })).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /built-in action/i })).not.toBeInTheDocument();
        });

        it('should render multiple children', () => {
            render(
                <EmptyState title="No authors">
                    <button type="button">First Action</button>
                    <button type="button">Second Action</button>
                </EmptyState>,
            );

            expect(screen.getByRole('button', { name: /first action/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /second action/i })).toBeInTheDocument();
        });

        it('should wrap children in a flex container', () => {
            const { container } = render(
                <EmptyState title="No authors" data-testid="empty-state">
                    <button type="button">Action</button>
                </EmptyState>,
            );

            const emptyState = container.firstChild as HTMLElement;
            const childrenWrapper = emptyState.lastElementChild as HTMLElement;

            expect(childrenWrapper).toHaveClass('flex', 'items-center', 'gap-2');
        });
    });

    describe('Styling', () => {
        it('should accept custom className', () => {
            const { container } = render(<EmptyState title="Empty" className="custom-class" />);

            expect(container.firstChild).toHaveClass('custom-class');
        });

        it('should have centered content', () => {
            const { container } = render(<EmptyState title="Empty" />);

            expect(container.firstChild).toHaveClass('items-center', 'justify-center', 'text-center');
        });
    });
});
