import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col items-center justify-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <header className="mb-6 w-full max-w-4xl">
                    <nav className="flex items-center justify-end gap-4">
                        {auth.user ? (
                            <Link href={dashboard()} className="rounded-sm border px-5 py-1.5">
                                Dashboard
                            </Link>
                        ) : (
                            <Link href={login()} className="rounded-sm border px-5 py-1.5">
                                Log in
                            </Link>
                        )}
                    </nav>
                </header>
                <main className="text-center">
                    <h1 className="mb-4 text-2xl font-semibold">
                        ERNIE - Earth Research Notary for Information & Editing
                    </h1>
                    <p>
                        A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.
                    </p>
                </main>
            </div>
        </>
    );
}
