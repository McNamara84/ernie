/**
 * DataTable Component
 *
 * A feature-rich data table built on TanStack Table with shadcn/ui styling.
 * Supports both client-side and server-side pagination, sorting, and filtering.
 *
 * @example Basic usage (client-side)
 * ```tsx
 * import { DataTable } from '@/components/ui/data-table';
 * import { type DataTableColumnDef } from '@/components/ui/data-table';
 *
 * interface User {
 *     id: number;
 *     name: string;
 *     email: string;
 * }
 *
 * const columns: DataTableColumnDef<User>[] = [
 *     { accessorKey: 'name', header: 'Name' },
 *     { accessorKey: 'email', header: 'Email' },
 * ];
 *
 * <DataTable columns={columns} data={users} />
 * ```
 *
 * @example Server-side pagination
 * ```tsx
 * <DataTable
 *     columns={columns}
 *     data={resources}
 *     serverSide
 *     paginationInfo={{
 *         currentPage: pagination.current_page,
 *         lastPage: pagination.last_page,
 *         perPage: pagination.per_page,
 *         total: pagination.total,
 *         from: pagination.from,
 *         to: pagination.to,
 *     }}
 *     onPageChange={(page) => router.get('/resources', { page })}
 *     onPerPageChange={(perPage) => router.get('/resources', { per_page: perPage })}
 * />
 * ```
 *
 * @example With sortable columns
 * ```tsx
 * import { DataTableColumnHeader } from '@/components/ui/data-table';
 *
 * const columns: DataTableColumnDef<User>[] = [
 *     {
 *         accessorKey: 'name',
 *         header: ({ column }) => (
 *             <DataTableColumnHeader column={column} title="Name" />
 *         ),
 *     },
 * ];
 * ```
 */

export { DataTable } from './data-table';
export { DataTableColumnHeader, SimpleSortableHeader } from './data-table-column-header';
export type { DataTableColumnDef, DataTableFeatures, DataTableInstance } from './data-table-features';
export { dataTableFeatures } from './data-table-features';
export { DataTablePagination } from './data-table-pagination';
export { DataTablePageSkeleton, DataTableSkeleton } from './data-table-skeleton';
export { DataTableToolbar } from './data-table-toolbar';
export { DataTableViewOptions } from './data-table-view-options';
export type { ColumnFiltersState, ColumnVisibilityState, SortingState } from '@tanstack/react-table';
