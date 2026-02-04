import { Form, Head } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useRef } from 'react';

import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import HeadingSmall from '@/components/heading-small';
import { FormError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/password';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: edit().url,
    },
];

export default function Password() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Password settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Update password" description="Ensure your account is using a long, random password to stay secure" />

                    <Form
                        {...PasswordController.update.put()}
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnError={['password', 'password_confirmation', 'current_password']}
                        resetOnSuccess
                        onError={(errors) => {
                            if (errors.password) {
                                passwordInput.current?.focus();
                            }

                            if (errors.current_password) {
                                currentPasswordInput.current?.focus();
                            }
                        }}
                        className="space-y-6"
                    >
                        {({ errors, processing, recentlySuccessful }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">Current password</Label>

                                    <Input
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        type="password"
                                        className="mt-1 block w-full"
                                        autoComplete="current-password"
                                        placeholder="Current password"
                                    />

                                    <FormError message={errors.current_password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">New password</Label>

                                    <Input
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        type="password"
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        placeholder="New password"
                                    />

                                    <FormError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">Confirm password</Label>

                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        placeholder="Confirm password"
                                    />

                                    <FormError message={errors.password_confirmation} />
                                </div>

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
                            </>
                        )}
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
