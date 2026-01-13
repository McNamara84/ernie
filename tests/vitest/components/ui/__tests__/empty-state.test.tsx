/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { Calendar } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';

import { EmptyState } from '@/components/ui/empty-state';

describe('EmptyState', () => {
    describe('rendering', () => {
        it('renders with title', () => {
            render(<EmptyState title="No items found" />);
            
            expect(screen.getByText('No items found')).toBeInTheDocument();
        });

        it('renders description when provided', () => {
            render(
                <EmptyState
                    title="No dates added"
                    description="Add important dates like collection period."
                />
            );
            
            expect(screen.getByText('Add important dates like collection period.')).toBeInTheDocument();
        });

        it('renders icon when provided', () => {
            render(
                <EmptyState
                    title="No data"
                    icon={<Calendar data-testid="calendar-icon" />}
                />
            );
            
            expect(screen.getByTestId('calendar-icon')).toBeInTheDocument();
        });

        it('sets aria-label from title', () => {
            render(<EmptyState title="Empty state title" />);
            
            expect(screen.getByRole('status')).toHaveAttribute('aria-label', 'Empty state title');
        });

        it('applies data-testid when provided', () => {
            render(<EmptyState title="Test" data-testid="empty-state" />);
            
            expect(screen.getByTestId('empty-state')).toBeInTheDocument();
        });
    });

    describe('variants', () => {
        it('applies default variant with dashed border', () => {
            const { container } = render(<EmptyState title="Default" />);
            
            expect(container.firstChild).toHaveClass('border-2', 'border-dashed');
        });

        it('applies compact variant without dashed border', () => {
            const { container } = render(<EmptyState title="Compact" variant="compact" />);
            
            expect(container.firstChild).toHaveClass('py-6');
            expect(container.firstChild).not.toHaveClass('border-2', 'border-dashed');
        });
    });

    describe('actions', () => {
        it('renders primary action button', () => {
            const handleClick = vi.fn();
            
            render(
                <EmptyState
                    title="No dates"
                    action={{ label: 'Add Date', onClick: handleClick }}
                />
            );
            
            expect(screen.getByRole('button', { name: /Add Date/i })).toBeInTheDocument();
        });

        it('calls action onClick when button is clicked', () => {
            const handleClick = vi.fn();
            
            render(
                <EmptyState
                    title="No dates"
                    action={{ label: 'Add Date', onClick: handleClick }}
                />
            );
            
            fireEvent.click(screen.getByRole('button', { name: /Add Date/i }));
            
            expect(handleClick).toHaveBeenCalledTimes(1);
        });

        it('renders secondary action button', () => {
            const primaryClick = vi.fn();
            const secondaryClick = vi.fn();
            
            render(
                <EmptyState
                    title="No items"
                    action={{ label: 'Add Item', onClick: primaryClick }}
                    secondaryAction={{ label: 'Import', onClick: secondaryClick }}
                />
            );
            
            expect(screen.getByRole('button', { name: /Import/i })).toBeInTheDocument();
        });

        it('calls secondaryAction onClick when secondary button is clicked', () => {
            const primaryClick = vi.fn();
            const secondaryClick = vi.fn();
            
            render(
                <EmptyState
                    title="No items"
                    action={{ label: 'Add Item', onClick: primaryClick }}
                    secondaryAction={{ label: 'Import', onClick: secondaryClick }}
                />
            );
            
            fireEvent.click(screen.getByRole('button', { name: /Import/i }));
            
            expect(secondaryClick).toHaveBeenCalledTimes(1);
        });

        it('renders action with custom icon', () => {
            const handleClick = vi.fn();
            
            render(
                <EmptyState
                    title="No dates"
                    action={{
                        label: 'Add Date',
                        onClick: handleClick,
                        icon: <Calendar data-testid="action-icon" />,
                    }}
                />
            );
            
            expect(screen.getByTestId('action-icon')).toBeInTheDocument();
        });
    });

    describe('children', () => {
        it('renders children when provided', () => {
            render(
                <EmptyState title="No items">
                    <button>Custom Action</button>
                </EmptyState>
            );
            
            expect(screen.getByRole('button', { name: 'Custom Action' })).toBeInTheDocument();
        });

        it('hides built-in actions when children are provided', () => {
            const handleClick = vi.fn();
            
            render(
                <EmptyState
                    title="No items"
                    action={{ label: 'Built-in Action', onClick: handleClick }}
                >
                    <button>Custom Action</button>
                </EmptyState>
            );
            
            expect(screen.queryByRole('button', { name: 'Built-in Action' })).not.toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Custom Action' })).toBeInTheDocument();
        });
    });

    describe('className', () => {
        it('applies custom className', () => {
            const { container } = render(
                <EmptyState title="Test" className="my-custom-class" />
            );
            
            expect(container.firstChild).toHaveClass('my-custom-class');
        });
    });
});
