/**
 * Compute the aggregate checked state for a list of boolean values.
 *
 * Used by "select all" header checkboxes in settings tables to determine
 * whether the header should show checked, unchecked, or indeterminate.
 */
export interface SelectAllState {
    /** True when every value is `true` and the array is non-empty. */
    allChecked: boolean;
    /** True when every value is `false` or the array is empty. */
    noneChecked: boolean;
    /** True when some — but not all — values are `true`. */
    indeterminate: boolean;
    /** True when the input array has no elements. */
    isEmpty: boolean;
}

export function getSelectAllState(values: boolean[]): SelectAllState {
    if (values.length === 0) {
        return { allChecked: false, noneChecked: true, indeterminate: false, isEmpty: true };
    }

    const allChecked = values.every(Boolean);
    const noneChecked = values.every((v) => !v);
    const indeterminate = !allChecked && !noneChecked;

    return { allChecked, noneChecked, indeterminate, isEmpty: false };
}
