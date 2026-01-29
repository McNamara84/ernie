import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { type CreateUserInput, createUserSchema } from '@/lib/validations/user';

interface AddUserDialogProps {
    disabled?: boolean;
}

export function AddUserDialog({ disabled }: AddUserDialogProps) {
    const [open, setOpen] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const form = useForm<CreateUserInput>({
        resolver: zodResolver(createUserSchema),
        defaultValues: {
            name: '',
            email: '',
        },
    });

    const handleSubmit = (data: CreateUserInput) => {
        setIsSubmitting(true);
        router.post('/users', data, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { success?: string; warning?: string } | undefined;
                if (flash?.warning) {
                    toast.warning(flash.warning);
                } else if (flash?.success) {
                    toast.success(flash.success);
                }
                setOpen(false);
                form.reset();
                setIsSubmitting(false);
            },
            onError: (errors) => {
                // Map server errors to form fields
                Object.entries(errors).forEach(([field, message]) => {
                    if (field === 'name' || field === 'email') {
                        form.setError(field, { message: message as string });
                    }
                });
                setIsSubmitting(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(isOpen) => {
                setOpen(isOpen);
                if (!isOpen) form.reset();
            }}
        >
            <DialogTrigger asChild>
                <Button disabled={disabled}>
                    <UserPlus className="mr-2 h-4 w-4" />
                    Add User
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[425px]">
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(handleSubmit)}>
                        <DialogHeader>
                            <DialogTitle>Add New User</DialogTitle>
                            <DialogDescription>
                                Create a new user account. They will receive an email with a link to set their password. New users are automatically
                                assigned the 'Beginner' role.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Name</FormLabel>
                                        <FormControl>
                                            <Input placeholder="John Doe" disabled={isSubmitting} {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="email"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Email</FormLabel>
                                        <FormControl>
                                            <Input type="email" placeholder="john.doe@example.com" disabled={isSubmitting} {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="outline" disabled={isSubmitting}>
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting && <Spinner size="sm" className="mr-2" />}
                                {isSubmitting ? 'Creating...' : 'Create User'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
