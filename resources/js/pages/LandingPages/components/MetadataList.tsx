import { Fragment, type ReactNode } from 'react';

/**
 * A single label/value row for {@link MetadataList}.
 *
 * A row is hidden when its `value` is `null`, `undefined`, an empty string,
 * or an empty array. This keeps modules visually clean when optional
 * metadata is missing.
 */
export interface MetadataRow {
    label: string;
    value: ReactNode | null | undefined;
}

interface MetadataListProps {
    rows: MetadataRow[];
}

/**
 * Returns `true` when the given value would be hidden by {@link MetadataList}.
 *
 * Exported so callers (e.g. section components) can reuse the exact same
 * emptiness rules when deciding whether to render a wrapping card, avoiding
 * drift between the list filter and external "has content" checks.
 */
export const isMetadataValueEmpty = (value: ReactNode | null | undefined): boolean => {
    if (value === null || value === undefined) {
        return true;
    }
    if (typeof value === 'string') {
        return value.trim() === '';
    }
    if (Array.isArray(value)) {
        return value.length === 0;
    }
    return false;
};

/**
 * Returns `true` when at least one row in `rows` would be rendered by
 * {@link MetadataList}. Useful for callers that only want to render a
 * surrounding card when the list is non-empty.
 */
export const hasVisibleMetadataRows = (rows: MetadataRow[]): boolean =>
    rows.some((row) => !isMetadataValueEmpty(row.value));

/**
 * Renders a definition-list-style label/value grid for compact metadata
 * sections (e.g. "General", "Acquisition" on IGSN landing pages).
 *
 * Returns `null` when every row would be hidden so callers can use the
 * presence of the rendered list to decide whether to render the wrapping
 * card at all.
 */
export function MetadataList({ rows }: MetadataListProps): ReactNode {
    const visible = rows.filter((row) => !isMetadataValueEmpty(row.value));

    if (visible.length === 0) {
        return null;
    }

    return (
        <dl
            data-slot="metadata-list"
            className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 text-sm"
        >
            {visible.map((row) => (
                <Fragment key={row.label}>
                    <dt className="font-medium text-gray-600 dark:text-gray-400">{row.label}</dt>
                    <dd className="min-w-0 wrap-break-word text-gray-900 dark:text-gray-100">{row.value}</dd>
                </Fragment>
            ))}
        </dl>
    );
}
