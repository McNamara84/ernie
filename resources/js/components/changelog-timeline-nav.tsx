import { motion } from 'framer-motion';
import { History } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Release = {
    version: string;
    date: string;
};

type TimelineNavProps = {
    releases: Release[];
    activeIndex: number | null;
    onNavigate: (index: number) => void;
};

export function ChangelogTimelineNav({ releases, activeIndex, onNavigate }: TimelineNavProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [isMobile, setIsMobile] = useState(false);
    const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);

    useEffect(() => {
        const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        setPrefersReducedMotion(mediaQuery.matches);

        const handleChange = (e: MediaQueryListEvent) => {
            setPrefersReducedMotion(e.matches);
        };

        mediaQuery.addEventListener('change', handleChange);
        return () => mediaQuery.removeEventListener('change', handleChange);
    }, []);

    useEffect(() => {
        const checkMobile = () => {
            setIsMobile(window.innerWidth < 768);
        };

        checkMobile();
        window.addEventListener('resize', checkMobile);

        return () => window.removeEventListener('resize', checkMobile);
    }, []);

    const getVersionColor = (index: number) => {
        if (index === 0) return 'bg-green-500';

        const curr = releases[index];
        const prev = releases[index - 1];

        if (!prev || !curr) return 'bg-gray-400';

        const [currMajor, currMinor] = curr.version.split('.').map(Number);
        const [prevMajor, prevMinor] = prev.version.split('.').map(Number);

        if (currMajor !== prevMajor) return 'bg-green-500';
        if (currMinor !== prevMinor) return 'bg-blue-500';
        return 'bg-red-500';
    };

    if (releases.length === 0) return null;

    // Mobile: Floating Button
    if (isMobile) {
        return (
            <div className="fixed right-6 bottom-6 z-50">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-12 w-12 rounded-full bg-white shadow-lg hover:shadow-xl dark:bg-gray-800"
                            onClick={() => setIsOpen(!isOpen)}
                            aria-label="Toggle timeline navigation"
                            aria-expanded={isOpen}
                            aria-controls="changelog-timeline-menu"
                        >
                            <History className="h-5 w-5" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent side="left">
                        <p>Timeline Navigation</p>
                    </TooltipContent>
                </Tooltip>

                {isOpen && (
                    <motion.div
                        initial={prefersReducedMotion ? {} : { opacity: 0, scale: 0.8, y: 20 }}
                        animate={prefersReducedMotion ? {} : { opacity: 1, scale: 1, y: 0 }}
                        exit={prefersReducedMotion ? {} : { opacity: 0, scale: 0.8, y: 20 }}
                        id="changelog-timeline-menu"
                        className="absolute right-0 bottom-16 max-h-96 w-48 overflow-y-auto rounded-lg bg-white p-4 shadow-2xl dark:bg-gray-800"
                    >
                        <div className="space-y-2">
                            {releases.map((release, index) => (
                                <Button
                                    key={release.version}
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        onNavigate(index);
                                        setIsOpen(false);
                                    }}
                                    className={cn(
                                        'w-full justify-start gap-2',
                                        activeIndex === index && 'bg-accent font-medium',
                                    )}
                                    aria-current={activeIndex === index ? 'true' : undefined}
                                >
                                    <span
                                        className={cn(
                                            'size-2 shrink-0 rounded-full',
                                            getVersionColor(index),
                                            activeIndex === index && 'ring-2 ring-gray-400 ring-offset-2',
                                        )}
                                    />
                                    <span className="flex-1 truncate text-left">v{release.version}</span>
                                </Button>
                            ))}
                        </div>
                    </motion.div>
                )}
            </div>
        );
    }

    // Desktop: Fixed Right Navigation
    return (
        <nav className="fixed top-1/2 right-8 z-40 hidden -translate-y-1/2 md:block" aria-label="Version timeline navigation">
            <div className="flex flex-col items-center gap-3">
                {releases.map((release, index) => {
                    const isActive = activeIndex === index;
                    const color = getVersionColor(index);

                    return (
                        <Tooltip key={release.version}>
                            <TooltipTrigger asChild>
                                <motion.button
                                    whileHover={prefersReducedMotion ? {} : { scale: 1.05 }}
                                    whileTap={prefersReducedMotion ? {} : { scale: 0.96 }}
                                    onClick={() => onNavigate(index)}
                                    className={cn(
                                        'flex h-8 w-8 items-center justify-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-400 focus-visible:ring-offset-2 dark:focus-visible:ring-gray-600',
                                        isActive && 'bg-accent/50',
                                    )}
                                    aria-label={`Navigate to version ${release.version}`}
                                    aria-current={isActive ? 'true' : undefined}
                                >
                                    <span
                                        data-testid="timeline-dot"
                                        className={cn(
                                            'rounded-full transition-all',
                                            isActive ? 'h-4 w-4' : 'h-2.5 w-2.5',
                                            color,
                                            isActive && 'ring-2 ring-gray-400 ring-offset-2 ring-offset-background dark:ring-gray-600 dark:ring-offset-gray-950',
                                        )}
                                    />
                                </motion.button>
                            </TooltipTrigger>
                            <TooltipContent side="left" className="text-xs">
                                <div>
                                    <p className="font-semibold">Version {release.version}</p>
                                    <p className="text-gray-700 dark:text-gray-300">{release.date}</p>
                                </div>
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </div>
        </nav>
    );
}
