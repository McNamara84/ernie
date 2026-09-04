import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ResourceImpactFilters } from '@/components/resource-impact-filters';

describe('ResourceImpactFilters', () => {
    it('normalizes and applies a DOI only when the form is submitted', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(<ResourceImpactFilters filters={{ doi: null, datacenter_id: null }} datacenterOptions={[]} onChange={onChange} />);

        await user.type(screen.getByLabelText('DOI'), ' https://doi.org/10.5880/FILTER.ONE ');
        expect(onChange).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Apply' }));

        expect(onChange).toHaveBeenCalledWith({ doi: '10.5880/filter.one', datacenter_id: null });
    });

    it('reports invalid DOI input without applying it', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(<ResourceImpactFilters filters={{ doi: null, datacenter_id: null }} datacenterOptions={[]} onChange={onChange} />);

        await user.type(screen.getByLabelText('DOI'), 'not-a-doi');
        await user.keyboard('{Enter}');

        expect((await screen.findByRole('alert')).textContent).toMatch(/Invalid DOI format/i);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('applies and clears a datacenter while showing active filter labels', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const { rerender } = render(
            <ResourceImpactFilters
                filters={{ doi: '10.5880/filter.one', datacenter_id: null }}
                datacenterOptions={[{ id: 7, name: 'GFZ Data Services' }]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByRole('combobox', { name: 'Filter by datacenter' }));
        await user.click(screen.getByRole('option', { name: 'GFZ Data Services' }));

        expect(onChange).toHaveBeenLastCalledWith({ doi: '10.5880/filter.one', datacenter_id: 7 });

        rerender(
            <ResourceImpactFilters
                filters={{ doi: '10.5880/filter.one', datacenter_id: 7 }}
                datacenterOptions={[{ id: 7, name: 'GFZ Data Services' }]}
                onChange={onChange}
            />,
        );

        expect(screen.getByText('Datacenter: GFZ Data Services')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Clear all' }));
        expect(onChange).toHaveBeenLastCalledWith({ doi: null, datacenter_id: null });
    });
});
