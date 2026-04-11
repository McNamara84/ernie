import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import WelcomeController from '@/actions/App/Http/Controllers/Auth/WelcomeController';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoadingButton } from '@/components/ui/loading-button';
import AuthLayout from '@/layouts/auth-layout';
import { type WelcomePasswordInput, welcomePasswordSchema } from '@/lib/validations/user';

interface WelcomeProps {
    email: string;
    userId: number;
    signatureParams: {
        expires: string;
        signature: string;
    };
}

export default function Welcome({ email, userId, signatureParams }: WelcomeProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<WelcomePasswordInput>({
        resolver: zodResolver(welcomePasswordSchema),
        defaultValues: {
            password: '',
            password_confirmation: '',
        },
    });

    const onSubmit = (data: WelcomePasswordInput) => {
        setProcessing(true);
        router.post(WelcomeController.store.url({ user: userId }, { query: signatureParams }), data, {
            onError: (errors) => {
                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof WelcomePasswordInput, { message });
                });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthLayout title="Welcome to ERNIE" description="Set your password to activate your account">
            <Head title="Welcome - Set Your Password" />

            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" autoComplete="email" value={email} className="mt-1 block w-full bg-muted" readOnly />
                    </div>

                    <FormField
                        control={form.control}
                        name="password"
                        render={({ field }) => (
                            <FormItem className="grid gap-2">
                                <FormLabel>Password</FormLabel>
                                <FormControl>
                                    <Input {...field} type="password" autoComplete="new-password" className="mt-1 block w-full" autoFocus placeholder="Enter your new password" />
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
                                <FormLabel>Confirm Password</FormLabel>
                                <FormControl>
                                    <Input {...field} type="password" autoComplete="new-password" className="mt-1 block w-full" placeholder="Confirm your password" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />

                    <LoadingButton type="submit" className="mt-4 w-full" loading={processing}>
                        Set Password & Continue
                    </LoadingButton>
                </form>
            </Form>
        </AuthLayout>
    );
}
