import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

import { FormatSizeField } from '@/components/curation/fields/format-size-field';
import type { FormatEntry, SizeEntry } from '@/lib/content-metadata';
import * as mimeTypes from '@/lib/mime-types';

function EditorFields() {
    const [formats, setFormats] = useState<FormatEntry[]>([]);
    const [sizes, setSizes] = useState<SizeEntry[]>([]);
    return <FormatSizeField formats={formats} sizes={sizes} onFormatsChange={setFormats} onSizesChange={setSizes} />;
}

describe('Format & Size fields', () => {
    it('searches the complete IANA catalog and keeps format and size entries independent', async () => {
        const user = userEvent.setup();
        render(<EditorFields />);
        await user.click(screen.getByRole('button', { name: 'Add format' }));
        await user.click(screen.getByTestId('format-select-0'));
        await user.type(screen.getByPlaceholderText('Search all IANA MIME types...'), 'model/gltf+json');
        await user.click(await screen.findByRole('option', { name: 'model/gltf+json' }));
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('model/gltf+json');
        expect(screen.queryByTestId('size-value-0')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Add size' }));
        await user.type(screen.getByTestId('size-value-0'), '1,5');
        await user.click(screen.getByTestId('size-unit-0'));
        await user.click(screen.getByRole('option', { name: 'GiB' }));
        expect(screen.getByTestId('size-value-0')).toHaveValue('1,5');
        expect(screen.getByTestId('size-unit-0')).toHaveTextContent('GiB');
        await user.click(screen.getByRole('button', { name: 'Remove format 1' }));
        expect(screen.getByTestId('size-value-0')).toHaveValue('1,5');
    });

    it('preserves legacy formats, meaning labels, and physical sizes', async () => {
        render(
            <FormatSizeField
                formats={[{ clientId: 'f', id: 1, value: 'application/x-netcdf' }]}
                sizes={[
                    {
                        clientId: 's',
                        id: 2,
                        numeric_value: '15.0000',
                        unit: 'pages',
                        type: 'Page Count',
                        editable: false,
                        label: '15 Page Count [pages]',
                    },
                ]}
                onFormatsChange={() => {}}
                onSizesChange={() => {}}
            />,
        );
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('application/x-netcdf');
        await waitFor(() => expect(screen.getByText(/Existing format/)).toBeInTheDocument());
        expect(screen.getByText('Page Count')).toBeInTheDocument();
        expect(screen.getByText(/15 Page Count/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove size 1' })).not.toBeInTheDocument();
    });

    it('shows accessible inline errors', () => {
        render(
            <FormatSizeField
                formats={[]}
                sizes={[{ clientId: 's', numeric_value: '-2', unit: 'GB', type: null }]}
                onFormatsChange={() => {}}
                onSizesChange={() => {}}
                errors={{ 'sizes.0.numeric_value': 'Enter a positive number.' }}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Enter a positive number.');
        expect(screen.getByTestId('size-value-0')).toHaveAttribute('aria-invalid', 'true');
    });

    it('suppresses digital sizes for physical objects', () => {
        render(<FormatSizeField formats={[]} sizes={[]} physicalObject onFormatsChange={() => {}} onSizesChange={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Add size' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add format' })).toBeInTheDocument();
    });

    it('allows retrying a failed catalog load while preserving existing values', async () => {
        const loader = vi.spyOn(mimeTypes, 'loadMimeTypes').mockRejectedValueOnce(new Error('Chunk unavailable')).mockResolvedValue(['text/csv']);
        const user = userEvent.setup();
        render(
            <FormatSizeField
                formats={[{ clientId: 'f', id: 3, value: 'text/csv' }]}
                sizes={[]}
                onFormatsChange={() => {}}
                onSizesChange={() => {}}
            />,
        );
        await user.click(await screen.findByRole('button', { name: 'Retry MIME types' }));
        await waitFor(() => expect(screen.queryByText(/could not be loaded/)).not.toBeInTheDocument());
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('text/csv');
        expect(loader).toHaveBeenCalledTimes(2);
        loader.mockRestore();
    });

    it('selects a MIME type with the keyboard and removes a digital size', async () => {
        const user = userEvent.setup();
        render(<EditorFields />);
        await user.click(screen.getByRole('button', { name: 'Add format' }));
        await user.click(screen.getByTestId('format-select-0'));
        await user.type(screen.getByPlaceholderText('Search all IANA MIME types...'), 'image/png');
        await screen.findByRole('option', { name: 'image/png' });
        await user.keyboard('{Enter}');
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('image/png');
        await user.click(screen.getByRole('button', { name: 'Add size' }));
        await user.click(screen.getByRole('button', { name: 'Remove size 1' }));
        expect(screen.queryByTestId('size-row-0')).not.toBeInTheDocument();
    });

    it('renders bounded catalog pages and searches all entries without retaining a stale result limit', async () => {
        const user = userEvent.setup();
        render(<EditorFields />);
        await user.click(screen.getByRole('button', { name: 'Add format' }));
        await user.click(screen.getByTestId('format-select-0'));
        await screen.findByRole('option', { name: /Show more MIME types/ });
        expect(screen.getAllByRole('option')).toHaveLength(61);
        await user.click(screen.getByRole('option', { name: /Show more MIME types/ }));
        expect(screen.getAllByRole('option')).toHaveLength(121);
        await user.type(screen.getByPlaceholderText('Search all IANA MIME types...'), 'no-such-registered-media-type');
        expect(await screen.findByText('No matching MIME type.')).toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(screen.getByTestId('format-select-0')).toHaveAttribute('aria-expanded', 'false');
    });
});
