import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import CoordinateInputs from '@/components/curation/fields/spatial-temporal-coverage/CoordinateInputs';

describe('CoordinateInputs', () => {
    const mockOnChange = vi.fn();

    const defaultProps = {
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        onChange: mockOnChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
    });

    test('renders all coordinate input fields', () => {
        const { container } = render(<CoordinateInputs {...defaultProps} />);

        // Use container.querySelector since labels use aria-labelledby
        expect(container.querySelector('#lat-min')).toBeInTheDocument();
        expect(container.querySelector('#lon-min')).toBeInTheDocument();
        expect(container.querySelector('#lat-max')).toBeInTheDocument();
        expect(container.querySelector('#lon-max')).toBeInTheDocument();
    });

    test('displays values correctly', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                latMin="48.137154"
                lonMin="11.576124"
                latMax="48.150000"
                lonMax="11.600000"
            />,
        );

        expect(screen.getByDisplayValue('48.137154')).toBeInTheDocument();
        expect(screen.getByDisplayValue('11.576124')).toBeInTheDocument();
        expect(screen.getByDisplayValue('48.150000')).toBeInTheDocument();
        expect(screen.getByDisplayValue('11.600000')).toBeInTheDocument();
    });

    test('calls onChange when latitude min is changed', async () => {
        const user = userEvent.setup();
        const { container } = render(<CoordinateInputs {...defaultProps} />);

        const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;

        await user.type(latMinInput, '48.5');

        // Verify onChange was called multiple times (once per character typed)
        expect(mockOnChange).toHaveBeenCalled();
        
        // All calls should be for the latMin field
        const allCalls = mockOnChange.mock.calls;
        const latMinCalls = allCalls.filter(call => call[0] === 'latMin');
        expect(latMinCalls.length).toBeGreaterThan(0);
    });

    test('formats coordinates to max 6 decimal places', async () => {
        const user = userEvent.setup();
        const { container } = render(<CoordinateInputs {...defaultProps} />);

        const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;

        // Clear the field first
        await user.clear(latMinInput);
        
        // Type a value with more than 6 decimal places
        await user.type(latMinInput, '7.1234567');

        // The component should truncate values to 6 decimal places
        // Check that all onChange calls have values with max 6 decimal places
        const allCalls = mockOnChange.mock.calls;
        const latMinCalls = allCalls.filter(call => call[0] === 'latMin');
        
        for (const call of latMinCalls) {
            const value = call[1];
            if (value.includes('.')) {
                const decimalPart = value.split('.')[1];
                expect(decimalPart.length).toBeLessThanOrEqual(6);
            }
        }
    });

    test('shows error for latitude outside valid range', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                latMin="100"
                latMax=""
            />,
        );

        expect(
            screen.getByText(/Latitude must be between -90 and \+90/i),
        ).toBeInTheDocument();
    });

    test('shows error for longitude outside valid range', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                lonMin="200"
                lonMax=""
            />,
        );

        expect(
            screen.getByText(/Longitude must be between -180 and \+180/i),
        ).toBeInTheDocument();
    });

    test('shows error when min is greater than max', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                latMin="50"
                latMax="40"
                lonMin=""
                lonMax=""
            />,
        );

        expect(
            screen.getByText(/Latitude Min must be less than Latitude Max/i),
        ).toBeInTheDocument();
    });

    test('marks latitude min and longitude min as required', () => {
        const { container } = render(<CoordinateInputs {...defaultProps} />);

        const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;
        const lonMinInput = container.querySelector('#lon-min') as HTMLInputElement;

        expect(latMinInput).toBeRequired();
        expect(lonMinInput).toBeRequired();
    });

    test('latitude max and longitude max are optional', () => {
        const { container } = render(<CoordinateInputs {...defaultProps} />);

        const latMaxInput = container.querySelector('#lat-max') as HTMLInputElement;
        const lonMaxInput = container.querySelector('#lon-max') as HTMLInputElement;

        expect(latMaxInput).not.toBeRequired();
        expect(lonMaxInput).not.toBeRequired();
    });

    test('displays section labels', () => {
        render(<CoordinateInputs {...defaultProps} />);

        expect(screen.getByText(/^Coordinates$/i)).toBeInTheDocument();
        expect(screen.getByText(/^Min \(Required\)$/i)).toBeInTheDocument();
        expect(screen.getByText(/^Max \(Optional\)$/i)).toBeInTheDocument();
    });
});
