import {
    columnFilteringFeature,
    columnVisibilityFeature,
    createFilteredRowModel,
    createPaginatedRowModel,
    createSortedRowModel,
    rowPaginationFeature,
    rowSelectionFeature,
    rowSortingFeature,
    tableFeatures,
    type ColumnDef,
    type ReactTable,
    type RowData,
} from '@tanstack/react-table';

/**
 * TanStack Table 9 exposes feature APIs only when they are registered.
 * Keep this feature set shared by the table and its typed subcomponents.
 */
export const dataTableFeatures = tableFeatures({
    columnFilteringFeature,
    filteredRowModel: createFilteredRowModel(),
    columnVisibilityFeature,
    rowPaginationFeature,
    paginatedRowModel: createPaginatedRowModel(),
    rowSelectionFeature,
    rowSortingFeature,
    sortedRowModel: createSortedRowModel(),
});

export type DataTableFeatures = typeof dataTableFeatures;
export type DataTableColumnDef<TData extends RowData, TValue = unknown> = ColumnDef<DataTableFeatures, TData, TValue>;
export type DataTableInstance<TData extends RowData> = ReactTable<DataTableFeatures, TData>;
