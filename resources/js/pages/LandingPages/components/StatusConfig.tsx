import { CheckCircle, Eye, FileEdit, type LucideIcon } from 'lucide-react';

interface StatusConfig {
    icon: LucideIcon;
    color: string;
    textColor: string;
    label: string;
}

const STATUS_CONFIGS: Record<string, StatusConfig> = {
    published: {
        icon: CheckCircle,
        color: 'text-green-600',
        textColor: 'text-green-700',
        label: 'Published',
    },
    draft: {
        icon: FileEdit,
        color: 'text-amber-500',
        textColor: 'text-amber-700',
        label: 'Draft',
    },
    // Review Preview (not a database status, but for preview mode)
    preview: {
        icon: Eye,
        color: 'text-blue-500',
        textColor: 'text-blue-700',
        label: 'Review Preview',
    },
};

export function getStatusConfig(status: string): StatusConfig {
    const normalizedStatus = status.toLowerCase();
    return (
        STATUS_CONFIGS[normalizedStatus] || {
            icon: Eye,
            color: 'text-gray-500',
            textColor: 'text-gray-700',
            label: status,
        }
    );
}
