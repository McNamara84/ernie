/**
 * @vitest-environment jsdom
 */

import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import CoordinateCsvImport from '@/components/curation/fields/spatial-temporal-coverage/coordinate-csv-import';

describe('CoordinateCsvImport Component', () => {
    const mockOnImport = vi.fn();
    const mockOnClose = vi.fn();

    const defaultProps = {
        onImport: mockOnImport,
        onClose: mockOnClose,
        existingPointCount: 0,
        geoType: 'polygon' as const,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    const createMockFile = (content: string, filename: string): File => {
        const file = new File([content], filename, { type: 'text/csv' });
        Object.defineProperty(file, 'text', {
            value: vi.fn().mockResolvedValue(content),
        });
        return file;
    };

    const uploadFile = async (content: string, filename = 'coordinates.csv') => {
        const user = userEvent.setup({ delay: null });
        render(<CoordinateCsvImport {...defaultProps} />);
        const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
        const file = createMockFile(content, filename);
        await user.upload(fileInput, file);
        return user;
    };

    describe('Initial Render', () => {
        it('renders CSV import dialog with header', () => {
            render(<CoordinateCsvImport {...defaultProps} />);

            expect(screen.getByText('CSV Coordinate Import')).toBeInTheDocument();
            expect(screen.getByText(/Import coordinate pairs for your polygon/)).toBeInTheDocument();
        });

        it('shows line label when geoType is line', () => {
            render(<CoordinateCsvImport {...defaultProps} geoType="line" />);

            expect(screen.getByText(/Import coordinate pairs for your line/)).toBeInTheDocument();
        });

        it('shows close button', () => {
            render(<CoordinateCsvImport {...defaultProps} />);

            expect(screen.getByLabelText('Close CSV import')).toBeInTheDocument();
        });

        it('shows example download button', () => {
            render(<CoordinateCsvImport {...defaultProps} />);

            expect(screen.getByText('Example CSV')).toBeInTheDocument();
        });

        it('shows file upload area with instructions', () => {
            render(<CoordinateCsvImport {...defaultProps} />);

            expect(screen.getByText('Drop your CSV file here or click to browse')).toBeInTheDocument();
            expect(screen.getByText(/One coordinate pair per row/)).toBeInTheDocument();
        });

        it('has hidden file input', () => {
            render(<CoordinateCsvImport {...defaultProps} />);

            const fileInput = document.querySelector('#csv-upload-coordinates');
            expect(fileInput).toBeInTheDocument();
            expect(fileInput).toHaveAttribute('type', 'file');
            expect(fileInput).toHaveAttribute('accept', '.csv,text/csv');
        });

        it('import button is disabled initially', () => {
            render(<CoordinateCsvImport {...defaultProps} />);

            const importButton = screen.getByRole('button', { name: /Import/ });
            expect(importButton).toBeDisabled();
        });

        it('does not show replace/append when no existing points', () => {
            render(<CoordinateCsvImport {...defaultProps} existingPointCount={0} />);

            expect(screen.queryByText('Import Mode')).not.toBeInTheDocument();
        });
    });

    describe('Example CSV Download', () => {
        it('downloads polygon example CSV when button is clicked', async () => {
            const user = userEvent.setup({ delay: null });
            const mockObjectURL = 'blob:test';
            globalThis.URL.createObjectURL = vi.fn(() => mockObjectURL);
            globalThis.URL.revokeObjectURL = vi.fn();

            render(<CoordinateCsvImport {...defaultProps} geoType="polygon" />);

            const clickSpy = vi.fn();
            const originalCreateElement = document.createElement.bind(document);
            vi.spyOn(document, 'createElement').mockImplementation((tagName: string) => {
                const element = originalCreateElement(tagName);
                if (tagName === 'a') {
                    element.click = clickSpy;
                }
                return element;
            });

            await user.click(screen.getByText('Example CSV'));

            expect(globalThis.URL.createObjectURL).toHaveBeenCalled();
            expect(clickSpy).toHaveBeenCalled();
            expect(globalThis.URL.revokeObjectURL).toHaveBeenCalledWith(mockObjectURL);

            vi.mocked(document.createElement).mockRestore();
        });
    });

    describe('CSV Parsing - Header Detection', () => {
        it('parses CSV with latitude,longitude headers', async () => {
            await uploadFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.381,13.070`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 coordinate pair/)).toBeInTheDocument();
            });
        });

        it('parses CSV with lat,lon headers', async () => {
            await uploadFile(`lat,lon\n52.381,13.066\n52.382,13.068\n52.381,13.070`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 coordinate pair/)).toBeInTheDocument();
            });
        });

        it('parses CSV with lon,lat headers (reversed order)', async () => {
            await uploadFile(`longitude,latitude\n13.066,52.381\n13.068,52.382\n13.070,52.381`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 coordinate pair/)).toBeInTheDocument();
                // Values appear multiple times in the table, use getAllByText
                expect(screen.getAllByText('52.381').length).toBeGreaterThanOrEqual(1);
                expect(screen.getAllByText('13.066').length).toBeGreaterThanOrEqual(1);
            });
        });

        it('parses CSV with lng,lat headers', async () => {
            await uploadFile(`lng,lat\n13.066,52.381\n13.068,52.382`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 2 coordinate pair/)).toBeInTheDocument();
            });
        });

        it('shows fallback warning for unrecognized 2-column headers', async () => {
            await uploadFile(`x,y\n52.381,13.066\n52.382,13.068`);

            await waitFor(() => {
                expect(screen.getByText(/No recognized column headers found/)).toBeInTheDocument();
                expect(screen.getByText(/Successfully parsed 2 coordinate pair/)).toBeInTheDocument();
            });
        });

        it('shows error for unrecognizable headers with more than 2 columns', async () => {
            await uploadFile(`x,y,z\n1,2,3\n4,5,6`);

            await waitFor(() => {
                expect(screen.getByText(/Could not detect latitude\/longitude columns/)).toBeInTheDocument();
            });
        });
    });

    describe('CSV Parsing - Coordinate Validation', () => {
        it('rejects latitude out of range (> 90)', async () => {
            await uploadFile(`latitude,longitude\n91.0,13.066`);

            await waitFor(() => {
                expect(screen.getByText(/91 is out of range/)).toBeInTheDocument();
            });
        });

        it('rejects latitude out of range (< -90)', async () => {
            await uploadFile(`latitude,longitude\n-91.0,13.066`);

            await waitFor(() => {
                expect(screen.getByText(/-91 is out of range/)).toBeInTheDocument();
            });
        });

        it('rejects longitude out of range (> 180)', async () => {
            await uploadFile(`latitude,longitude\n52.381,181.0`);

            await waitFor(() => {
                expect(screen.getByText(/181 is out of range/)).toBeInTheDocument();
            });
        });

        it('rejects longitude out of range (< -180)', async () => {
            await uploadFile(`latitude,longitude\n52.381,-181.0`);

            await waitFor(() => {
                expect(screen.getByText(/-181 is out of range/)).toBeInTheDocument();
            });
        });

        it('rejects non-numeric latitude', async () => {
            await uploadFile(`latitude,longitude\nabc,13.066`);

            await waitFor(() => {
                expect(screen.getByText(/"abc" is not a valid number/)).toBeInTheDocument();
            });
        });

        it('rejects non-numeric longitude', async () => {
            await uploadFile(`latitude,longitude\n52.381,xyz`);

            await waitFor(() => {
                expect(screen.getByText(/"xyz" is not a valid number/)).toBeInTheDocument();
            });
        });

        it('rejects empty latitude', async () => {
            await uploadFile(`latitude,longitude\n,13.066`);

            await waitFor(() => {
                expect(screen.getByText(/Latitude is empty/)).toBeInTheDocument();
            });
        });

        it('rejects empty longitude', async () => {
            await uploadFile(`latitude,longitude\n52.381,`);

            await waitFor(() => {
                expect(screen.getByText(/Longitude is empty/)).toBeInTheDocument();
            });
        });

        it('accepts valid boundary values (-90, 90, -180, 180)', async () => {
            await uploadFile(`latitude,longitude\n-90,180\n90,-180\n0,0`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 coordinate pair/)).toBeInTheDocument();
            });
        });

        it('imports valid rows and skips invalid ones', async () => {
            await uploadFile(`latitude,longitude\n52.381,13.066\n999,13.068\n52.382,13.070`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 2 coordinate pair/)).toBeInTheDocument();
                expect(screen.getByText(/Found 1 validation error/)).toBeInTheDocument();
            });
        });
    });

    describe('CSV Parsing - Edge Cases', () => {
        it('shows error for empty CSV file', async () => {
            await uploadFile(`latitude,longitude`);

            await waitFor(() => {
                expect(screen.getByText(/CSV file is empty or has no data rows/)).toBeInTheDocument();
            });
        });

        it('removes consecutive duplicate points', async () => {
            await uploadFile(`latitude,longitude\n52.381,13.066\n52.381,13.066\n52.382,13.068`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 2 coordinate pair/)).toBeInTheDocument();
                expect(screen.getByText(/1 consecutive duplicate removed/)).toBeInTheDocument();
            });
        });

        it('skips completely empty rows silently', async () => {
            await uploadFile(`latitude,longitude\n52.381,13.066\n\n52.382,13.068`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 2 coordinate pair/)).toBeInTheDocument();
            });
        });

        it('shows warning when polygon has fewer than 3 points', async () => {
            render(<CoordinateCsvImport {...defaultProps} geoType="polygon" />);
            const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
            const file = createMockFile(`latitude,longitude\n52.381,13.066\n52.382,13.068`, 'coords.csv');
            const user = userEvent.setup({ delay: null });
            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/A valid polygon requires at least 3 points/)).toBeInTheDocument();
            });
        });

        it('shows warning when line has fewer than 2 points', async () => {
            render(<CoordinateCsvImport {...defaultProps} geoType="line" />);
            const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
            const file = createMockFile(`latitude,longitude\n52.381,13.066`, 'coords.csv');
            const user = userEvent.setup({ delay: null });
            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/A valid line requires at least 2 points/)).toBeInTheDocument();
            });
        });

        it('shows max 10 errors and indicates remaining', async () => {
            const rows = Array.from({ length: 15 }, (_, i) => `abc${i},def${i}`).join('\n');
            await uploadFile(`latitude,longitude\n${rows}`);

            await waitFor(() => {
                expect(screen.getByText(/and 5 more errors/)).toBeInTheDocument();
            });
        });
    });

    describe('File Validation', () => {
        it('rejects non-CSV files via drag and drop', async () => {
            const user = userEvent.setup({ delay: null });
            render(<CoordinateCsvImport {...defaultProps} />);

            const dropZone = screen.getByText('Drop your CSV file here or click to browse').closest('div')!;
            const file = new File(['test'], 'data.txt', { type: 'text/plain' });

            const dataTransfer = {
                files: [file],
                items: [{ kind: 'file', type: file.type, getAsFile: () => file }],
                types: ['Files'],
            };

            await user.pointer([{ target: dropZone }]);
            dropZone.dispatchEvent(new Event('dragover', { bubbles: true }));
            const dropEvent = new Event('drop', { bubbles: true }) as unknown as DragEvent;
            Object.defineProperty(dropEvent, 'dataTransfer', { value: dataTransfer });
            Object.defineProperty(dropEvent, 'preventDefault', { value: vi.fn() });
            dropZone.dispatchEvent(dropEvent);

            await waitFor(() => {
                expect(screen.getByText('Please drop a valid CSV file')).toBeInTheDocument();
            });
        });
    });

    describe('Replace / Append Mode', () => {
        it('shows replace/append options when existing points exist', async () => {
            render(<CoordinateCsvImport {...defaultProps} existingPointCount={5} />);
            const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
            const file = createMockFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.383,13.070`, 'coords.csv');
            const user = userEvent.setup({ delay: null });
            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText('Import Mode')).toBeInTheDocument();
                expect(screen.getByText(/Replace existing 5 points/)).toBeInTheDocument();
                expect(screen.getByText(/Append imported data to existing 5 points/)).toBeInTheDocument();
            });
        });

        it('does not show replace/append when no existing points', async () => {
            await uploadFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.383,13.070`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 coordinate pair/)).toBeInTheDocument();
                expect(screen.queryByText('Import Mode')).not.toBeInTheDocument();
            });
        });

        it('defaults to replace mode', async () => {
            render(<CoordinateCsvImport {...defaultProps} existingPointCount={3} />);
            const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
            const file = createMockFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.383,13.070`, 'coords.csv');
            const user = userEvent.setup({ delay: null });
            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText('Import Mode')).toBeInTheDocument();
            });

            const replaceRadio = screen.getByLabelText(/Replace existing/);
            expect(replaceRadio).toBeChecked();
        });
    });

    describe('Import Action', () => {
        it('calls onImport with parsed points and replace mode', async () => {
            render(<CoordinateCsvImport {...defaultProps} existingPointCount={0} />);
            const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
            const file = createMockFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.383,13.070`, 'coords.csv');
            const user = userEvent.setup({ delay: null });
            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 3 coordinate pair/)).toBeInTheDocument();
            });

            const importButton = screen.getByRole('button', { name: /Import 3 Points/ });
            expect(importButton).not.toBeDisabled();
            await user.click(importButton);

            expect(mockOnImport).toHaveBeenCalledWith(
                [
                    { lat: 52.381, lon: 13.066 },
                    { lat: 52.382, lon: 13.068 },
                    { lat: 52.383, lon: 13.07 },
                ],
                'replace',
            );
            expect(mockOnClose).toHaveBeenCalled();
        });

        it('calls onImport with append mode when selected', async () => {
            render(<CoordinateCsvImport {...defaultProps} existingPointCount={2} />);
            const fileInput = document.querySelector('#csv-upload-coordinates') as HTMLInputElement;
            const file = createMockFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.383,13.070`, 'coords.csv');
            const user = userEvent.setup({ delay: null });
            await user.upload(fileInput, file);

            await waitFor(() => {
                expect(screen.getByText('Import Mode')).toBeInTheDocument();
            });

            const appendRadio = screen.getByLabelText(/Append imported data/);
            await user.click(appendRadio);

            const importButton = screen.getByRole('button', { name: /Import 3 Points/ });
            await user.click(importButton);

            expect(mockOnImport).toHaveBeenCalledWith(expect.any(Array), 'append');
        });

        it('calls onClose when cancel is clicked', async () => {
            const user = userEvent.setup({ delay: null });
            render(<CoordinateCsvImport {...defaultProps} />);

            await user.click(screen.getByRole('button', { name: 'Cancel' }));
            expect(mockOnClose).toHaveBeenCalled();
        });
    });

    describe('Preview Table', () => {
        it('shows coordinate preview table with first 10 points', async () => {
            const rows = Array.from({ length: 15 }, (_, i) => `${52 + i * 0.001},${13 + i * 0.001}`).join('\n');
            await uploadFile(`latitude,longitude\n${rows}`);

            await waitFor(() => {
                expect(screen.getByText(/Successfully parsed 15 coordinate pair/)).toBeInTheDocument();
                expect(screen.getByText(/and 5 more/)).toBeInTheDocument();
            });
        });

        it('shows file info after upload', async () => {
            await uploadFile(`latitude,longitude\n52.381,13.066\n52.382,13.068\n52.383,13.070`);

            await waitFor(() => {
                expect(screen.getByText('coordinates.csv')).toBeInTheDocument();
            });
        });
    });
});
