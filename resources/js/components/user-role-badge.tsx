import { Badge } from '@/components/ui/badge';

interface UserRoleBadgeProps {
    role: string;
    label?: string;
}

/**
 * Role to badge variant mapping
 * 
 * IMPORTANT: This mapping must be kept in sync with app/Enums/UserRole.php badgeVariant() method
 * Any changes to role variants must be reflected in both files
 */
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
