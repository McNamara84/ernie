export type DateMode = 'single' | 'range';

export const DATE_RANGE_CAPABLE_TYPES = ['created', 'collected', 'valid', 'other'] as const;
export const NON_EDITABLE_DATE_TYPES = ['accepted', 'issued', 'updated', 'coverage'] as const;

export function normalizeDateTypeSlug(value: string | null | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

export function isAvailableDateType(dateType: string | null | undefined): boolean {
    return normalizeDateTypeSlug(dateType) === 'available';
}

export function isDateRangeCapable(dateType: string | null | undefined): boolean {
    return DATE_RANGE_CAPABLE_TYPES.includes(normalizeDateTypeSlug(dateType) as (typeof DATE_RANGE_CAPABLE_TYPES)[number]);
}

export function isEditableDateType(dateType: string | null | undefined): boolean {
    return !NON_EDITABLE_DATE_TYPES.includes(normalizeDateTypeSlug(dateType) as (typeof NON_EDITABLE_DATE_TYPES)[number]);
}
