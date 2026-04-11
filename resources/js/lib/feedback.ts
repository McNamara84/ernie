import { toast } from 'sonner';

/**
 * Centralized feedback helpers for consistent toast messages across the application.
 *
 * Strategy:
 * - Toasts for all action confirmations (save, delete, create, etc.)
 * - Inline FormMessage for field-level validation errors
 * - AlertDialog before destructive actions
 * - toast.error for system errors (auto-dismiss like all other toasts)
 */
export const feedback = {
    saved: (entity: string) => toast.success(`${entity} saved successfully`),
    deleted: (entity: string) => toast.success(`${entity} deleted`),
    created: (entity: string) => toast.success(`${entity} created`),
    updated: (entity: string) => toast.success(`${entity} updated`),
    error: (message: string) => toast.error(message),
    networkError: () => toast.error('Network error. Please try again.'),
    sessionExpired: () => toast.error('Session expired. Please log in again.'),
    importStarted: (entity: string) => toast.info(`${entity} import started`),
    importCompleted: (entity: string, count?: number) =>
        toast.success(count !== undefined ? `${entity} import completed: ${count} items imported` : `${entity} import completed`),
};
