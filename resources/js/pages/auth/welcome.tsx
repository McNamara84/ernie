import { Form, Head } from '@inertiajs/react';

import WelcomeController from '@/actions/App/Http/Controllers/Auth/WelcomeController';
import { FormError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

interface WelcomeProps {
    email: string;
    userId: number;
}

export default function Welcome({ email, userId }: WelcomeProps) {
    return (
        <AuthLayout title="Welcome to ERNIE" description="Set your password to activate your account">
            <Head title="Welcome - Set Your Password" />

            <Form {...WelcomeController.store.post({ user: userId })} resetOnSuccess={['password', 'password_confirmation']}>
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full bg-muted"
                                readOnly
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder="Enter your new password"
                            />
                            <FormError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm Password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder="Confirm your password"
                            />
                            <FormError message={errors.password_confirmation} />
                        </div>

                        <Button type="submit" className="mt-4 w-full" disabled={processing}>
                            {processing && <Spinner size="sm" className="mr-2" />}
                            Set Password & Continue
                        </Button>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
