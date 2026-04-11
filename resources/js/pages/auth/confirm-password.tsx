import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import ConfirmablePasswordController from '@/actions/App/Http/Controllers/Auth/ConfirmablePasswordController';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { LoadingButton } from '@/components/ui/loading-button';
import AuthLayout from '@/layouts/auth-layout';
import { type ConfirmPasswordInput, confirmPasswordSchema } from '@/lib/validations/user';

export default function ConfirmPassword() {
    const [processing, setProcessing] = useState(false);
    const form = useForm<ConfirmPasswordInput>({
        resolver: zodResolver(confirmPasswordSchema),
        defaultValues: {
            password: '',
        },
    });

    const onSubmit = (data: ConfirmPasswordInput) => {
        setProcessing(true);
        router.post(ConfirmablePasswordController.store.url(), data, {
            onError: (errors) => {
                Object.entries(errors).forEach(([key, message]) => {
                    form.setError(key as keyof ConfirmPasswordInput, { message });
                });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthLayout
            title="Confirm your password"
            description="This is a secure area of the application. Please confirm your password before continuing."
        >
            <Head title="Confirm password" />

            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
                    <FormField
                        control={form.control}
                        name="password"
                        render={({ field }) => (
                            <FormItem className="grid gap-2">
                                <FormLabel>Password</FormLabel>
                                <FormControl>
                                    <Input {...field} type="password" placeholder="Password" autoComplete="current-password" autoFocus />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />

                    <div className="flex items-center">
                        <LoadingButton className="w-full" loading={processing}>
                            Confirm password
                        </LoadingButton>
                    </div>
                </form>
            </Form>
        </AuthLayout>
    );
}
