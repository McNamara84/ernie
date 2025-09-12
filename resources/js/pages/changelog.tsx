import PublicLayout from '@/layouts/public-layout';
import { Head } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState } from 'react';

type Entry = {
    date: string;
    type: 'feature' | 'fix' | 'improvement';
    title: string;
    description: string;
};

const typeColors: Record<Entry['type'], string> = {
    feature: 'bg-green-500',
    fix: 'bg-red-500',
    improvement: 'bg-blue-500',
};

export default function Changelog() {
    const [entries, setEntries] = useState<Entry[]>([]);
    const [active, setActive] = useState<number | null>(null);

    useEffect(() => {
        fetch('/api/changelog')
            .then((res) => res.json())
            .then((data) => setEntries(data));
    }, []);

    return (
        <PublicLayout>
            <Head title="Changelog" />
            <h1 className="mb-6 text-2xl font-semibold">Changelog</h1>
            <ul className="space-y-4" aria-label="Changelog Timeline">
                {entries.map((entry, index) => {
                    const isOpen = active === index;
                    return (
                        <li key={entry.title} className="relative">
                            <motion.button
                                whileHover={{ scale: 1.01 }}
                                onClick={() => setActive(isOpen ? null : index)}
                                aria-expanded={isOpen}
                                className="flex w-full items-center rounded p-2 text-left focus:outline-none focus:ring"
                            >
                                <span
                                    className={`mr-2 h-3 w-3 rounded-full ${typeColors[entry.type]}`}
                                    aria-hidden="true"
                                />
                                <span className="flex-1 font-medium">{entry.title}</span>
                                <span className="ml-4 text-sm text-gray-600">{entry.date}</span>
                            </motion.button>
                            <AnimatePresence initial={false}>
                                {isOpen && (
                                    <motion.div
                                        id={`entry-${index}`}
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        transition={{ duration: 0.3 }}
                                        className="ml-5 mt-2 border-l pl-4 text-sm text-gray-700"
                                    >
                                        {entry.description}
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </li>
                    );
                })}
            </ul>
        </PublicLayout>
    );
}
