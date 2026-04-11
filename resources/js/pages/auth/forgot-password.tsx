import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import PasswordResetLinkController from '@/actions/App/Http/Controllers/Auth/PasswordResetLinkController';
import TextLink from '@/components/text-link';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { LoadingButton } from '@/components/ui/loading-button';
import AuthLayout from '@/layouts/auth-layout';
import { type ForgotPasswordInput, forgotPasswordSchema } from '@/lib/validations/user';
import { login } from '@/routes';

export default function ForgotPassword({ status }: { status?: string }) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<ForgotPasswordInput>({
        resolver: zodResolver(forgotPasswordSchema),
        defaultValues: {
            email: '',
        },
    });

    const onSubmit = (data: ForgotPasswordInput) => {
        setProcessing(true);
        router.post(PasswordResetLinkController.store.url(), data, {
            onError: (errors) => {
                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof ForgotPasswordInput, { message });
                });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthLayout title="Forgot password" description="Enter your email to receive a password reset link">
            <Head title="Forgot password" />

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}

            <div className="space-y-6">
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)}>
                        <FormField
                            control={form.control}
                            name="email"
                            render={({ field }) => (
                                <FormItem className="grid gap-2">
                                    <FormLabel>Email address</FormLabel>
                                    <FormControl>
                                        <Input {...field} type="email" autoComplete="off" autoFocus placeholder="email@example.com" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        <div className="my-6 flex items-center justify-start">
                            <LoadingButton className="w-full" loading={processing}>
                                Email password reset link
                            </LoadingButton>
                        </div>
                    </form>
                </Form>

                <div className="space-x-1 text-center text-sm text-muted-foreground">
                    <span>Or, return to</span>
                    <TextLink href={login()}>log in</TextLink>
                </div>
            </div>
        </AuthLayout>
    );
}
