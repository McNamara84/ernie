import { Link } from '@inertiajs/react';

export function DataCentresEmpty() {
    return (
        <div className="space-y-4 rounded-lg border p-6">
            <p>No data centres with published DOI resources are currently available.</p>
            <Link href="/doi-search" className="underline underline-offset-4">
                Explore the Data Portal
            </Link>
        </div>
    );
}
