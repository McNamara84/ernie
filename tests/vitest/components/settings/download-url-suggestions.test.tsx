import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DownloadUrlSuggestions } from '@/components/settings/download-url-suggestions';

const suggestions = [
    { value: 'https://popular.example.org/', usage_count: 12 },
    { value: 'https://rare.example.org/', usage_count: 1 },
];

describe('Download URL suggestion settings', () => {
    it('shows configured prefixes before observed domains without duplicate rows', () => {
        render(<DownloadUrlSuggestions order={['https://rare.example.org/']} suggestions={suggestions} onChange={vi.fn()} />);
        expect(screen.getByLabelText('Download URL suggestion 1')).toHaveValue('https://rare.example.org/');
        expect(screen.getByLabelText('Download URL suggestion 2')).toHaveValue('https://popular.example.org/');
        expect(screen.queryByLabelText('Download URL suggestion 3')).not.toBeInTheDocument();
    });

    it('moves every suggestion with accessible buttons and preserves focus while editing', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<DownloadUrlSuggestions order={[]} suggestions={suggestions} onChange={onChange} />);
        expect(screen.getByRole('button', { name: 'Move suggestion 1 up' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Move suggestion 2 up' }));
        expect(onChange).toHaveBeenLastCalledWith(['https://rare.example.org/', 'https://popular.example.org/']);
        const input = screen.getByLabelText('Download URL suggestion 1');
        await user.type(input, 'download');
        expect(input).toHaveFocus();
        expect(onChange).toHaveBeenLastCalledWith(['https://rare.example.org/download', 'https://popular.example.org/']);
    });

    it('supports ordering entirely through keyboard move controls', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<DownloadUrlSuggestions order={[]} suggestions={suggestions} onChange={onChange} />);
        screen.getByRole('button', { name: 'Move suggestion 1 down' }).focus();
        await user.keyboard('[Enter]');
        expect(onChange).toHaveBeenLastCalledWith(['https://rare.example.org/', 'https://popular.example.org/']);
    });

    it('adds a path prefix and removes configured entries without submitting the settings form', async () => {
        const onChange = vi.fn();
        const onSubmit = vi.fn((event) => event.preventDefault());
        const user = userEvent.setup();
        render(
            <form onSubmit={onSubmit}>
                <DownloadUrlSuggestions order={[]} suggestions={[]} onChange={onChange} />
            </form>,
        );
        await user.type(screen.getByLabelText('New download URL prefix'), 'https://datapub.gfz.de/download');
        await user.click(screen.getByRole('button', { name: 'Add prefix' }));
        expect(onChange).toHaveBeenLastCalledWith(['https://datapub.gfz.de/download']);
        await user.click(screen.getByRole('button', { name: 'Remove suggestion 1' }));
        expect(onChange).toHaveBeenLastCalledWith([]);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('associates server validation errors with the affected row', () => {
        render(
            <DownloadUrlSuggestions
                order={['invalid']}
                suggestions={[]}
                onChange={vi.fn()}
                errors={{ 'downloadUrlSuggestionOrder.0': 'Use an HTTP or HTTPS URL.' }}
            />,
        );
        expect(screen.getByLabelText('Download URL suggestion 1')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Use an HTTP or HTTPS URL.');
    });
});
