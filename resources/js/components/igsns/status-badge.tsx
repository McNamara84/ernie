import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type UploadStatus = 'pending' | 'uploaded' | 'validating' | 'validated' | 'registering' | 'registered' | 'error';

interface IgsnStatusBadgeProps {
    status: UploadStatus | string;
    className?: string;
}

/**
 * Status configuration for IGSN upload states.
 *
 * Defines the visual appearance and labels for each upload status.
 */
const statusConfig: Record<
    UploadStatus,
    {
        label: string;
        variant: 'default' | 'secondary' | 'destructive' | 'outline';
        className?: string;
    }
> = {
    pending: {
        label: 'Pending',
        variant: 'secondary',
        className: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    },
    uploaded: {
        label: 'Uploaded',
        variant: 'outline',
        className: 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    },
    validating: {
        label: 'Validating',
        variant: 'outline',
        className: 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300',
    },
    validated: {
        label: 'Validated',
        variant: 'outline',
        className: 'border-cyan-300 bg-cyan-50 text-cyan-700 dark:border-cyan-700 dark:bg-cyan-950 dark:text-cyan-300',
    },
    registering: {
        label: 'Registering',
        variant: 'outline',
        className: 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300',
    },
    registered: {
        label: 'Registered',
        variant: 'default',
        className: 'bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-600',
    },
    error: {
        label: 'Error',
        variant: 'destructive',
    },
};

/**
 * Badge component for displaying IGSN upload status.
 *
 * @example
 * <IgsnStatusBadge status="pending" />
 * <IgsnStatusBadge status="registered" />
 * <IgsnStatusBadge status="error" />
 */
export function IgsnStatusBadge({ status, className }: IgsnStatusBadgeProps) {
    const normalizedStatus = status.toLowerCase() as UploadStatus;
    const config = statusConfig[normalizedStatus] ?? {
        label: status,
        variant: 'outline' as const,
    };

    return (
        <Badge variant={config.variant} className={cn(config.className, className)}>
            {config.label}
        </Badge>
    );
}
