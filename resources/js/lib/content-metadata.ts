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
type LocalMetadataState = { removedOnServer?: boolean };
export type FormatEntry = ResourceFormat & LocalMetadataState & { clientId: string };
export type SizeEntry = ResourceSize & LocalMetadataState & { clientId: string };
export type ContentMetadataClientIds = { formats: string[]; sizes: string[] };

export const REMOVED_METADATA_MESSAGE = 'This entry was removed elsewhere while you were editing it. Review your local change before saving.';

export function hasFormatContent(row: ResourceFormat): boolean {
    return row.id !== undefined || row.value.trim() !== '';
}

export function hasSizeContent(row: ResourceSize): boolean {
    return row.id !== undefined || (row.numeric_value ?? '').trim() !== '' || (row.unit ?? '') !== '';
}

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
    return rows.filter(hasFormatContent).map((row) => ({
        ...(row.id !== undefined ? { id: row.id } : {}),
        value: row.value,
        ...(row.importKey ? { importKey: row.importKey } : {}),
    }));
}

export function sizePayload(rows: ResourceSize[]): ResourceSize[] {
    return rows.filter(hasSizeContent).map((row) => ({
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

export function contentMetadataErrors(
    sizes: (ResourceSize & LocalMetadataState)[],
    formats: (ResourceFormat & LocalMetadataState)[] = [],
): Record<string, string> {
    const errors: Record<string, string> = {};
    formats.forEach((row, index) => {
        if (row.removedOnServer) errors[`formats.${index}.value`] = REMOVED_METADATA_MESSAGE;
    });
    sizes.forEach((row, index) => {
        if (row.removedOnServer) {
            errors[`sizes.${index}.numeric_value`] = REMOVED_METADATA_MESSAGE;
            return;
        }
        if (!hasSizeContent(row) || row.editable === false) return;
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

/** Backend indexes refer to the compacted request; client IDs identify the current UI row. */
export function remapContentMetadataErrors(
    errors: Record<string, string[]>,
    submitted: ContentMetadataClientIds,
    current: { formats: FormatEntry[]; sizes: SizeEntry[] },
): Record<string, string[]> {
    const result: Record<string, string[]> = {};
    for (const [key, messages] of Object.entries(errors)) {
        const match = key.match(/^(formats|sizes)\.(\d+)(\..+)?$/);
        let target = key;
        if (match) {
            const group = match[1] as keyof ContentMetadataClientIds;
            const clientId = submitted[group][Number(match[2])];
            const index = clientId === undefined ? -1 : current[group].findIndex((row) => row.clientId === clientId);
            // A removed row has no field to focus. Keep its error at section level.
            target = index < 0 ? group : `${group}.${index}${match[3] ?? ''}`;
        }
        result[target] = [...(result[target] ?? []), ...messages];
    }
    return result;
}

export function withClientIds<T extends ResourceFormat | ResourceSize>(rows: T[]): (T & { clientId: string })[] {
    return rows.map((row) => ({ ...row, clientId: crypto.randomUUID() }));
}
