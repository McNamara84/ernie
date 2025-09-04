import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome" />
            <header className="fixed top-0 right-0 left-0 z-50 border-b border-sidebar-border/80 bg-background">
                <nav className="mx-auto flex h-16 w-full max-w-4xl items-center justify-end gap-4 px-6">
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
            <div className="flex min-h-screen flex-col items-center justify-center bg-[#FDFDFC] px-6 pt-16 pb-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <main className="text-center">
                    <h1 className="mb-4 text-2xl font-semibold">ERNIE - Earth Research Notary for Information & Editing</h1>
                    <p>A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.</p>
                </main>
            </div>
        </>
    );
}
