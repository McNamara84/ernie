export type DateMode = 'single' | 'range';

export const DATE_RANGE_CAPABLE_TYPES = ['collected', 'valid', 'other'] as const;
export const NON_EDITABLE_DATE_TYPES = ['created', 'updated', 'coverage'] as const;

export function normalizeDateTypeSlug(value: string | null | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

export function isDateRangeCapable(dateType: string | null | undefined): boolean {
    return DATE_RANGE_CAPABLE_TYPES.includes(normalizeDateTypeSlug(dateType) as (typeof DATE_RANGE_CAPABLE_TYPES)[number]);
}

export function isEditableDateType(dateType: string | null | undefined): boolean {
    return !NON_EDITABLE_DATE_TYPES.includes(normalizeDateTypeSlug(dateType) as (typeof NON_EDITABLE_DATE_TYPES)[number]);
}
