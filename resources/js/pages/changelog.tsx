import PublicLayout from '@/layouts/public-layout';
import { withBasePath } from '@/lib/base-path';
import { Head } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState } from 'react';

type Change = {
    title: string;
    description: string;
};

type Release = {
    version: string;
    date: string;
    features?: Change[];
    improvements?: Change[];
    fixes?: Change[];
};

const sectionConfig: Record<keyof Omit<Release, 'version' | 'date'>, { label: string; color: string }> = {
    features: { label: 'Features', color: 'text-green-600' },
    improvements: { label: 'Improvements', color: 'text-blue-600' },
    fixes: { label: 'Fixes', color: 'text-red-600' },
};

export default function Changelog() {
    const [releases, setReleases] = useState<Release[]>([]);
    const [active, setActive] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetch(withBasePath('/api/changelog'))
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then((data: Release[]) => setReleases(data))
            .catch(() => setError('Unable to load changelog.'));
    }, []);

    return (
        <PublicLayout>
            <Head title="Changelog" />
            <h1 className="mb-6 text-2xl font-semibold">Changelog</h1>
            {error ? (
                <p role="alert" className="text-red-600">
                    {error}
                </p>
            ) : (
                <ul
                    className="relative space-y-4 border-l border-gray-300 pl-6"
                    aria-label="Changelog Timeline"
                >
                    {releases.map((release, index) => {
                        const isOpen = active === index;
                        const prev = releases[index - 1];
                        const [currMajor, currMinor] = release.version.split('.').map(Number);
                    const [prevMajor, prevMinor] = prev
                        ? prev.version.split('.').map(Number)
                        : [currMajor, currMinor];
                    const ringColor = (() => {
                        if (!prev) return 'ring-green-500';
                        if (currMajor !== prevMajor) return 'ring-green-500';
                        if (currMinor !== prevMinor) return 'ring-blue-500';
                        return 'ring-red-500';
                    })();
                    return (
                        <li key={release.version} className="relative">
                            <span
                                aria-hidden="true"
                                data-testid="version-anchor"
                                className={`absolute -left-3 top-3 h-3 w-3 rounded-full bg-white ring-2 ${ringColor}`}
                            ></span>
                            <motion.button
                                whileHover={{ scale: 1.01 }}
                                onClick={() => setActive(isOpen ? null : index)}
                                aria-expanded={isOpen}
                                className="flex w-full items-center rounded p-2 text-left focus:outline-none focus:ring"
                            >
                                <span className="flex-1 font-medium">Version {release.version}</span>
                                <span className="ml-4 text-sm text-gray-600">{release.date}</span>
                            </motion.button>
                            <AnimatePresence initial={false}>
                                {isOpen && (
                                    <motion.div
                                        id={`release-${index}`}
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        transition={{ duration: 0.3 }}
                                        className="ml-5 mt-2 border-l pl-4 text-sm text-gray-700"
                                    >
                                        {(
                                            Object.keys(sectionConfig) as Array<keyof typeof sectionConfig>
                                        ).map((key) => {
                                            const items = release[key];
                                            if (!items || items.length === 0) return null;
                                            return (
                                                <section key={key} className="mb-4 last:mb-0">
                                                    <h3
                                                        className={`mb-1 font-semibold ${sectionConfig[key].color}`}
                                                    >
                                                        {sectionConfig[key].label}
                                                    </h3>
                                                    <ul className="space-y-1">
                                                        {items.map((item) => (
                                                            <li key={item.title}>
                                                                <p className="font-medium">{item.title}</p>
                                                                <p>{item.description}</p>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </section>
                                            );
                                        })}
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </li>
                    );
                })}
                </ul>
            )}
        </PublicLayout>
    );
}
