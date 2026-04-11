// Components
import { Form, Head } from '@inertiajs/react';

import EmailVerificationNotificationController from '@/actions/App/Http/Controllers/Auth/EmailVerificationNotificationController';
import TextLink from '@/components/text-link';
import { LoadingButton } from '@/components/ui/loading-button';
import AuthLayout from '@/layouts/auth-layout';
import { logout } from '@/routes';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <AuthLayout title="Verify email" description="Please verify your email address by clicking on the link we just emailed to you.">
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    A new verification link has been sent to the email address you provided during registration.
                </div>
            )}

            <Form {...EmailVerificationNotificationController.store.post()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <LoadingButton loading={processing} variant="secondary">
                            Resend verification email
                        </LoadingButton>

                        <TextLink href={logout()} className="mx-auto block text-sm">
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
