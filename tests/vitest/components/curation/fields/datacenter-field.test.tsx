import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DatacenterField } from '@/components/curation/fields/datacenter-field';

const options = [
    { id: 1, name: 'Datacenter One' },
    { id: 2, name: 'Datacenter Two' },
];

describe('DatacenterField', () => {
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
