import { Head } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Bug, Sparkles, TrendingUp } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ChangelogTimelineNav } from '@/components/changelog-timeline-nav';
import { Button } from '@/components/ui/button';
import ChangelogLayout from '@/layouts/changelog-layout';

// Type declaration for test helpers exposed on window object
declare global {
    interface Window {
        __enableChangelogTestHelpers?: boolean;
        __testHelper_updateActiveRelease?: () => void;
        __testHelper_expandRelease?: (index: number) => void;
    }
}

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
    features: { label: 'Features', color: 'text-green-700', icon: Sparkles },
    improvements: { label: 'Improvements', color: 'text-blue-700', icon: TrendingUp },
    fixes: { label: 'Fixes', color: 'text-red-700', icon: Bug },
};

export const browserNavigation = {
    reload: () => window.location.reload(),
};

export default function Changelog() {
    const [releases, setReleases] = useState<Release[]>([]);
    const [openIndex, setOpenIndex] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [highlightedIndex, setHighlightedIndex] = useState<number | null>(null);
    const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const releaseRefs = useRef<(HTMLLIElement | null)[]>([]);
    const pendingScrollRef = useRef<{ index: number; behavior: ScrollBehavior } | null>(null);

    const getScrollBehavior = useCallback(
        (): ScrollBehavior => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'),
        [],
    );

    const shouldIgnoreGlobalShortcut = useCallback((event: KeyboardEvent) => {
        if (event.defaultPrevented) {
            return true;
        }

        const target = event.target;

        if (!(target instanceof HTMLElement)) {
            return false;
        }

        if (target.isContentEditable) {
            return true;
        }

        return target.closest('button, a[href], input, textarea, select, summary, [contenteditable="true"], [role="button"], [role="link"]') !== null;
    }, []);

    // Check for reduced motion preference
    useEffect(() => {
        const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        setPrefersReducedMotion(mediaQuery.matches);

        const handleChange = (e: MediaQueryListEvent) => {
            setPrefersReducedMotion(e.matches);
        };

        mediaQuery.addEventListener('change', handleChange);
        return () => mediaQuery.removeEventListener('change', handleChange);
    }, []);

    const isNewRelease = (dateString: string, index: number) => {
        // Only show "New" badge for the first 3 releases
        if (index > 2) return false;

        // Only if less than 14 days old
        const releaseDate = new Date(dateString);
        const fourteenDaysAgo = new Date();
        fourteenDaysAgo.setDate(fourteenDaysAgo.getDate() - 14);
        return releaseDate >= fourteenDaysAgo;
    };

    const getReleaseIndexFromHash = useCallback((hash: string, sourceReleases: Release[]) => {
        const normalizedHash = hash.replace('#', '');

        if (!normalizedHash.startsWith('v')) {
            return null;
        }

        const version = normalizedHash.substring(1);
        const index = sourceReleases.findIndex((release) => release.version === version);

        return index === -1 ? null : index;
    }, []);

    // Fetch changelog data on mount
    useEffect(() => {
        fetch('/api/changelog')
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Failed to fetch changelog');
                }
                return res.json();
            })
            .then((data: Release[]) => {
                setReleases(data);
                releaseRefs.current = new Array(data.length).fill(null);

                const hashIndex = getReleaseIndexFromHash(window.location.hash, data);

                if (hashIndex !== null) {
                    setOpenIndex(hashIndex);
                    setHighlightedIndex(hashIndex);
                    pendingScrollRef.current = {
                        index: hashIndex,
                        behavior: getScrollBehavior(),
                    };
                } else {
                    const defaultIndex = data.length > 0 ? 0 : null;

                    setOpenIndex(defaultIndex);
                    setHighlightedIndex(defaultIndex);
                }
            })
            .catch(() => setError('Unable to load changelog.'));
    }, [getReleaseIndexFromHash, getScrollBehavior]);

    useEffect(() => {
        const pendingScroll = pendingScrollRef.current;

        if (!pendingScroll) {
            return;
        }

        const element = releaseRefs.current[pendingScroll.index];

        if (!element) {
            pendingScrollRef.current = null;
            return;
        }

        element.scrollIntoView({ behavior: pendingScroll.behavior, block: 'center' });
        pendingScrollRef.current = null;
    }, [releases, openIndex]);

    const navigateToRelease = useCallback(
        (
            index: number,
            options: {
                updateHash?: boolean;
                scrollBehavior?: ScrollBehavior;
            } = {},
        ) => {
            const targetRelease = releases[index];

            if (!targetRelease) {
                return;
            }

            const nextHash = `#v${targetRelease.version}`;

            if (options.updateHash !== false && window.location.hash !== nextHash) {
                window.history.pushState(null, '', nextHash);
            }

            setOpenIndex(index);
            setHighlightedIndex(index);
            pendingScrollRef.current = {
                index,
                behavior: options.scrollBehavior ?? getScrollBehavior(),
            };
        },
        [getScrollBehavior, releases],
    );

    // Handle hash changes (for browser navigation and timeline clicks)
    useEffect(() => {
        if (releases.length === 0) return;

        const processHash = () => {
            const index = getReleaseIndexFromHash(window.location.hash, releases);

            if (index !== null) {
                navigateToRelease(index, { updateHash: false });
            }
        };

        // Listen for hash changes (browser back/forward, timeline clicks)
        window.addEventListener('hashchange', processHash);

        return () => {
            window.removeEventListener('hashchange', processHash);
        };
    }, [getReleaseIndexFromHash, navigateToRelease, releases]);

    // Helper function to find most visible release (extracted for reuse)
    const findMostVisibleRelease = useCallback(() => {
        let bestIndex = -1;
        let bestRatio = 0;

        const viewportHeight = window.innerHeight;
        const centerViewport = viewportHeight * 0.3; // Wider viewport window
        const centerViewportBottom = viewportHeight * 0.7;

        releaseRefs.current.forEach((ref, index) => {
            if (!ref) return;
            const rect = ref.getBoundingClientRect();

            // Element is in viewport center zone
            if (rect.top < centerViewportBottom && rect.bottom > centerViewport) {
                const visibleHeight = Math.min(rect.bottom, centerViewportBottom) - Math.max(rect.top, centerViewport);
                const ratio = visibleHeight / (centerViewportBottom - centerViewport);

                if (ratio > bestRatio) {
                    bestRatio = ratio;
                    bestIndex = index;
                }
            }
        });

        return bestIndex;
    }, []);

    // Update active release based on visibility (extracted for reuse)
    const updateActiveRelease = useCallback(() => {
        const mostVisibleIndex = findMostVisibleRelease();

        if (mostVisibleIndex !== -1) {
            setHighlightedIndex(mostVisibleIndex);
        }
    }, [findMostVisibleRelease]);

    // Test helpers are only exposed in explicit test contexts.
    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const shouldExposeTestHelpers = import.meta.env.MODE === 'test' || window.__enableChangelogTestHelpers === true;

        if (!shouldExposeTestHelpers) {
            delete window.__testHelper_updateActiveRelease;
            delete window.__testHelper_expandRelease;
            return;
        }

        window.__testHelper_updateActiveRelease = updateActiveRelease;
        window.__testHelper_expandRelease = (index: number) => {
            navigateToRelease(index);
        };

        return () => {
            delete window.__testHelper_updateActiveRelease;
            delete window.__testHelper_expandRelease;
        };
    }, [navigateToRelease, updateActiveRelease]);

    // Intersection Observer for scroll-based highlighting
    useEffect(() => {
        if (releases.length === 0) return;

        const observerOptions = {
            root: null,
            rootMargin: '-30% 0px -30% 0px', // Wider margin for better detection
            threshold: [0, 0.1, 0.25, 0.5, 0.75, 1.0],
        };

        let intersectionTimeout: ReturnType<typeof setTimeout>;

        const observerCallback = () => {
            // Debounce intersection updates to avoid rapid state changes during smooth scrolling
            clearTimeout(intersectionTimeout);
            intersectionTimeout = setTimeout(() => {
                updateActiveRelease();
            }, 100);
        };

        const observer = new IntersectionObserver(observerCallback, observerOptions);

        releaseRefs.current.forEach((ref) => {
            if (ref) observer.observe(ref);
        });

        // Additional scroll listener for better reliability
        let scrollTimeout: ReturnType<typeof setTimeout>;
        const handleScroll = () => {
            window.clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(updateActiveRelease, 50);
        };

        window.addEventListener('scroll', handleScroll, { passive: true });

        return () => {
            observer.disconnect();
            window.removeEventListener('scroll', handleScroll);
            window.clearTimeout(scrollTimeout);
            clearTimeout(intersectionTimeout); // Cleanup intersection debounce too
        };
    }, [releases, updateActiveRelease]);

    const handleNavigate = useCallback(
        (index: number) => {
            navigateToRelease(index);
        },
        [navigateToRelease],
    );

    // Keyboard navigation
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if (releases.length === 0 || shouldIgnoreGlobalShortcut(event)) return;

            const currentIndex = highlightedIndex ?? openIndex ?? 0;

            switch (event.key) {
                case 'ArrowDown':
                case 'j':
                    event.preventDefault();
                    if (currentIndex < releases.length - 1) {
                        handleNavigate(currentIndex + 1);
                    }
                    break;
                case 'ArrowUp':
                case 'k':
                    event.preventDefault();
                    if (currentIndex > 0) {
                        handleNavigate(currentIndex - 1);
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
                case ' ': {
                    event.preventDefault();
                    const currentRelease = releases[currentIndex];

                    if (!currentRelease) {
                        return;
                    }

                    const nextIsOpen = openIndex !== currentIndex;

                    if (nextIsOpen) {
                        setAnnouncement(`Version ${currentRelease.version} expanded`);
                        navigateToRelease(currentIndex);
                        break;
                    }

                    pendingScrollRef.current = null;
                    setHighlightedIndex(currentIndex);
                    setOpenIndex(null);
                    setAnnouncement(`Version ${currentRelease.version} collapsed`);
                    break;
                }
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [handleNavigate, highlightedIndex, navigateToRelease, openIndex, releases, shouldIgnoreGlobalShortcut]);

    const activeTimelineIndex = highlightedIndex ?? openIndex;

    return (
        <ChangelogLayout>
            <Head title="Changelog" />
            <ChangelogTimelineNav releases={releases} activeIndex={activeTimelineIndex} onNavigate={handleNavigate} />

            {/* Screen reader announcements */}
            <div role="status" aria-live="polite" aria-atomic="true" className="sr-only">
                {announcement}
            </div>

            <h1 className="mb-6 text-2xl font-semibold">Changelog</h1>
            {error ? (
                <div
                    role="alert"
                    aria-atomic="true"
                    className="rounded-lg border border-red-200 bg-red-50 p-6 dark:border-red-800 dark:bg-red-950/20"
                >
                    <h2 className="mb-2 text-lg font-semibold text-red-800 dark:text-red-400">Failed to load changelog</h2>
                    <p className="mb-4 text-red-700 dark:text-red-300">{error}</p>
                    <Button
                        onClick={() => browserNavigation.reload()}
                        variant="destructive"
                    >
                        Reload page
                    </Button>
                </div>
            ) : (
                <ul className="relative space-y-4 border-l border-gray-300 pl-6" aria-label="Changelog Timeline">
                    {releases.map((release, index) => {
                        const isOpen = openIndex === index;
                        const buttonId = `release-trigger-${index}`;
                        const panelId = `release-${index}`;
                        const prev = releases[index - 1];
                        const [currMajor, currMinor] = release.version.split('.').map(Number);
                        const [prevMajor, prevMinor] = prev ? prev.version.split('.').map(Number) : [currMajor, currMinor];
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
                                initial={prefersReducedMotion ? {} : { opacity: 0, x: -20 }}
                                animate={prefersReducedMotion ? {} : { opacity: 1, x: 0 }}
                                transition={
                                    prefersReducedMotion
                                        ? {}
                                        : {
                                              duration: 0.2,
                                              ease: 'easeOut',
                                          }
                                }
                            >
                                <div
                                    className={`rounded-lg bg-linear-to-r p-1 ${gradientBg} ${highlightedIndex === index ? 'ring-1 ring-slate-300/70 dark:ring-slate-700/70' : ''}`}
                                >
                                    <span
                                        aria-hidden="true"
                                        data-testid="version-anchor"
                                        className={`absolute top-3 -left-3 h-3 w-3 rounded-full bg-white ring-2 ${ringColor}`}
                                    ></span>
                                    <Button
                                        onClick={() => {
                                            const wasOpen = isOpen;
                                            const nextIsOpen = !wasOpen;

                                            // Announce for screen readers
                                            setAnnouncement(
                                                wasOpen ? `Version ${release.version} collapsed` : `Version ${release.version} expanded`,
                                            );

                                            if (nextIsOpen) {
                                                navigateToRelease(index);
                                                return;
                                            }

                                            pendingScrollRef.current = null;
                                            setHighlightedIndex(index);
                                            setOpenIndex(null);
                                        }}
                                        id={buttonId}
                                        data-version={release.version}
                                        aria-expanded={isOpen}
                                        aria-controls={panelId}
                                        type="button"
                                        variant="ghost"
                                        className="h-auto w-full justify-start rounded px-2 py-3 text-left whitespace-normal hover:bg-gray-50 focus-visible:ring-blue-500 dark:hover:bg-gray-800"
                                    >
                                        <span className="flex flex-1 items-center gap-2">
                                            <span className="font-medium">Version {release.version}</span>
                                            {isNewRelease(release.date, index) && (
                                                <span
                                                    className="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium text-white"
                                                    style={{ backgroundColor: '#000200' }}
                                                >
                                                    New
                                                </span>
                                            )}
                                        </span>
                                        <span className="ml-4 text-sm text-gray-700 dark:text-gray-300">{release.date}</span>
                                    </Button>
                                    <AnimatePresence initial={false}>
                                        {isOpen && (
                                            <motion.div
                                                id={panelId}
                                                initial={prefersReducedMotion ? {} : { height: 0, opacity: 0 }}
                                                animate={prefersReducedMotion ? {} : { height: 'auto', opacity: 1 }}
                                                exit={prefersReducedMotion ? {} : { height: 0, opacity: 0 }}
                                                transition={prefersReducedMotion ? {} : { duration: 0.2 }}
                                                className="mt-2 ml-5 border-l pl-4 text-sm text-gray-700"
                                                role="region"
                                                aria-labelledby={buttonId}
                                            >
                                                {(Object.keys(sectionConfig) as Array<keyof typeof sectionConfig>).map((key) => {
                                                    const items = release[key];
                                                    if (!items || items.length === 0) return null;
                                                    const Icon = sectionConfig[key].icon;
                                                    const iconTestId =
                                                        key === 'features'
                                                            ? 'sparkles-icon'
                                                            : key === 'improvements'
                                                              ? 'trending-up-icon'
                                                              : 'bug-icon';
                                                    return (
                                                        <section key={key} className="mb-4 last:mb-0">
                                                            <h3
                                                                className={`mb-1 flex items-center gap-1.5 font-semibold ${sectionConfig[key].color}`}
                                                            >
                                                                <Icon className="h-4 w-4" aria-hidden="true" data-testid={iconTestId} />
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
                                </div>
                            </motion.li>
                        );
                    })}
                </ul>
            )}
        </ChangelogLayout>
    );
}
