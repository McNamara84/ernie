import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';

import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import HeadingSmall from '@/components/heading-small';
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
import { type DeleteAccountInput, deleteAccountSchema } from '@/lib/validations/user';

export default function DeleteUser() {
    const [processing, setProcessing] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const form = useForm<DeleteAccountInput>({
        resolver: zodResolver(deleteAccountSchema),
        defaultValues: {
            password: '',
        },
    });

    const onSubmit = (data: DeleteAccountInput) => {
        setProcessing(true);
        router.delete(ProfileController.destroy.url(), {
            data,
            preserveScroll: true,
            onError: (errors) => {
                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof DeleteAccountInput, { message });
                });
                passwordInput.current?.focus();
            },
            onSuccess: () => form.reset(),
            onFinish: () => setProcessing(false),
        });
    };

    const handleCancel = () => {
        form.reset();
        form.clearErrors();
    };

    return (
        <div className="space-y-6">
            <HeadingSmall title="Delete account" description="Delete your account and all of its resources" />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">Please proceed with caution, this cannot be undone.</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="destructive">Delete account</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Are you sure you want to delete your account?</DialogTitle>
                            <DialogDescription>
                                Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your
                                password to confirm you would like to permanently delete your account.
                            </DialogDescription>
                        </DialogHeader>

                        <Form {...form}>
                            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
                                <FormField
                                    control={form.control}
                                    name="password"
                                    render={({ field }) => (
                                        <FormItem className="grid gap-2">
                                            <FormLabel className="sr-only">Password</FormLabel>
                                            <FormControl>
                                                <Input
                                                    {...field}
                                                    ref={(e) => {
                                                        field.ref(e);
                                                        (passwordInput as React.MutableRefObject<HTMLInputElement | null>).current = e;
                                                    }}
                                                    type="password"
                                                    placeholder="Password"
                                                    autoComplete="current-password"
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary" onClick={handleCancel}>
                                            Cancel
                                        </Button>
                                    </DialogClose>

                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Delete account
                                    </Button>
                                </DialogFooter>
                            </form>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
