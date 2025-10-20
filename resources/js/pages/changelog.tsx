import { Head } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Bug, Sparkles, TrendingUp } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ChangelogTimelineNav } from '@/components/changelog-timeline-nav';
import PublicLayout from '@/layouts/public-layout';
import { withBasePath } from '@/lib/base-path';

// Type declaration for test helpers exposed on window object
declare global {
    interface Window {
        __testHelper_updateActiveRelease?: () => void;
        __testHelper_getUserInteracted?: () => boolean;
        __testHelper_setUserInteracted?: (value: boolean) => void;
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

export default function Changelog() {
    const [releases, setReleases] = useState<Release[]>([]);
    const [active, setActive] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [highlightedIndex, setHighlightedIndex] = useState<number | null>(null);
    const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const releaseRefs = useRef<(HTMLLIElement | null)[]>([]);
    const userInteractedRef = useRef<boolean>(false);

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

    // Fetch changelog data on mount
    useEffect(() => {
        fetch(withBasePath('/api/changelog'))
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Failed to fetch changelog');
                }
                return res.json();
            })
            .then((data: Release[]) => {
                setReleases(data);
                releaseRefs.current = new Array(data.length).fill(null);

                // Read hash directly from URL when data is loaded
                const hash = window.location.hash.replace('#', '');
                if (hash.startsWith('v')) {
                    const version = hash.substring(1);
                    const index = data.findIndex((r) => r.version === version);
                    if (index !== -1) {
                        userInteractedRef.current = true; // Prevent auto-expand from overriding deep-link
                        setActive(index);
                        // Scroll to element after React has rendered
                        setTimeout(() => {
                            const element = releaseRefs.current[index];
                            if (element) {
                                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 300); // Increased timeout for reliability
                    }
                } else {
                    setActive(data.length > 0 ? 0 : null);
                }
            })
            .catch(() => setError('Unable to load changelog.'));
    }, []);

    // Handle hash changes (for browser navigation and timeline clicks)
    useEffect(() => {
        if (releases.length === 0) return;

        const processHash = () => {
            const hash = window.location.hash.replace('#', '');
            if (hash.startsWith('v')) {
                const version = hash.substring(1);
                const index = releases.findIndex((r) => r.version === version);
                if (index !== -1) {
                    userInteractedRef.current = true; // Prevent auto-expand from overriding deep-link
                    setActive(index);
                    setTimeout(() => {
                        const element = releaseRefs.current[index];
                        if (element) {
                            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 100);
                }
            }
        };

        // Listen for hash changes (browser back/forward, timeline clicks)
        window.addEventListener('hashchange', processHash);

        return () => {
            window.removeEventListener('hashchange', processHash);
        };
    }, [releases]);    // Helper function to find most visible release (extracted for reuse)
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
            
            if (!userInteractedRef.current) {
                setActive(mostVisibleIndex);
            }
        }
    }, [findMostVisibleRelease]);

    // Test helper: Expose updateActiveRelease for E2E tests (Playwright doesn't trigger IntersectionObserver properly)
    useEffect(() => {
        if (typeof window !== 'undefined') {
            window.__testHelper_updateActiveRelease = updateActiveRelease;
            window.__testHelper_getUserInteracted = () => userInteractedRef.current;
            window.__testHelper_setUserInteracted = (value: boolean) => {
                userInteractedRef.current = value;
            };
            // Direct helper to expand specific release by index (bypasses visibility check)
            // NOTE: For tests only - bypasses userInteracted check for full control
            window.__testHelper_expandRelease = (index: number) => {
                if (index >= 0 && index < releases.length) {
                    setHighlightedIndex(index);
                    setActive(index);
                }
            };
        }
        return () => {
            if (typeof window !== 'undefined') {
                delete window.__testHelper_updateActiveRelease;
                delete window.__testHelper_getUserInteracted;
                delete window.__testHelper_setUserInteracted;
                delete window.__testHelper_expandRelease;
            }
        };
    }, [updateActiveRelease, releases, setActive, setHighlightedIndex]);

    // Intersection Observer for scroll-based highlighting and auto-expand
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
            const element = releaseRefs.current[index];
            if (element) {
                const version = releases[index]?.version;
                if (version) {
                    window.history.pushState(null, '', `#v${version}`);
                }
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                userInteractedRef.current = true;
                setActive(index);
                
                // Manually trigger update after programmatic scroll
                setTimeout(() => updateActiveRelease(), 400);
            }
        },
        [releases, updateActiveRelease]
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
                    userInteractedRef.current = true;
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
                    <h2 className="mb-2 text-lg font-semibold text-red-800 dark:text-red-400">
                        Failed to load changelog
                    </h2>
                    <p className="mb-4 text-red-700 dark:text-red-300">{error}</p>
                    <button 
                        onClick={() => window.location.reload()}
                        className="rounded-md bg-red-600 px-4 py-2 text-white transition-colors hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:bg-red-700 dark:hover:bg-red-800"
                    >
                        Reload page
                    </button>
                </div>
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
                            initial={prefersReducedMotion ? {} : { opacity: 0, x: -20 }}
                            animate={prefersReducedMotion ? {} : { opacity: 1, x: 0 }}
                            transition={prefersReducedMotion ? {} : { 
                                duration: 0.4, 
                                delay: index * 0.1,
                                ease: 'easeOut'
                            }}
                        >
                            <motion.div
                                animate={prefersReducedMotion ? {} : {
                                    scale: highlightedIndex === index ? 1.02 : 1,
                                }}
                                transition={prefersReducedMotion ? {} : { duration: 0.3, ease: 'easeInOut' }}
                                className={`rounded-lg bg-gradient-to-r p-1 ${gradientBg}`}
                                style={{
                                    opacity: highlightedIndex === null || highlightedIndex === index ? 1 : 0.6,
                                }}
                            >
                            <span
                                aria-hidden="true"
                                data-testid="version-anchor"
                                className={`absolute -left-3 top-3 h-3 w-3 rounded-full bg-white ring-2 ${ringColor}`}
                            ></span>
                            <button
                                onClick={() => {
                                    userInteractedRef.current = true;
                                    const wasOpen = isOpen;
                                    setActive(isOpen ? null : index);
                                    
                                    // Announce for screen readers
                                    setAnnouncement(
                                        wasOpen 
                                            ? `Version ${release.version} eingeklappt` 
                                            : `Version ${release.version} erweitert`
                                    );
                                    
                                    // Scroll element into view after toggle
                                    if (!isOpen) {
                                        setTimeout(() => {
                                            const element = releaseRefs.current[index];
                                            if (element) {
                                                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                            }
                                        }, 50);
                                    }
                                }}
                                id={buttonId}
                                aria-expanded={isOpen}
                                aria-controls={panelId}
                                type="button"
                                className="flex w-full items-center rounded px-2 py-3 text-left transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:hover:bg-gray-800"
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
                            </button>
                            <AnimatePresence initial={false}>
                                {isOpen && (
                                    <motion.div
                                        id={panelId}
                                        initial={prefersReducedMotion ? {} : { height: 0, opacity: 0 }}
                                        animate={prefersReducedMotion ? {} : { height: 'auto', opacity: 1 }}
                                        exit={prefersReducedMotion ? {} : { height: 0, opacity: 0 }}
                                        transition={prefersReducedMotion ? {} : { duration: 0.3 }}
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
                                            const iconTestId = key === 'features' ? 'sparkles-icon' : key === 'improvements' ? 'trending-up-icon' : 'bug-icon';
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
                            </motion.div>
                        </motion.li>
                    );
                })}
                </ul>
            )}
        </PublicLayout>
    );
}
