import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DatacenterField } from '@/components/curation/fields/datacenter-field';

const options = [
    { id: 1, name: 'Datacenter One' },
    { id: 2, name: 'Datacenter Two' },
];

describe('DatacenterField', () => {
    it('selects a data centre by ID and clears its required-field feedback on update', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const props = { id: 'datacenter', label: 'Data centre', required: true, options, onChange };
        const { rerender } = render(<DatacenterField {...props} selected={null} hasError errorMessage="Choose a data centre." />);
        const trigger = screen.getByRole('combobox', { name: /Data centre/ });
        expect(trigger).toBeRequired();
        expect(trigger).toBeInvalid();
        expect(screen.getByText('Choose a data centre.')).toBeVisible();

        await user.click(trigger);
        await user.click(screen.getByRole('option', { name: 'Datacenter Two' }));
        expect(onChange).toHaveBeenCalledExactlyOnceWith(2);
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        rerender(<DatacenterField {...props} selected={2} />);
        expect(trigger).toHaveTextContent('Datacenter Two');
        expect(trigger).not.toBeInvalid();
        expect(screen.queryByText('Choose a data centre.')).not.toBeInTheDocument();
    });

    it('clears the selected datacenter and closes the popover', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(<DatacenterField id="datacenter" label="Datacenter" options={options} selected={1} onChange={onChange} />);

        await user.click(screen.getByTestId('datacenter-select'));
        await user.click(screen.getByText('Clear selection'));

        expect(onChange).toHaveBeenCalledOnce();
        expect(onChange).toHaveBeenCalledWith(null);
        expect(screen.queryByText('Clear selection')).not.toBeInTheDocument();
    });

    it('does not offer the clear action without a selection', async () => {
        const user = userEvent.setup();

        render(<DatacenterField id="datacenter" label="Datacenter" options={options} selected={null} onChange={vi.fn()} />);

        await user.click(screen.getByTestId('datacenter-select'));

        expect(screen.queryByText('Clear selection')).not.toBeInTheDocument();
    });
});
