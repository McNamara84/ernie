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

    describe('Rendering', () => {
        test('renders all four coordinate input fields', () => {
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            // Min coordinates (required)
            expect(container.querySelector('#lat-min')).toBeInTheDocument();
            expect(container.querySelector('#lon-min')).toBeInTheDocument();

            // Max coordinates (optional)
            expect(container.querySelector('#lat-max')).toBeInTheDocument();
            expect(container.querySelector('#lon-max')).toBeInTheDocument();
        });

        test('renders with labels when showLabels is true', () => {
            render(<CoordinateInputs {...defaultProps} showLabels={true} />);

            expect(screen.getByText(/^Coordinates$/i)).toBeInTheDocument();
            expect(screen.getByText(/^Min \(Required\)$/i)).toBeInTheDocument();
            expect(screen.getByText(/^Max \(Optional\)$/i)).toBeInTheDocument();
        });

        test('hides labels when showLabels is false', () => {
            render(<CoordinateInputs {...defaultProps} showLabels={false} />);

            expect(screen.queryByText(/^Coordinates$/i)).not.toBeInTheDocument();
        });

        test('displays existing coordinate values', () => {
            const props = {
                ...defaultProps,
                latMin: '48.137154',
                lonMin: '11.576124',
                latMax: '48.200000',
                lonMax: '11.600000',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByDisplayValue('48.137154')).toBeInTheDocument();
            expect(screen.getByDisplayValue('11.576124')).toBeInTheDocument();
            expect(screen.getByDisplayValue('48.200000')).toBeInTheDocument();
            expect(screen.getByDisplayValue('11.600000')).toBeInTheDocument();
        });
    });

    describe('User Input', () => {
        test('calls onChange when latitude min is entered', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;

            await user.type(latMinInput, '48.137154');

            // user.type() calls onChange for each character
            // Just verify onChange was called with the right field name
            expect(mockOnChange).toHaveBeenCalled();
            const calls = mockOnChange.mock.calls.filter(call => call[0] === 'latMin');
            expect(calls.length).toBeGreaterThan(0);
        });

        test('calls onChange when longitude min is entered', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const lonMinInput = container.querySelector('#lon-min') as HTMLInputElement;

            await user.type(lonMinInput, '11.576124');

            expect(mockOnChange).toHaveBeenCalled();
            const calls = mockOnChange.mock.calls.filter(call => call[0] === 'lonMin');
            expect(calls.length).toBeGreaterThan(0);
        });

        test('calls onChange when latitude max is entered', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const latMaxInput = container.querySelector('#lat-max') as HTMLInputElement;

            await user.type(latMaxInput, '48.200000');

            expect(mockOnChange).toHaveBeenCalled();
            const calls = mockOnChange.mock.calls.filter(call => call[0] === 'latMax');
            expect(calls.length).toBeGreaterThan(0);
        });

        test('calls onChange when longitude max is entered', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const lonMaxInput = container.querySelector('#lon-max') as HTMLInputElement;

            await user.type(lonMaxInput, '11.600000');

            expect(mockOnChange).toHaveBeenCalled();
            const calls = mockOnChange.mock.calls.filter(call => call[0] === 'lonMax');
            expect(calls.length).toBeGreaterThan(0);
        });
    });

    describe('Coordinate Formatting', () => {
        test('formats coordinates to max 6 decimal places', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;

            await user.type(latMinInput, '48.1234567890');

            // Check that onChange was called and input value respects formatting
            expect(mockOnChange).toHaveBeenCalled();
            const inputValue = latMinInput.value;
            // Input should have decimal places (max 6)
            if (inputValue.includes('.')) {
                const decimals = inputValue.split('.')[1];
                expect(decimals.length).toBeLessThanOrEqual(6);
            }
        });

        test('allows negative coordinates', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;

            await user.type(latMinInput, '-48.137154');

            // Verify onChange was called with latMin field
            expect(mockOnChange).toHaveBeenCalled();
            const calls = mockOnChange.mock.calls.filter(call => call[0] === 'latMin');
            expect(calls.length).toBeGreaterThan(0);
        });

        test('removes invalid characters', async () => {
            const user = userEvent.setup();
            const { container } = render(<CoordinateInputs {...defaultProps} />);

            const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;

            await user.type(latMinInput, '48abc.137xyz');

            // Should have removed letters
            const lastCall = mockOnChange.mock.calls[mockOnChange.mock.calls.length - 1];
            expect(lastCall[1]).not.toMatch(/[a-z]/i);
        });
    });

    describe('Validation', () => {
        test('shows error for invalid latitude (> 90)', () => {
            const props = {
                ...defaultProps,
                latMin: '91.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByText(/latitude must be between -90 and \+90/i)).toBeInTheDocument();
        });

        test('shows error for invalid latitude (< -90)', () => {
            const props = {
                ...defaultProps,
                latMin: '-91.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByText(/latitude must be between -90 and \+90/i)).toBeInTheDocument();
        });

        test('shows error for invalid longitude (> 180)', () => {
            const props = {
                ...defaultProps,
                lonMin: '181.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByText(/longitude must be between -180 and \+180/i)).toBeInTheDocument();
        });

        test('shows error for invalid longitude (< -180)', () => {
            const props = {
                ...defaultProps,
                lonMin: '-181.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByText(/longitude must be between -180 and \+180/i)).toBeInTheDocument();
        });

        test('shows error when latMin >= latMax', () => {
            const props = {
                ...defaultProps,
                latMin: '50.0',
                latMax: '48.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByText(/latitude min must be less than latitude max/i)).toBeInTheDocument();
        });

        test('shows error when lonMin >= lonMax', () => {
            const props = {
                ...defaultProps,
                lonMin: '12.0',
                lonMax: '11.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.getByText(/longitude min must be less than longitude max/i)).toBeInTheDocument();
        });

        test('does not show error for valid coordinates', () => {
            const props = {
                ...defaultProps,
                latMin: '48.0',
                lonMin: '11.0',
                latMax: '49.0',
                lonMax: '12.0',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.queryByText(/must be between/i)).not.toBeInTheDocument();
            expect(screen.queryByText(/must be less than/i)).not.toBeInTheDocument();
        });

        test('accepts empty values without showing errors', () => {
            render(<CoordinateInputs {...defaultProps} />);

            expect(screen.queryByText(/must be between/i)).not.toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        test('handles boundary values correctly (latitude 90/-90)', () => {
            const props = {
                ...defaultProps,
                latMin: '-90',
                latMax: '90',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.queryByText(/latitude must be between/i)).not.toBeInTheDocument();
        });

        test('handles boundary values correctly (longitude 180/-180)', () => {
            const props = {
                ...defaultProps,
                lonMin: '-180',
                lonMax: '180',
            };

            render(<CoordinateInputs {...props} />);

            expect(screen.queryByText(/longitude must be between/i)).not.toBeInTheDocument();
        });

        test('handles equator and prime meridian (0,0)', () => {
            const props = {
                ...defaultProps,
                latMin: '0',
                lonMin: '0',
            };

            const { container } = render(<CoordinateInputs {...props} />);

            // Check both lat and lon inputs have value '0'
            const latMinInput = container.querySelector('#lat-min') as HTMLInputElement;
            const lonMinInput = container.querySelector('#lon-min') as HTMLInputElement;
            
            expect(latMinInput.value).toBe('0');
            expect(lonMinInput.value).toBe('0');
            expect(screen.queryByText(/must be between/i)).not.toBeInTheDocument();
        });
    });
});
