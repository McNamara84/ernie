import { Head, router, usePage } from '@inertiajs/react';
import { KeyRound, Mail, ShieldCheck, ShieldOff, UserCog, Users as UsersIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { UserRoleBadge } from '@/components/user-role-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem,type User as AuthUser } from '@/types';

interface UserData {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    is_active: boolean;
    deactivated_at: string | null;
    deactivated_by: {
        id: number;
        name: string;
    } | null;
    created_at: string;
}

interface RoleOption {
    value: string;
    label: string;
}

interface UsersIndexProps {
    users: UserData[];
    available_roles: RoleOption[];
    can_promote_to_group_leader: boolean;
}

export default function Index({ users, available_roles, can_promote_to_group_leader }: UsersIndexProps) {
    const { auth } = usePage<{ auth: { user: AuthUser } }>().props;
    const [processingUserId, setProcessingUserId] = useState<number | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Users',
            href: '/users',
        },
    ];

    const handleRoleChange = (userId: number, newRole: string) => {
        if (processingUserId) return;

        setProcessingUserId(userId);

        router.patch(
            `/users/${userId}/role`,
            { role: newRole },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('User role updated successfully');
                },
                onError: (errors) => {
                    const errorMessage = Object.values(errors)[0] as string;
                    toast.error(errorMessage || 'Failed to update user role');
                },
                onFinish: () => {
                    setProcessingUserId(null);
                },
            },
        );
    };

    const handleDeactivate = (userId: number) => {
        if (processingUserId) return;

        setProcessingUserId(userId);

        router.post(
            `/users/${userId}/deactivate`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('User deactivated successfully');
                },
                onError: (errors) => {
                    const errorMessage = Object.values(errors)[0] as string;
                    toast.error(errorMessage || 'Failed to deactivate user');
                },
                onFinish: () => {
                    setProcessingUserId(null);
                },
            },
        );
    };

    const handleReactivate = (userId: number) => {
        if (processingUserId) return;

        setProcessingUserId(userId);

        router.post(
            `/users/${userId}/reactivate`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('User reactivated successfully');
                },
                onError: (errors) => {
                    const errorMessage = Object.values(errors)[0] as string;
                    toast.error(errorMessage || 'Failed to reactivate user');
                },
                onFinish: () => {
                    setProcessingUserId(null);
                },
            },
        );
    };

    const handleResetPassword = (userId: number) => {
        if (processingUserId) return;

        setProcessingUserId(userId);

        router.post(
            `/users/${userId}/reset-password`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Password reset link sent to user');
                },
                onError: (errors) => {
                    const errorMessage = Object.values(errors)[0] as string;
                    toast.error(errorMessage || 'Failed to send password reset link');
                },
                onFinish: () => {
                    setProcessingUserId(null);
                },
            },
        );
    };

    const getAvailableRolesForUser = (userId: number): RoleOption[] => {
        // User ID 1 cannot have their role changed
        if (userId === 1) {
            return available_roles.filter((role) => role.value === 'admin');
        }

        // Group leaders cannot promote to group_leader or admin
        if (!can_promote_to_group_leader) {
            return available_roles.filter((role) => !['admin', 'group_leader'].includes(role.value));
        }

        return available_roles;
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />

            <div className="container mx-auto space-y-6 py-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <UsersIcon className="h-5 w-5" />
                            User Management
                        </CardTitle>
                        <CardDescription>
                            Manage user roles, permissions, and account status. Only admins and group leaders can access this page.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {users.length === 0 ? (
                            <Alert>
                                <AlertDescription>No users found in the system.</AlertDescription>
                            </Alert>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[50px]">ID</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Email</TableHead>
                                            <TableHead>Role</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Registered</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {users.map((user) => {
                                            const isProcessing = processingUserId === user.id;
                                            const availableRoles = getAvailableRolesForUser(user.id);
                                            const canChangeRole = user.id !== auth.user.id && availableRoles.length > 1;

                                            return (
                                                <TableRow key={user.id}>
                                                    <TableCell className="font-mono text-sm">{user.id}</TableCell>
                                                    <TableCell className="font-medium">
                                                        {user.name}
                                                        {user.id === 1 && (
                                                            <Badge variant="outline" className="ml-2 text-xs">
                                                                System Admin
                                                            </Badge>
                                                        )}
                                                        {user.id === auth.user.id && (
                                                            <Badge variant="outline" className="ml-2 text-xs">
                                                                You
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">{user.email}</TableCell>
                                                    <TableCell>
                                                        {canChangeRole ? (
                                                            <Select
                                                                value={user.role}
                                                                onValueChange={(value) => handleRoleChange(user.id, value)}
                                                                disabled={isProcessing}
                                                            >
                                                                <SelectTrigger className="w-[140px]">
                                                                    <SelectValue>
                                                                        <UserRoleBadge role={user.role} label={user.role_label} />
                                                                    </SelectValue>
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {availableRoles.map((role) => (
                                                                        <SelectItem key={role.value} value={role.value}>
                                                                            {role.label}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <UserRoleBadge role={user.role} label={user.role_label} />
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {user.is_active ? (
                                                            <Badge variant="secondary" className="gap-1">
                                                                <ShieldCheck className="h-3 w-3" />
                                                                Active
                                                            </Badge>
                                                        ) : (
                                                            <div className="space-y-1">
                                                                <Badge variant="destructive" className="gap-1">
                                                                    <ShieldOff className="h-3 w-3" />
                                                                    Deactivated
                                                                </Badge>
                                                                {user.deactivated_by && (
                                                                    <p className="text-xs text-muted-foreground">by {user.deactivated_by.name}</p>
                                                                )}
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">{formatDate(user.created_at)}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            {user.id !== auth.user.id && user.id !== 1 && (
                                                                <>
                                                                    {user.is_active ? (
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            onClick={() => handleDeactivate(user.id)}
                                                                            disabled={isProcessing}
                                                                        >
                                                                            <ShieldOff className="mr-1 h-4 w-4" />
                                                                            Deactivate
                                                                        </Button>
                                                                    ) : (
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            onClick={() => handleReactivate(user.id)}
                                                                            disabled={isProcessing}
                                                                        >
                                                                            <ShieldCheck className="mr-1 h-4 w-4" />
                                                                            Reactivate
                                                                        </Button>
                                                                    )}
                                                                </>
                                                            )}
                                                            {user.id !== 1 && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => handleResetPassword(user.id)}
                                                                    disabled={isProcessing}
                                                                    title="Send password reset email"
                                                                >
                                                                    <KeyRound className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        <div className="mt-6 space-y-2 text-sm text-muted-foreground">
                            <p className="flex items-center gap-2">
                                <UserCog className="h-4 w-4" />
                                <strong>Role Hierarchy:</strong> Admin &gt; Group Leader &gt; Curator &gt; Beginner
                            </p>
                            <p className="flex items-center gap-2">
                                <Mail className="h-4 w-4" />
                                <strong>Password Reset:</strong> Clicking the key icon sends a password reset link to the user's email.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
