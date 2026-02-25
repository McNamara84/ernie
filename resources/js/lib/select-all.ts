/**
 * Compute the aggregate checked state for a list of boolean values.
 *
 * Used by "select all" header checkboxes in settings tables to determine
 * whether the header should show checked, unchecked, or indeterminate.
 */
export interface SelectAllState {
    /** True when every value is `true` (or the array is empty). */
    allChecked: boolean;
    /** True when every value is `false` (or the array is empty). */
    noneChecked: boolean;
    /** True when some — but not all — values are `true`. */
    indeterminate: boolean;
}

export function getSelectAllState(values: boolean[]): SelectAllState {
    const allChecked = values.length === 0 || values.every(Boolean);
    const noneChecked = values.length === 0 || values.every((v) => !v);
    const indeterminate = !allChecked && !noneChecked;

    return { allChecked, noneChecked, indeterminate };
}
