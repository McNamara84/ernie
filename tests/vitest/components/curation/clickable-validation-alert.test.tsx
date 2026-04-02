import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ClickableValidationAlert } from '@/components/curation/clickable-validation-alert';
import type { MappedError } from '@/components/curation/utils/error-field-mapper';

function createErrors(overrides: Partial<MappedError>[] = []): MappedError[] {
    const defaults: MappedError[] = [
        {
            backendKey: 'year',
            message: 'Publication Year is required.',
            sectionId: 'resource-info',
            sectionName: 'Resource Information',
            fieldSelector: '#year',
        },
        {
            backendKey: 'titles',
            message: 'At least one title is required.',
            sectionId: 'resource-info',
            sectionName: 'Resource Information',
            fieldSelector: '#main-title-input',
        },
        {
            backendKey: 'licenses',
            message: 'At least one license is required.',
            sectionId: 'licenses-rights',
            sectionName: 'Licenses & Rights',
            fieldSelector: '[data-testid="license-select-0"]',
        },
    ];

    if (overrides.length > 0) {
        return overrides.map((o, i) => ({ ...defaults[i % defaults.length], ...o }));
    }
    return defaults;
}

describe('ClickableValidationAlert', () => {
    describe('Rendering', () => {
        it('renders nothing when errors array is empty', () => {
            const { container } = render(<ClickableValidationAlert errors={[]} onErrorClick={vi.fn()} />);

            expect(container.firstChild).toBeNull();
        });

        it('renders alert with role="alert"', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            expect(screen.getByRole('alert')).toBeInTheDocument();
        });

        it('renders header message about unable to save', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            expect(screen.getByText(/Unable to save resource/)).toBeInTheDocument();
        });

        it('renders with data-testid when provided', () => {
            render(
                <ClickableValidationAlert
                    errors={createErrors()}
                    onErrorClick={vi.fn()}
                    data-testid="test-alert"
                />,
            );

            expect(screen.getByTestId('test-alert')).toBeInTheDocument();
        });

        it('renders with data-slot attribute', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            const alert = screen.getByRole('alert');
            expect(alert).toHaveAttribute('data-slot', 'clickable-validation-alert');
        });
    });

    describe('Error grouping', () => {
        it('groups errors by section', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            expect(screen.getByTestId('error-group-resource-info')).toBeInTheDocument();
            expect(screen.getByTestId('error-group-licenses-rights')).toBeInTheDocument();
        });

        it('displays section name as heading', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            expect(screen.getByText('Resource Information')).toBeInTheDocument();
            expect(screen.getByText('Licenses & Rights')).toBeInTheDocument();
        });

        it('shows issue count per section', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            // Resource Information has 2 errors
            expect(screen.getByText('(2 issues)')).toBeInTheDocument();
            // Licenses & Rights has 1 error
            expect(screen.getByText('(1 issue)')).toBeInTheDocument();
        });

        it('renders individual error messages', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            expect(screen.getByText('Publication Year is required.')).toBeInTheDocument();
            expect(screen.getByText('At least one title is required.')).toBeInTheDocument();
            expect(screen.getByText('At least one license is required.')).toBeInTheDocument();
        });
    });

    describe('Click handling', () => {
        it('calls onErrorClick with the correct error when an error is clicked', () => {
            const onErrorClick = vi.fn();
            const errors = createErrors();

            render(<ClickableValidationAlert errors={errors} onErrorClick={onErrorClick} />);

            const yearButton = screen.getByText('Publication Year is required.');
            fireEvent.click(yearButton);

            expect(onErrorClick).toHaveBeenCalledOnce();
            expect(onErrorClick).toHaveBeenCalledWith(errors[0]);
        });

        it('calls onErrorClick with correct error for different clicked items', () => {
            const onErrorClick = vi.fn();
            const errors = createErrors();

            render(<ClickableValidationAlert errors={errors} onErrorClick={onErrorClick} />);

            const licenseButton = screen.getByText('At least one license is required.');
            fireEvent.click(licenseButton);

            expect(onErrorClick).toHaveBeenCalledWith(errors[2]);
        });

        it('renders error items as buttons', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            const buttons = screen.getAllByRole('button');
            expect(buttons).toHaveLength(3);
        });
    });

    describe('Accessibility', () => {
        it('has aria-live="assertive" on the alert', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            const alert = screen.getByRole('alert');
            expect(alert).toHaveAttribute('aria-live', 'assertive');
        });

        it('does not have tabIndex by default', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} />);

            const alert = screen.getByRole('alert');
            expect(alert).not.toHaveAttribute('tabindex');
        });

        it('has tabIndex=-1 when focusable is true', () => {
            render(<ClickableValidationAlert errors={createErrors()} onErrorClick={vi.fn()} focusable />);

            const alert = screen.getByRole('alert');
            expect(alert).toHaveAttribute('tabindex', '-1');
        });
    });

    describe('Custom className', () => {
        it('applies additional className', () => {
            render(
                <ClickableValidationAlert
                    errors={createErrors()}
                    onErrorClick={vi.fn()}
                    className="my-custom-class"
                />,
            );

            const alert = screen.getByRole('alert');
            expect(alert).toHaveClass('my-custom-class');
        });
    });
});
