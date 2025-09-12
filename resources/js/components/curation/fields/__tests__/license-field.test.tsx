import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import LicenseField from '../license-field';

describe('LicenseField', () => {
    it('renders add button for first row', async () => {
        const onAdd = vi.fn();
        render(
            <LicenseField
                id="row-0"
                license=""
                options={[]}
                onLicenseChange={() => {}}
                onAdd={onAdd}
                onRemove={() => {}}
                isFirst
            />,
        );
        const addButton = screen.getByRole('button', { name: 'Add license' });
        await userEvent.click(addButton);
        expect(onAdd).toHaveBeenCalled();
    });

    it('renders remove button for other rows', async () => {
        const onRemove = vi.fn();
        render(
            <LicenseField
                id="row-1"
                license=""
                options={[]}
                onLicenseChange={() => {}}
                onAdd={() => {}}
                onRemove={onRemove}
                isFirst={false}
            />,
        );
        const removeButton = screen.getByRole('button', { name: 'Remove license' });
        await userEvent.click(removeButton);
        expect(onRemove).toHaveBeenCalled();
    });

    it('hides label for non-first rows', () => {
        render(
            <LicenseField
                id="row-1"
                license=""
                options={[]}
                onLicenseChange={() => {}}
                onAdd={() => {}}
                onRemove={() => {}}
                isFirst={false}
            />,
        );
        expect(screen.getByText('License')).toHaveClass('sr-only');
    });

    it('disables add button when cannot add', () => {
        render(
            <LicenseField
                id="row-0"
                license=""
                options={[]}
                onLicenseChange={() => {}}
                onAdd={() => {}}
                onRemove={() => {}}
                isFirst
                canAdd={false}
            />,
        );
        expect(screen.getByRole('button', { name: 'Add license' })).toBeDisabled();
    });
});

