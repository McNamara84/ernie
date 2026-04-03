import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import AuthenticatedSessionController from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { type LoginInput, loginSchema } from '@/lib/validations/user';
import { request } from '@/routes/password';

interface LoginProps {
    status?: string | null;
    error?: string | null;
    canResetPassword: boolean;
}

export default function Login({ status, error, canResetPassword }: LoginProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<LoginInput>({
        resolver: zodResolver(loginSchema),
        defaultValues: {
            email: '',
            password: '',
            remember: false,
        },
    });

    const onSubmit = (data: LoginInput) => {
        setProcessing(true);
        router.post(AuthenticatedSessionController.store.url(), data, {
            onError: (errors) => {
                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof LoginInput, { message });
                });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthLayout title="Log in to your account" description="Enter your email and password below to log in">
            <Head title="Log in" />

            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
                    <div className="grid gap-6">
                        <FormField
                            control={form.control}
                            name="email"
                            render={({ field }) => (
                                <FormItem className="grid gap-2">
                                    <FormLabel>Email address</FormLabel>
                                    <FormControl>
                                        <Input {...field} type="email" autoFocus tabIndex={1} autoComplete="email" placeholder="email@gfz.de" />
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
                                    <div className="flex items-center">
                                        <FormLabel>Password</FormLabel>
                                        {canResetPassword && (
                                            <TextLink href={request()} className="ml-auto text-sm" tabIndex={5}>
                                                Forgot password?
                                            </TextLink>
                                        )}
                                    </div>
                                    <FormControl>
                                        <PasswordInput {...field} tabIndex={2} autoComplete="current-password" placeholder="Password" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        <FormField
                            control={form.control}
                            name="remember"
                            render={({ field }) => (
                                <FormItem className="flex items-center space-x-3">
                                    <FormControl>
                                        <Checkbox checked={field.value} onCheckedChange={field.onChange} tabIndex={3} />
                                    </FormControl>
                                    <FormLabel className="!mt-0">Remember me</FormLabel>
                                </FormItem>
                            )}
                        />

                        <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={processing}>
                            {processing && <Spinner size="sm" data-testid="loading-spinner" />}
                            Log in
                        </Button>
                    </div>
                </form>
            </Form>

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}
            {error && <div className="mb-4 text-center text-sm font-medium text-destructive">{error}</div>}
        </AuthLayout>
    );
}
