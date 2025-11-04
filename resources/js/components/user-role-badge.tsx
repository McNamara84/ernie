import { Badge } from '@/components/ui/badge';

interface UserRoleBadgeProps {
    role: string;
    label?: string;
}

const roleVariants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    admin: 'destructive',
    group_leader: 'default',
    curator: 'secondary',
    beginner: 'outline',
};

export function UserRoleBadge({ role, label }: UserRoleBadgeProps) {
    const variant = roleVariants[role] || 'outline';
    const displayLabel = label || role;

    return <Badge variant={variant}>{displayLabel}</Badge>;
}
