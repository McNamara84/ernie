import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import NewPasswordController from '@/actions/App/Http/Controllers/Auth/NewPasswordController';
import { Button } from '@/components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { type ResetPasswordInput, resetPasswordSchema } from '@/lib/validations/user';

interface ResetPasswordProps {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<ResetPasswordInput>({
        resolver: zodResolver(resetPasswordSchema),
        defaultValues: {
            email,
            password: '',
            password_confirmation: '',
        },
    });

    const onSubmit = (data: ResetPasswordInput) => {
        setProcessing(true);
        router.post(NewPasswordController.store.url(), { ...data, token }, {
            onError: (errors) => {
                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof ResetPasswordInput, { message });
                });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthLayout title="Reset password" description="Please enter your new password below">
            <Head title="Reset password" />

            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="grid gap-6">
                    <FormField
                        control={form.control}
                        name="email"
                        render={({ field }) => (
                            <FormItem className="grid gap-2">
                                <FormLabel>Email</FormLabel>
                                <FormControl>
                                    <Input {...field} type="email" autoComplete="email" className="mt-1 block w-full" readOnly />
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
                                <FormLabel>Password</FormLabel>
                                <FormControl>
                                    <Input {...field} type="password" autoComplete="new-password" className="mt-1 block w-full" autoFocus placeholder="Password" />
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
                                    <Input {...field} type="password" autoComplete="new-password" className="mt-1 block w-full" placeholder="Confirm password" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />

                    <Button type="submit" className="mt-4 w-full" disabled={processing}>
                        {processing && <Spinner size="sm" />}
                        Reset password
                    </Button>
                </form>
            </Form>
        </AuthLayout>
    );
}
