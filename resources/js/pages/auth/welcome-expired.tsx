import { Form, Head } from '@inertiajs/react';
import { AlertCircle, Mail } from 'lucide-react';

import WelcomeController from '@/actions/App/Http/Controllers/Auth/WelcomeController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

interface WelcomeExpiredProps {
    email: string;
}

export default function WelcomeExpired({ email }: WelcomeExpiredProps) {
    return (
        <AuthLayout title="Link Expired" description="Your welcome link has expired">
            <Head title="Welcome Link Expired" />

            <Alert variant="destructive" className="mb-6">
                <AlertCircle className="h-4 w-4" />
                <AlertTitle>Link Expired</AlertTitle>
                <AlertDescription>Your welcome link has expired. Don't worry – you can request a new one below.</AlertDescription>
            </Alert>

            <Form {...WelcomeController.resend.post()}>
                {({ processing }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                defaultValue={email}
                                placeholder="Enter your email address"
                                className={email ? 'bg-muted' : ''}
                                readOnly={!!email}
                                required
                            />
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? <Spinner size="sm" className="mr-2" /> : <Mail className="mr-2 h-4 w-4" />}
                            Send New Welcome Email
                        </Button>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
