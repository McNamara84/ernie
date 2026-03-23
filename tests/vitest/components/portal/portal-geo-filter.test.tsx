import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalGeoFilter } from '@/components/portal/PortalGeoFilter';
import type { GeoBounds } from '@/types/portal';

describe('PortalGeoFilter', () => {
    const defaultProps = {
        enabled: false,
        onToggle: vi.fn(),
        bounds: null as GeoBounds | null,
        onBoundsChange: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Disabled State', () => {
        it('renders the toggle switch and label', () => {
            render(<PortalGeoFilter {...defaultProps} />);

            expect(screen.getByText('Geographic Filter')).toBeInTheDocument();
            expect(screen.getByLabelText(/filter by map area/i)).toBeInTheDocument();
        });

        it('does not show coordinate fields when disabled', () => {
            render(<PortalGeoFilter {...defaultProps} />);

            expect(screen.queryByLabelText('N')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('S')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('E')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('W')).not.toBeInTheDocument();
        });

        it('does not show description text when disabled', () => {
            render(<PortalGeoFilter {...defaultProps} />);

            expect(screen.queryByText(/zoom or pan the map/i)).not.toBeInTheDocument();
        });

        it('does not show Active badge when disabled', () => {
            render(<PortalGeoFilter {...defaultProps} />);

            expect(screen.queryByText('Active')).not.toBeInTheDocument();
        });
    });

    describe('Enabled State', () => {
        it('shows coordinate fields when enabled', () => {
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            expect(screen.getByLabelText('N')).toBeInTheDocument();
            expect(screen.getByLabelText('S')).toBeInTheDocument();
            expect(screen.getByLabelText('E')).toBeInTheDocument();
            expect(screen.getByLabelText('W')).toBeInTheDocument();
        });

        it('shows description text when enabled', () => {
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            expect(screen.getByText(/zoom or pan the map/i)).toBeInTheDocument();
        });

        it('shows Bounding Box label when enabled', () => {
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            expect(screen.getByText('Bounding Box')).toBeInTheDocument();
        });

        it('shows Apply and Clear buttons when enabled', () => {
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            expect(screen.getByRole('button', { name: /apply/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /clear/i })).toBeInTheDocument();
        });

        it('shows Active badge when enabled with bounds', () => {
            const bounds: GeoBounds = { north: 53, south: 51, east: 14, west: 12 };
            render(<PortalGeoFilter {...defaultProps} enabled={true} bounds={bounds} />);

            expect(screen.getByText('Active')).toBeInTheDocument();
        });

        it('does not show Active badge when enabled without bounds', () => {
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            expect(screen.queryByText('Active')).not.toBeInTheDocument();
        });
    });

    describe('Toggle Behavior', () => {
        it('calls onToggle when switch is toggled on', async () => {
            const onToggle = vi.fn();
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} onToggle={onToggle} />);

            await user.click(screen.getByRole('switch'));

            expect(onToggle).toHaveBeenCalledWith(true);
        });

        it('calls onToggle and onBoundsChange(null) when toggled off', async () => {
            const onToggle = vi.fn();
            const onBoundsChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalGeoFilter
                    {...defaultProps}
                    enabled={true}
                    onToggle={onToggle}
                    onBoundsChange={onBoundsChange}
                />,
            );

            await user.click(screen.getByRole('switch'));

            expect(onToggle).toHaveBeenCalledWith(false);
            expect(onBoundsChange).toHaveBeenCalledWith(null);
        });
    });

    describe('Bounds Synchronization', () => {
        it('populates coordinate fields when bounds prop changes', () => {
            const bounds: GeoBounds = { north: 52.5174, south: 51.3497, east: 13.7612, west: 12.2371 };
            render(<PortalGeoFilter {...defaultProps} enabled={true} bounds={bounds} />);

            expect(screen.getByLabelText('N')).toHaveValue(52.5174);
            expect(screen.getByLabelText('S')).toHaveValue(51.3497);
            expect(screen.getByLabelText('E')).toHaveValue(13.7612);
            expect(screen.getByLabelText('W')).toHaveValue(12.2371);
        });

        it('clears coordinate fields when disabled and bounds is null', () => {
            const { rerender } = render(
                <PortalGeoFilter
                    {...defaultProps}
                    enabled={true}
                    bounds={{ north: 52, south: 51, east: 14, west: 12 }}
                />,
            );

            rerender(<PortalGeoFilter {...defaultProps} enabled={false} bounds={null} />);

            expect(screen.queryByLabelText('N')).not.toBeInTheDocument();
        });
    });

    describe('Apply Validation', () => {
        it('shows error when fields are empty and Apply is clicked', async () => {
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(screen.getByText('All four coordinates are required.')).toBeInTheDocument();
        });

        it('shows error for latitude out of range', async () => {
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '95' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '40' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '14' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '12' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(screen.getByText('Latitude must be between -90 and 90.')).toBeInTheDocument();
        });

        it('shows error for longitude out of range', async () => {
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '53' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '51' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '200' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '12' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(screen.getByText('Longitude must be between -180 and 180.')).toBeInTheDocument();
        });

        it('shows error when north is less than south', async () => {
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '40' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '50' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '14' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '12' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(screen.getByText('North must be greater than or equal to South.')).toBeInTheDocument();
        });

        it('calls onBoundsChange with valid coordinates on Apply', async () => {
            const onBoundsChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalGeoFilter {...defaultProps} enabled={true} onBoundsChange={onBoundsChange} />,
            );

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '53' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '51' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '14' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '12' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(onBoundsChange).toHaveBeenCalledWith({
                north: 53,
                south: 51,
                east: 14,
                west: 12,
            });
        });

        it('does not show error after successful apply', async () => {
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            // First trigger an error
            await user.click(screen.getByRole('button', { name: /apply/i }));
            expect(screen.getByText('All four coordinates are required.')).toBeInTheDocument();

            // Now fill in valid values and apply
            fireEvent.change(screen.getByLabelText('N'), { target: { value: '53' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '51' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '14' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '12' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(screen.queryByText('All four coordinates are required.')).not.toBeInTheDocument();
        });
    });

    describe('Clear Button', () => {
        it('calls onToggle(false) and onBoundsChange(null) when Clear is clicked', async () => {
            const onToggle = vi.fn();
            const onBoundsChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalGeoFilter
                    {...defaultProps}
                    enabled={true}
                    bounds={{ north: 53, south: 51, east: 14, west: 12 }}
                    onToggle={onToggle}
                    onBoundsChange={onBoundsChange}
                />,
            );

            await user.click(screen.getByRole('button', { name: /clear/i }));

            expect(onToggle).toHaveBeenCalledWith(false);
            expect(onBoundsChange).toHaveBeenCalledWith(null);
        });
    });

    describe('Edge Cases', () => {
        it('accepts boundary values at range limits', async () => {
            const onBoundsChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalGeoFilter {...defaultProps} enabled={true} onBoundsChange={onBoundsChange} />,
            );

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '90' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '-90' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '180' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '-180' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(onBoundsChange).toHaveBeenCalledWith({
                north: 90,
                south: -90,
                east: 180,
                west: -180,
            });
        });

        it('accepts north equal to south (single latitude line)', async () => {
            const onBoundsChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalGeoFilter {...defaultProps} enabled={true} onBoundsChange={onBoundsChange} />,
            );

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '52' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '52' } });
            fireEvent.change(screen.getByLabelText('E'), { target: { value: '14' } });
            fireEvent.change(screen.getByLabelText('W'), { target: { value: '12' } });

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(onBoundsChange).toHaveBeenCalledWith({
                north: 52,
                south: 52,
                east: 14,
                west: 12,
            });
        });

        it('handles partially filled coordinates as error', async () => {
            const user = userEvent.setup();
            render(<PortalGeoFilter {...defaultProps} enabled={true} />);

            fireEvent.change(screen.getByLabelText('N'), { target: { value: '53' } });
            fireEvent.change(screen.getByLabelText('S'), { target: { value: '51' } });
            // Leave E and W empty

            await user.click(screen.getByRole('button', { name: /apply/i }));

            expect(screen.getByText('All four coordinates are required.')).toBeInTheDocument();
        });
    });
});
