import { Head } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Bug, Sparkles, TrendingUp } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ChangelogTimelineNav } from '@/components/changelog-timeline-nav';
import { Badge } from '@/components/ui/badge';
import PublicLayout from '@/layouts/public-layout';
import { withBasePath } from '@/lib/base-path';

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

const sectionConfig: Record<
    keyof Omit<Release, 'version' | 'date'>,
    { label: string; color: string; icon: React.ComponentType<{ className?: string }> }
> = {
    features: { label: 'Features', color: 'text-green-600', icon: Sparkles },
    improvements: { label: 'Improvements', color: 'text-blue-600', icon: TrendingUp },
    fixes: { label: 'Fixes', color: 'text-red-600', icon: Bug },
};

export default function Changelog() {
    const [releases, setReleases] = useState<Release[]>([]);
    const [active, setActive] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [highlightedIndex, setHighlightedIndex] = useState<number | null>(null);
    const releaseRefs = useRef<(HTMLLIElement | null)[]>([]);

    const isNewRelease = (dateString: string) => {
        const releaseDate = new Date(dateString);
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
        return releaseDate >= thirtyDaysAgo;
    };

    useEffect(() => {
        fetch(withBasePath('/api/changelog'))
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then((data: Release[]) => {
                setReleases(data);
                releaseRefs.current = new Array(data.length).fill(null);
                
                // Check for hash in URL and scroll to version
                const hash = window.location.hash.replace('#', '');
                if (hash.startsWith('v')) {
                    const version = hash.substring(1);
                    const index = data.findIndex((r) => r.version === version);
                    if (index !== -1) {
                        setActive(index);
                        // Delay scroll to ensure refs are set
                        setTimeout(() => {
                            const element = releaseRefs.current[index];
                            if (element) {
                                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 100);
                    } else {
                        setActive(data.length > 0 ? 0 : null);
                    }
                } else {
                    setActive(data.length > 0 ? 0 : null);
                }
            })
            .catch(() => setError('Unable to load changelog.'));
    }, []);

    // Intersection Observer für scroll-basiertes Highlighting
    useEffect(() => {
        if (releases.length === 0) return;

        const observerOptions = {
            root: null,
            rootMargin: '-40% 0px -40% 0px', // Mittlerer Bereich des Viewports
            threshold: 0.5,
        };

        const observerCallback = (entries: IntersectionObserverEntry[]) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const index = releaseRefs.current.findIndex((ref) => ref === entry.target);
                    if (index !== -1) {
                        setHighlightedIndex(index);
                        setActive(index);
                    }
                }
            });
        };

        const observer = new IntersectionObserver(observerCallback, observerOptions);

        releaseRefs.current.forEach((ref) => {
            if (ref) observer.observe(ref);
        });

        return () => {
            observer.disconnect();
        };
    }, [releases]);

    const handleNavigate = useCallback(
        (index: number) => {
            const element = releaseRefs.current[index];
            if (element) {
                const version = releases[index]?.version;
                if (version) {
                    window.history.pushState(null, '', `#v${version}`);
                }
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setActive(index);
            }
        },
        [releases]
    );

    // Keyboard navigation
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if (releases.length === 0 || active === null) return;

            switch (event.key) {
                case 'ArrowDown':
                case 'j':
                    event.preventDefault();
                    if (active < releases.length - 1) {
                        handleNavigate(active + 1);
                    }
                    break;
                case 'ArrowUp':
                case 'k':
                    event.preventDefault();
                    if (active > 0) {
                        handleNavigate(active - 1);
                    }
                    break;
                case 'Home':
                    event.preventDefault();
                    handleNavigate(0);
                    break;
                case 'End':
                    event.preventDefault();
                    handleNavigate(releases.length - 1);
                    break;
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    setActive((prev) => (prev === active ? null : active));
                    break;
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [releases, active, handleNavigate]);

    return (
        <PublicLayout>
            <Head title="Changelog" />
            <ChangelogTimelineNav releases={releases} activeIndex={active} onNavigate={handleNavigate} />
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
                        const buttonId = `release-trigger-${index}`;
                        const panelId = `release-${index}`;
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
                        
                        const gradientBg = (() => {
                            if (!prev) return 'from-green-50 to-transparent dark:from-green-950/20';
                            if (currMajor !== prevMajor) return 'from-green-50 to-transparent dark:from-green-950/20';
                            if (currMinor !== prevMinor) return 'from-blue-50 to-transparent dark:from-blue-950/20';
                            return 'from-red-50 to-transparent dark:from-red-950/20';
                        })();
                        
                        return (
                        <motion.li 
                            key={release.version} 
                            className="relative"
                            ref={(el) => {
                                releaseRefs.current[index] = el;
                            }}
                            initial={{ opacity: 0, x: -20 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ 
                                duration: 0.4, 
                                delay: index * 0.1,
                                ease: 'easeOut'
                            }}
                        >
                            <motion.div
                                animate={{
                                    scale: highlightedIndex === index ? 1.02 : 1,
                                    opacity: highlightedIndex === null || highlightedIndex === index ? 1 : 0.6,
                                }}
                                transition={{ duration: 0.3, ease: 'easeInOut' }}
                                className={`rounded-lg bg-gradient-to-r p-1 ${gradientBg}`}
                            >
                            <span
                                aria-hidden="true"
                                data-testid="version-anchor"
                                className={`absolute -left-3 top-3 h-3 w-3 rounded-full bg-white ring-2 ${ringColor}`}
                            ></span>
                            <motion.button
                                whileHover={{ scale: 1.01, x: 4 }}
                                whileTap={{ scale: 0.98 }}
                                onClick={() => setActive(isOpen ? null : index)}
                                id={buttonId}
                                aria-expanded={isOpen}
                                aria-controls={panelId}
                                className="flex w-full items-center rounded p-2 text-left transition-colors hover:bg-gray-50 focus:outline-none focus:ring dark:hover:bg-gray-800"
                                type="button"
                            >
                                <span className="flex flex-1 items-center gap-2">
                                    <span className="font-medium">Version {release.version}</span>
                                    {isNewRelease(release.date) && (
                                        <Badge variant="secondary" className="text-xs">
                                            New
                                        </Badge>
                                    )}
                                </span>
                                <span className="ml-4 text-sm text-gray-600 dark:text-gray-400">{release.date}</span>
                            </motion.button>
                            <AnimatePresence initial={false}>
                                {isOpen && (
                                    <motion.div
                                        id={panelId}
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        transition={{ duration: 0.3 }}
                                        className="ml-5 mt-2 border-l pl-4 text-sm text-gray-700"
                                        role="region"
                                        aria-labelledby={buttonId}
                                    >
                                        {(
                                            Object.keys(sectionConfig) as Array<keyof typeof sectionConfig>
                                        ).map((key) => {
                                            const items = release[key];
                                            if (!items || items.length === 0) return null;
                                            const Icon = sectionConfig[key].icon;
                                            return (
                                                <section key={key} className="mb-4 last:mb-0">
                                                    <h3
                                                        className={`mb-1 flex items-center gap-1.5 font-semibold ${sectionConfig[key].color}`}
                                                    >
                                                        <Icon className="h-4 w-4" aria-hidden="true" />
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
                            </motion.div>
                        </motion.li>
                    );
                })}
                </ul>
            )}
        </PublicLayout>
    );
}
