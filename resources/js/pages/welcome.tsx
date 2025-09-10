import PublicLayout from '@/layouts/public-layout';
import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <PublicLayout>
            <Head title="Welcome" />
            <div className="flex flex-1 flex-col items-center justify-center text-center">
                <h1 className="mb-4 text-2xl font-semibold">
                    ERNIE - Earth Research Notary for Information & Editing
                </h1>
                <p>A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.</p>
            </div>
        </PublicLayout>
    );
}
