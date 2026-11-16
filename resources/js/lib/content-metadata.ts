export const SIZE_UNITS = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'] as const;

export type ResourceFormat = { id?: number; value: string; importKey?: string };
export type ResourceSize = {
    id?: number;
    numeric_value: string | null;
    unit: string | null;
    type: string | null;
    editable?: boolean;
    label?: string;
    importKey?: string;
};
export type ContentMetadata = { formats: ResourceFormat[]; sizes: ResourceSize[]; fingerprint: string | null };
export type FormatEntry = ResourceFormat & { clientId: string };
export type SizeEntry = ResourceSize & { clientId: string };

export function normalizedSizeNumber(value: string | null): string {
    const number = (value ?? '').trim().replace(',', '.');
    if (!/^\d{1,16}(?:\.\d{1,4})?$/.test(number)) return number;
    const [whole, fraction = ''] = number.split('.');
    const trimmedFraction = fraction.replace(/0+$/, '');
    return (whole.replace(/^0+/, '') || '0') + (trimmedFraction ? `.${trimmedFraction}` : '');
}

export function normalizedSizeUnit(value: string | null): string {
    return value === 'bytes' || value === 'byte' ? 'B' : value === 'KB' ? 'kB' : (value ?? '');
}

export function formatPayload(rows: ResourceFormat[]): ResourceFormat[] {
    return rows
        .filter((row) => row.id !== undefined || row.value.trim() !== '')
        .map((row) => ({
            ...(row.id !== undefined ? { id: row.id } : {}),
            value: row.value,
            ...(row.importKey ? { importKey: row.importKey } : {}),
        }));
}

export function sizePayload(rows: ResourceSize[]): ResourceSize[] {
    return rows
        .filter((row) => row.id !== undefined || (row.numeric_value ?? '').trim() !== '' || (row.unit ?? '') !== '')
        .map((row) => ({
            ...(row.id !== undefined ? { id: row.id } : {}),
            numeric_value: row.numeric_value,
            unit: row.unit,
            type: row.type,
            ...(row.importKey ? { importKey: row.importKey } : {}),
        }));
}

export function contentMetadataSnapshot(formats: ResourceFormat[], sizes: ResourceSize[]): string {
    return JSON.stringify({
        formats: formatPayload(formats).map((row) => row.value),
        sizes: sizePayload(sizes).map((row) => [normalizedSizeNumber(row.numeric_value), normalizedSizeUnit(row.unit), row.type]),
    });
}

export function contentMetadataErrors(sizes: ResourceSize[]): Record<string, string> {
    const errors: Record<string, string> = {};
    sizes
        .filter((row) => row.id !== undefined || (row.numeric_value ?? '').trim() !== '' || (row.unit ?? '') !== '')
        .forEach((row, index) => {
            if (row.editable === false) return;
            const number = normalizedSizeNumber(row.numeric_value);
            if (!/^\d{1,16}(?:\.\d{1,4})?$/.test(number) || number === '0') {
                errors[`sizes.${index}.numeric_value`] = 'Enter a positive number with up to 16 integer digits and 4 decimal places.';
            }
            if (!(SIZE_UNITS as readonly string[]).includes(normalizedSizeUnit(row.unit))) {
                errors[`sizes.${index}.unit`] = 'Select a size unit.';
            }
        });
    return errors;
}

export function withClientIds<T extends ResourceFormat | ResourceSize>(rows: T[]): (T & { clientId: string })[] {
    return rows.map((row) => ({ ...row, clientId: crypto.randomUUID() }));
}
