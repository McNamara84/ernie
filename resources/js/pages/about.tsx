import PublicLayout from '@/layouts/public-layout';
import { Head } from '@inertiajs/react';

export default function About() {
    return (
        <PublicLayout>
            <Head title="About" />
            <h1 className="mb-4 text-2xl font-semibold">About ERNIE</h1>
            <p className="mb-4">
                ERNIE (Earth Research Notary for Information &amp; Editing) is a metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.
            </p>
            <p className="mb-4">This project is currently under active development; features and interfaces may change.</p>
            <p className="mb-4">
                The source code is available on{' '}
                <a href="https://github.com/McNamara84/ernie" target="_blank" rel="noreferrer" className="text-primary underline">
                    GitHub
                </a>
                .
            </p>
            <p>
                For inquiries, contact{' '}
                <a href="mailto:ehrmann@gfz.de" className="text-primary underline">
                    ehrmann@gfz.de
                </a>
                .
            </p>
        </PublicLayout>
    );
}
