import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';

import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type UpdatePasswordInput, updatePasswordSchema } from '@/lib/validations/user';
import { edit } from '@/routes/password';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: edit().url,
    },
];

export default function Password() {
    const [processing, setProcessing] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const form = useForm<UpdatePasswordInput>({
        resolver: zodResolver(updatePasswordSchema),
        defaultValues: {
            current_password: '',
            password: '',
            password_confirmation: '',
        },
    });

    const onSubmit = (data: UpdatePasswordInput) => {
        setProcessing(true);
        router.put(PasswordController.update.url(), data, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setRecentlySuccessful(true);
                setTimeout(() => setRecentlySuccessful(false), 2000);
            },
            onError: (errors) => {
                // Reset password fields on error
                form.setValue('password', '');
                form.setValue('password_confirmation', '');
                form.setValue('current_password', '');

                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof UpdatePasswordInput, { message });
                });

                if (errors.password) {
                    passwordInput.current?.focus();
                }
                if (errors.current_password) {
                    currentPasswordInput.current?.focus();
                }
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Password settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Update password" description="Ensure your account is using a long, random password to stay secure" />

                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
                            <FormField
                                control={form.control}
                                name="current_password"
                                render={({ field }) => (
                                    <FormItem className="grid gap-2">
                                        <FormLabel>Current password</FormLabel>
                                        <FormControl>
                                            <Input
                                                {...field}
                                                ref={(e) => {
                                                    field.ref(e);
                                                    (currentPasswordInput as React.MutableRefObject<HTMLInputElement | null>).current = e;
                                                }}
                                                type="password"
                                                className="mt-1 block w-full"
                                                autoComplete="current-password"
                                                placeholder="Current password"
                                            />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />

                            <FormField
                                control={form.control}
                                name="password"
                                render={({ field }) => (
                                    <FormItem className="grid gap-2">
                                        <FormLabel>New password</FormLabel>
                                        <FormControl>
                                            <Input
                                                {...field}
                                                ref={(e) => {
                                                    field.ref(e);
                                                    (passwordInput as React.MutableRefObject<HTMLInputElement | null>).current = e;
                                                }}
                                                type="password"
                                                className="mt-1 block w-full"
                                                autoComplete="new-password"
                                                placeholder="New password"
                                            />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />

                            <FormField
                                control={form.control}
                                name="password_confirmation"
                                render={({ field }) => (
                                    <FormItem className="grid gap-2">
                                        <FormLabel>Confirm password</FormLabel>
                                        <FormControl>
                                            <Input
                                                {...field}
                                                type="password"
                                                className="mt-1 block w-full"
                                                autoComplete="new-password"
                                                placeholder="Confirm password"
                                            />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save password</Button>

                                <AnimatePresence>
                                    {recentlySuccessful && (
                                        <motion.p
                                            initial={{ opacity: 0 }}
                                            animate={{ opacity: 1 }}
                                            exit={{ opacity: 0 }}
                                            transition={{ ease: 'easeInOut' }}
                                            className="text-sm text-neutral-600"
                                        >
                                            Saved
                                        </motion.p>
                                    )}
                                </AnimatePresence>
                            </div>
                        </form>
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
