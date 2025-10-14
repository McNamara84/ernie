import { motion } from 'framer-motion';
import { History } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
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
            <div className="fixed bottom-6 right-6 z-50">
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="h-12 w-12 rounded-full bg-white shadow-lg hover:shadow-xl dark:bg-gray-800"
                                onClick={() => setIsOpen(!isOpen)}
                                aria-label="Toggle timeline navigation"
                            >
                                <History className="h-5 w-5" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="left">
                            <p>Timeline Navigation</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                {isOpen && (
                    <motion.div
                        initial={{ opacity: 0, scale: 0.8, y: 20 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.8, y: 20 }}
                        className="absolute bottom-16 right-0 max-h-96 w-48 overflow-y-auto rounded-lg bg-white p-4 shadow-2xl dark:bg-gray-800"
                    >
                        <div className="space-y-2">
                            {releases.map((release, index) => (
                                <button
                                    key={release.version}
                                    onClick={() => {
                                        onNavigate(index);
                                        setIsOpen(false);
                                    }}
                                    className={cn(
                                        'flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition-colors',
                                        activeIndex === index
                                            ? 'bg-gray-100 font-medium dark:bg-gray-700'
                                            : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'h-2 w-2 flex-shrink-0 rounded-full',
                                            getVersionColor(index),
                                            activeIndex === index && 'ring-2 ring-gray-400 ring-offset-2'
                                        )}
                                    />
                                    <span className="flex-1 truncate">v{release.version}</span>
                                </button>
                            ))}
                        </div>
                    </motion.div>
                )}
            </div>
        );
    }

    // Desktop: Fixed Right Navigation
    return (
        <nav
            className="fixed right-8 top-1/2 z-40 hidden -translate-y-1/2 md:block"
            aria-label="Version timeline navigation"
        >
            <div className="flex flex-col items-center gap-3">
                {releases.map((release, index) => {
                    const isActive = activeIndex === index;
                    const color = getVersionColor(index);
                    
                    return (
                        <TooltipProvider key={release.version}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <motion.button
                                        whileHover={{ scale: 1.3 }}
                                        whileTap={{ scale: 0.9 }}
                                        onClick={() => onNavigate(index)}
                                        className={cn(
                                            'relative rounded-full transition-all',
                                            isActive ? 'h-4 w-4' : 'h-2.5 w-2.5',
                                            color,
                                            isActive && 'ring-2 ring-gray-400 ring-offset-2 dark:ring-gray-600'
                                        )}
                                        aria-label={`Navigate to version ${release.version}`}
                                        aria-current={isActive ? 'true' : undefined}
                                    >
                                        {isActive && (
                                            <motion.span
                                                layoutId="activeIndicator"
                                                className="absolute inset-0 rounded-full bg-white/30"
                                                transition={{ type: 'spring', stiffness: 380, damping: 30 }}
                                            />
                                        )}
                                    </motion.button>
                                </TooltipTrigger>
                                <TooltipContent side="left" className="text-xs">
                                    <div>
                                        <p className="font-semibold">Version {release.version}</p>
                                        <p className="text-gray-500">{release.date}</p>
                                    </div>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    );
                })}
            </div>
        </nav>
    );
}
