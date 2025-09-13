import PublicLayout from '@/layouts/public-layout';
import { Head, Link } from '@inertiajs/react';

export default function Welcome() {
    return (
        <PublicLayout>
            <Head title="Welcome" />
            <div className="flex flex-1 flex-col items-center justify-center text-center">
                <h1 className="mb-4 text-2xl font-semibold">
                    ERNIE - Earth Research Notary for Information & Editing
                </h1>
                <p>
                    A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.
                </p>
                <p className="mt-6">
                    <Link
                        href="/api/v1/doc"
                        className="rounded-sm border px-5 py-1.5 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        API Documentation
                    </Link>
                </p>
            </div>
        </PublicLayout>
    );
}
