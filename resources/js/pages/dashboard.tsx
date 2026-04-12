import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import { UnifiedDropzone } from '@/components/unified-dropzone';
import AppLayout from '@/layouts/app-layout';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { latestVersion } from '@/lib/version';
import { changelog as changelogRoute, dashboard, editor as editorRoute } from '@/routes';
import { uploadJson as uploadJsonRoute, uploadXml as uploadXmlRoute } from '@/routes/dashboard';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { UploadErrorResponse } from '@/types/upload';

/**
 * Shared helper for session-based file uploads (XML, JSON, JSON-LD).
 * Posts the file to the given route and navigates to the editor with the session key.
 */
async function uploadSessionFile(file: File, route: { url: () => string }, sessionQueryKey: string): Promise<void> {
    const csrfHeaders = buildCsrfHeaders();

    if (!csrfHeaders['X-CSRF-TOKEN'] && !csrfHeaders['X-XSRF-TOKEN']) {
        throw new Error('CSRF token not found. Please reload the page (Ctrl+F5) and try again.');
    }

    const formData = new FormData();
    formData.append('file', file);
    const filename = file.name;

    try {
        const response = await fetch(route.url(), {
            method: 'POST',
            body: formData,
            headers: csrfHeaders,
            credentials: 'same-origin',
        });

        if (!response.ok) {
            if (response.status === 419) {
                console.warn('CSRF token expired, reloading page...');
                window.location.reload();
                throw new Error('Session expired. Reloading page...');
            }

            let message = `Failed to upload ${filename}`;
            try {
                const errorData: UploadErrorResponse = await response.json();
                if (errorData.message) {
                    message = errorData.message;
                }
            } catch {
                // Response body is not valid JSON (e.g. HTML error page) – use generic message
            }
            throw new Error(message);
        }

        const data: { sessionKey: string } = await response.json();

        router.get(editorRoute({ query: { [sessionQueryKey]: data.sessionKey } }).url);
    } catch (error) {
        console.error(`${sessionQueryKey} upload failed`, error);
        if (error instanceof Error) {
            throw error;
        }
        throw new Error(`Failed to upload ${filename}`, { cause: error });
    }
}

export const handleXmlFiles = async (files: File[]): Promise<void> => {
    if (!files.length) return;
    await uploadSessionFile(files[0], uploadXmlRoute, 'xmlSession');
};

export const handleJsonFiles = async (files: File[]): Promise<void> => {
    if (!files.length) return;
    await uploadSessionFile(files[0], uploadJsonRoute, 'jsonSession');
};

