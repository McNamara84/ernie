import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@tests/vitest/utils/render';
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
    it('orders readable names by resource usage and searches names as well as MIME types', async () => {
        const spreadsheet = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        const loader = vi
            .spyOn(mimeTypes, 'loadMimeTypes')
            .mockResolvedValue(['image/png', 'application/pdf', 'text/csv', 'application/json', spreadsheet]);
        const user = userEvent.setup();
        function PopularFormats() {
            const [formats, setFormats] = useState<FormatEntry[]>([]);
            return (
                <FormatSizeField
                    formats={formats}
                    sizes={[]}
                    formatUsage={{ 'text/csv': 8, 'application/json': 2, 'application/pdf': 2 }}
                    onFormatsChange={setFormats}
                    onSizesChange={() => {}}
                />
            );
        }
        try {
            render(<PopularFormats />);
            await user.click(screen.getByRole('button', { name: 'Add format' }));
            await user.click(screen.getByTestId('format-select-0'));
            await screen.findByRole('option', { name: 'CSV (text/csv)' });
            expect(screen.getAllByRole('option').map((option) => option.getAttribute('aria-label'))).toEqual([
                'CSV (text/csv)',
                'JSON (application/json)',
                'PDF (application/pdf)',
                `Excel (XLSX) (${spreadsheet})`,
                'PNG (image/png)',
            ]);
            await user.type(screen.getByPlaceholderText('Search formats or MIME types...'), 'Excel');
            await user.click(screen.getByRole('option', { name: `Excel (XLSX) (${spreadsheet})` }));
            expect(screen.getByTestId('format-select-0')).toHaveTextContent('Excel (XLSX)');
            expect(screen.getByTestId('format-select-0')).toHaveAttribute('title', spreadsheet);
        } finally {
            loader.mockRestore();
        }
    });

    it('can discard a removed non-IANA format without recreating its legacy value', async () => {
        const user = userEvent.setup();
        function LegacyConflict() {
            const [formats, setFormats] = useState<FormatEntry[]>([{ clientId: 'legacy', value: 'CSV legacy', removedOnServer: true }]);
            return <FormatSizeField formats={formats} sizes={[]} onFormatsChange={setFormats} onSizesChange={() => {}} />;
        }
        render(<LegacyConflict />);
        expect(screen.getByRole('button', { name: 'Keep as new format' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Discard local format change' }));
        expect(screen.queryByTestId('format-select-0')).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('requires a registered MIME type to resolve a removed legacy format as a new entry', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        function ConflictFields() {
            const [formats, setFormats] = useState<FormatEntry[]>([{ clientId: 'conflict', value: 'application/x-netcdf', removedOnServer: true }]);
            onChange(formats);
            return <FormatSizeField formats={formats} sizes={[]} onFormatsChange={setFormats} onSizesChange={() => {}} />;
        }
        render(<ConflictFields />);
        expect(screen.getByRole('alert')).toHaveTextContent('removed elsewhere');
        expect(screen.getByRole('button', { name: 'Keep as new format' })).toBeDisabled();
        await user.click(screen.getByTestId('format-select-0'));
        await user.type(screen.getByPlaceholderText('Search formats or MIME types...'), 'image/png');
        await user.click(await screen.findByRole('option', { name: 'PNG (image/png)' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Keep as new format' })).toBeEnabled());
        await user.click(screen.getByRole('button', { name: 'Keep as new format' }));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(onChange).toHaveBeenLastCalledWith([
            expect.objectContaining({ value: 'image/png', id: undefined, importKey: undefined, removedOnServer: undefined }),
        ]);
    });

    it('resolves a removed manual digital size without recreating protected meanings or non-digital metadata', async () => {
        const user = userEvent.setup();
        function ConflictSizes() {
            const [sizes, setSizes] = useState<SizeEntry[]>([
                { clientId: 'manual', numeric_value: '-2', unit: 'GB', type: null, removedOnServer: true },
                { clientId: 'meaning', numeric_value: '3', unit: 'GB', type: 'Primary Data Size', removedOnServer: true },
                { clientId: 'physical', numeric_value: '15', unit: 'pages', type: null, editable: false, removedOnServer: true },
            ]);
            return <FormatSizeField formats={[]} sizes={sizes} onFormatsChange={() => {}} onSizesChange={setSizes} />;
        }
        render(<ConflictSizes />);
        expect(screen.getAllByRole('button', { name: 'Keep as new size' })).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'Keep as new size' })).toBeDisabled();
        expect(within(screen.getByTestId('size-row-1')).queryByRole('button', { name: 'Keep as new size' })).not.toBeInTheDocument();
        expect(within(screen.getByTestId('size-row-2')).queryByRole('button', { name: 'Keep as new size' })).not.toBeInTheDocument();
        await user.clear(screen.getByTestId('size-value-0'));
        await user.type(screen.getByTestId('size-value-0'), '2');
        expect(screen.getByRole('button', { name: 'Keep as new size' })).toBeEnabled();
        await user.click(screen.getByRole('button', { name: 'Keep as new size' }));
        expect(within(screen.getByTestId('size-row-0')).queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByTestId('size-value-0')).toHaveValue('2');
        await user.click(within(screen.getByTestId('size-row-1')).getByRole('button', { name: 'Discard local size change' }));
        expect(screen.queryByText('Primary Data Size')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Discard local size change' }));
        expect(screen.queryByText(/preserved metadata/)).not.toBeInTheDocument();
    });

    it.each(['', '   '])('allows adding a MIME type alongside a preserved blank format (%j)', async (value) => {
        const user = userEvent.setup();
        function LegacyFields() {
            const [formats, setFormats] = useState<FormatEntry[]>([{ id: 1, clientId: 'legacy', value }]);
            return <FormatSizeField formats={formats} sizes={[]} onFormatsChange={setFormats} onSizesChange={() => {}} />;
        }
        render(<LegacyFields />);
        expect(screen.getByRole('button', { name: 'Add format' })).toBeEnabled();
        await user.click(screen.getByRole('button', { name: 'Add format' }));
        expect(screen.getByRole('button', { name: 'Add format' })).toBeDisabled();
        await user.click(screen.getByTestId('format-select-1'));
        await user.type(screen.getByPlaceholderText('Search formats or MIME types...'), 'text/csv');
        await user.click(await screen.findByRole('option', { name: 'CSV (text/csv)' }));
        expect(screen.getByTestId('format-select-1')).toHaveTextContent('CSV');
        expect(screen.getByRole('button', { name: 'Add format' })).toBeEnabled();
        expect(screen.getByTestId('format-select-0')).not.toHaveTextContent('CSV');
    });

    it('searches the complete IANA catalog and keeps format and size entries independent', async () => {
        const user = userEvent.setup();
        render(<EditorFields />);
        await user.click(screen.getByRole('button', { name: 'Add format' }));
        await user.click(screen.getByTestId('format-select-0'));
        await user.type(screen.getByPlaceholderText('Search formats or MIME types...'), 'model/gltf+json');
        await user.click(await screen.findByRole('option', { name: 'glTF (JSON) (model/gltf+json)' }));
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('glTF (JSON)');
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
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('NetCDF');
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
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('CSV');
        expect(loader).toHaveBeenCalledTimes(2);
        loader.mockRestore();
    });

    it('selects a MIME type with the keyboard and removes a digital size', async () => {
        const user = userEvent.setup();
        render(<EditorFields />);
        await user.click(screen.getByRole('button', { name: 'Add format' }));
        await user.click(screen.getByTestId('format-select-0'));
        await user.type(screen.getByPlaceholderText('Search formats or MIME types...'), 'image/png');
        await screen.findByRole('option', { name: 'PNG (image/png)' });
        await user.keyboard('{Enter}');
        expect(screen.getByTestId('format-select-0')).toHaveTextContent('PNG');
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
        await user.type(screen.getByPlaceholderText('Search formats or MIME types...'), 'no-such-registered-media-type');
        expect(await screen.findByText('No matching format.')).toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(screen.getByTestId('format-select-0')).toHaveAttribute('aria-expanded', 'false');
    });
});
