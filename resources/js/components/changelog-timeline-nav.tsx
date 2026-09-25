import { History } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { getReleaseKind, type ReleaseKind } from '@/lib/changelog-release-kind';
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

const kindColors: Record<ReleaseKind, string> = {
    major: 'bg-green-500',
    minor: 'bg-blue-500',
    patch: 'bg-red-500',
};

function VersionLegend() {
    return (
        <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground" aria-label="Version color legend">
            {(['major', 'minor', 'patch'] as const).map((kind) => (
                <span key={kind} className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className={cn('size-2.5 rounded-full', kindColors[kind])} />
                    <span>{kind.charAt(0).toUpperCase() + kind.slice(1)}</span>
                </span>
            ))}
        </div>
    );
}

export function ChangelogTimelineNav({ releases, activeIndex, onNavigate }: TimelineNavProps) {
    const [isMobile, setIsMobile] = useState(false);
    const [isOpen, setIsOpen] = useState(false);
    const toggleRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const checkMobile = () => setIsMobile(window.innerWidth < 1280);
        checkMobile();
        window.addEventListener('resize', checkMobile);
        return () => window.removeEventListener('resize', checkMobile);
    }, []);

    if (releases.length === 0) return null;

    const versionButtons = releases.map((release, index) => {
        const kind = getReleaseKind(releases, index);
        return (
            <Button
                key={release.version}
                variant="outline"
                type="button"
                className={cn('h-10 w-full justify-start gap-3 px-3 text-left', activeIndex === index && 'border-primary bg-accent font-semibold')}
                onClick={() => {
                    onNavigate(index);
                    if (isMobile) {
                        setIsOpen(false);
                        toggleRef.current?.focus();
                    }
                }}
                aria-label={`Navigate to version ${release.version}`}
                aria-current={activeIndex === index ? 'true' : undefined}
            >
                <span data-testid="timeline-dot" aria-hidden="true" className={cn('size-3 shrink-0 rounded-full', kindColors[kind])} />
                <span className="min-w-0 flex-1 truncate">v{release.version}</span>
            </Button>
        );
    });

    if (isMobile) {
        return (
            <nav
                className="order-1 rounded-lg border bg-card p-3 xl:hidden"
                aria-label="Version timeline navigation"
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && isOpen) {
                        setIsOpen(false);
                        toggleRef.current?.focus();
                    }
                }}
            >
                <Button
                    ref={toggleRef}
                    variant="outline"
                    type="button"
                    className="h-11 w-full justify-start gap-2"
                    onClick={() => setIsOpen((value) => !value)}
                    aria-label="Toggle timeline navigation"
                    aria-expanded={isOpen}
                    aria-controls="changelog-timeline-menu"
                >
                    <History className="size-4" aria-hidden="true" />
                    Versions
                </Button>
                {isOpen && (
                    <div id="changelog-timeline-menu" className="mt-3 max-h-80 space-y-3 overflow-y-auto">
                        <VersionLegend />
                        <div className="space-y-2">{versionButtons}</div>
                    </div>
                )}
            </nav>
        );
    }

    return (
        <nav
            className="sticky top-20 order-2 max-h-[calc(100vh-6rem)] self-start overflow-y-auto rounded-xl border bg-card p-4 shadow-sm"
            aria-label="Version timeline navigation"
        >
            <h2 className="mb-3 text-sm font-semibold">Versions</h2>
            <VersionLegend />
            <div className="mt-4 space-y-2">{versionButtons}</div>
        </nav>
    );
}