type DashboardProps = {
    onXmlFiles?: (files: File[]) => Promise<void>;
    onJsonFiles?: (files: File[]) => Promise<void>;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

type DashboardPageProps = SharedData & {
    dataResourceCount?: number;
    igsnCount?: number;
    dataInstitutionCount?: number;
    igsnInstitutionCount?: number;
    draftCount?: number;
    recentDrafts?: Array<{
        id: number;
        title: string;
        updated_at: string | null;
    }>;
    phpVersion?: string;
    laravelVersion?: string;
};

export default function Dashboard({ onXmlFiles = handleXmlFiles, onJsonFiles = handleJsonFiles }: DashboardProps = {}) {
    const {
        auth,
        dataResourceCount,
        igsnCount,
        dataInstitutionCount,
        igsnInstitutionCount,
        draftCount,
        recentDrafts,
        pendingAssistanceTotalCount,
        phpVersion = '8.4.12',
        laravelVersion = '12.28.1',
    } = usePage<DashboardPageProps>().props;

    // Easter Egg State
    const [isEasterEggActive, setIsEasterEggActive] = useState(false);
    const [unicornCount, setUnicornCount] = useState(0);
    const [unicorns, setUnicorns] = useState<Array<{ id: number; x: number; y: number; size: number; rotation: number }>>([]);
    const [showConfetti, setShowConfetti] = useState(false);
    const easterEggTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const hoverCountRef = useRef(0);
    const lastHoveredCardRef = useRef<'welcome' | 'environment' | null>(null);
    const unicornIdCounterRef = useRef(0);

    const datasetCount = typeof dataResourceCount === 'number' ? dataResourceCount : 0;
    const igsnCountDisplay = typeof igsnCount === 'number' ? igsnCount : 0;
    const dataInstitutions = typeof dataInstitutionCount === 'number' ? dataInstitutionCount : 0;
    const igsnInstitutions = typeof igsnInstitutionCount === 'number' ? igsnInstitutionCount : 0;

    // Easter Egg: Reset everything
    const resetEasterEgg = useCallback(() => {
        setIsEasterEggActive(false);
        setUnicornCount(0);
        setUnicorns([]);
        hoverCountRef.current = 0;
        lastHoveredCardRef.current = null;
        unicornIdCounterRef.current = 0;
        setShowConfetti(false);
        if (easterEggTimeoutRef.current) {
            clearTimeout(easterEggTimeoutRef.current);
            easterEggTimeoutRef.current = null;
        }
    }, []);

    // Easter Egg: Handle hover tracking
    const handleCardHover = useCallback(
        (cardName: 'welcome' | 'environment') => {
            if (isEasterEggActive) return;

            // Only count if switching between cards
            const prevCard = lastHoveredCardRef.current;
            if (prevCard !== null && prevCard !== cardName) {
                hoverCountRef.current += 1;

                // Trigger easter egg after 10 switches
                if (hoverCountRef.current >= 10) {
                    setIsEasterEggActive(true);
                    setUnicornCount(1);
                }
            }

            lastHoveredCardRef.current = cardName;
        },
        [isEasterEggActive],
    );

    // Easter Egg: Generate stable confetti configuration
    const confettiPieces = useMemo(() => {
        return Array.from({ length: 50 }).map((_, i) => ({
            id: i,
            left: Math.random() * 100,
            delay: Math.random() * 3,
            color: `hsl(${Math.random() * 360}, 70%, 60%)`,
            horizontalMovement: Math.random(),
        }));
    }, []);

    // Easter Egg: Handle ESC key to cancel
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && isEasterEggActive) {
                resetEasterEgg();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isEasterEggActive, resetEasterEgg]);

    // Easter Egg: Spawn unicorns exponentially
    useEffect(() => {
        if (!isEasterEggActive || unicornCount === 0) return;

        // Capture viewport dimensions once to avoid SSR issues and ensure consistency
        const viewportWidth = typeof window !== 'undefined' ? window.innerWidth : 1920;
        const viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 1080;

        // Calculate how many NEW unicorns to add and generate them
        setUnicorns((currentUnicorns) => {
            const currentCount = currentUnicorns.length;
            const newUnicornsToAdd = unicornCount - currentCount;

            const additionalUnicorns: typeof currentUnicorns = [];

            for (let i = 0; i < newUnicornsToAdd; i++) {
                // Random position within viewport
                const x = Math.random() * (viewportWidth - 100);
                const y = Math.random() * (viewportHeight - 100);

                // Random size (80% - 120% of original)
                const size = 0.8 + Math.random() * 0.4;

                // Random rotation (-15° to +15°)
                const rotation = -15 + Math.random() * 30;

                // Generate unique ID using counter
                unicornIdCounterRef.current += 1;

                additionalUnicorns.push({
                    id: unicornIdCounterRef.current,
                    x,
                    y,
                    size,
                    rotation,
                });
            }

            // ADD to existing unicorns instead of replacing
            return [...currentUnicorns, ...additionalUnicorns];
        });

        // Double the count for next iteration, max 128
        if (unicornCount < 128) {
            easterEggTimeoutRef.current = setTimeout(() => {
                setUnicornCount(unicornCount * 2);
            }, 1000);
        } else {
            // Show confetti when we reach max
            setShowConfetti(true);

            // Hide everything after 5 seconds
            easterEggTimeoutRef.current = setTimeout(() => {
                resetEasterEgg();
            }, 5000);
        }

        // Cleanup timeout on unmount or before re-run
        return () => {
            if (easterEggTimeoutRef.current) {
                clearTimeout(easterEggTimeoutRef.current);
            }
        };
    }, [unicornCount, isEasterEggActive, resetEasterEgg]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Card onMouseEnter={() => handleCardHover('welcome')}>
                        <CardHeader>
                            <CardTitle>Hello {auth.user.name}!</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <p>
                                Nice to see you today! You still have{' '}
                                <strong className="font-semibold text-foreground">{draftCount ?? 0}</strong> draft
                                {(draftCount ?? 0) !== 1 ? 's' : ''} to complete. Have fun, your ERNIE!
                            </p>
                            {recentDrafts && recentDrafts.length > 0 && (
                                <div className="mt-3 space-y-1">
                                    <p className="text-xs font-medium text-foreground">Recent drafts:</p>
                                    <ul className="space-y-0.5">
                                        {recentDrafts.map((draft) => (
                                            <li key={draft.id}>
                                                <Link
                                                    href={editorRoute({ query: { resourceId: draft.id } }).url}
                                                    className="text-xs text-primary underline-offset-4 hover:underline"
                                                >
                                                    {draft.title}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Statistics</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <p>
                                <strong className="font-semibold text-foreground">{datasetCount}</strong> datasets from{' '}
                                <strong className="font-semibold text-foreground">{dataInstitutions}</strong> institutions
                            </p>
                            <p>
                                <strong className="font-semibold text-foreground">{igsnCountDisplay}</strong> IGSNs from{' '}
                                <strong className="font-semibold text-foreground">{igsnInstitutions}</strong> institutions
                            </p>
                            {auth.user?.can_access_assistance && (pendingAssistanceTotalCount ?? 0) > 0 && (
                                <p>
                                    <strong className="font-semibold text-foreground">{pendingAssistanceTotalCount}</strong> pending
                                    suggestions
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card onMouseEnter={() => handleCardHover('environment')}>
                        <CardHeader>
                            <CardTitle>Environment</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <Table>
                                <TableBody>
                                    <TableRow>
                                        <TableCell className="py-1">ERNIE Version</TableCell>
                                        <TableCell className="py-1 text-right">
                                            <Link href={changelogRoute().url} aria-label={`View changelog for version ${latestVersion}`}>
                                                <Badge className="w-16 bg-[#003da6] text-white">{latestVersion}</Badge>
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell className="py-1">PHP Version</TableCell>
                                        <TableCell className="py-1 text-right">
                                            <a
                                                href={`https://www.php.net/releases/${phpVersion.split('.').slice(0, 2).join('.')}/en.php`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label={`View PHP ${phpVersion.split('.').slice(0, 2).join('.')} release notes`}
                                            >
                                                <Badge className="w-16 bg-[#777BB4] text-white transition-colors hover:bg-[#666BA0]">
                                                    {phpVersion}
                                                </Badge>
                                            </a>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell className="py-1">Laravel Version</TableCell>
                                        <TableCell className="py-1 text-right">
                                            <a
                                                href={`https://laravel.com/docs/${laravelVersion.split('.')[0]}.x/releases`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label={`View Laravel ${laravelVersion.split('.')[0]}.x release notes`}
                                            >
                                                <Badge className="w-16 bg-[#FF2D20] text-white transition-colors hover:bg-[#E6291C]">
                                                    {laravelVersion}
                                                </Badge>
                                            </a>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
                <Card className="flex flex-col items-center justify-center">
                    <CardHeader className="items-center text-center">
                        <CardTitle>Upload Files</CardTitle>
                        <CardDescription>Upload DataCite files (XML, JSON, JSON-LD) or IGSN CSV files for sample metadata.</CardDescription>
                    </CardHeader>
                    <CardContent className="flex w-full justify-center">
                        <UnifiedDropzone onXmlUpload={onXmlFiles} onJsonUpload={onJsonFiles} />
                    </CardContent>
                </Card>
            </div>

            {/* Easter Egg: Unicorn overlay */}
            {isEasterEggActive &&
                unicorns.map((unicorn) => (
                    <img
                        key={unicorn.id}
                        src="/images/unicorn.png"
                        alt="🦄"
                        className="pointer-events-none fixed z-50 duration-300 animate-in fade-in zoom-in"
                        style={{
                            left: `${unicorn.x}px`,
                            top: `${unicorn.y}px`,
                            width: `${80 * unicorn.size}px`,
                            height: `${80 * unicorn.size}px`,
                            transform: `rotate(${unicorn.rotation}deg)`,
                            opacity: 0.9,
                        }}
                    />
                ))}

            {/* Easter Egg: Confetti effect */}
            {showConfetti && (
                <div className="pointer-events-none fixed inset-0 z-50 flex items-center justify-center">
                    <div className="confetti-container">
                        {confettiPieces.map((piece) => (
                            <div
                                key={piece.id}
                                className="confetti"
                                style={
                                    {
                                        left: `${piece.left}%`,
                                        animationDelay: `${piece.delay}s`,
                                        backgroundColor: piece.color,
                                        '--random': piece.horizontalMovement,
                                    } as React.CSSProperties & { '--random': number }
                                }
                            />
                        ))}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
