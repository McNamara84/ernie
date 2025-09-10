import { Link } from '@inertiajs/react';
import { about, legalNotice } from '@/routes';

export function AppFooter() {
    return (
        <footer className="border-t py-4 text-sm text-neutral-600 dark:text-neutral-300">
            <div className="mx-auto flex w-full max-w-7xl flex-col items-center justify-between gap-2 px-4 md:flex-row">
                <span>ERNIE v0.1.0</span>
                <div className="flex gap-4">
                    <Link href={about()} className="hover:underline">
                        About
                    </Link>
                    <Link href={legalNotice()} className="hover:underline">
                        Legal Notice
                    </Link>
                </div>
            </div>
        </footer>
    );
}

export default AppFooter;
