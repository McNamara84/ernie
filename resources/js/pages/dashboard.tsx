import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { latestVersion } from '@/lib/version';
import { changelog as changelogRoute, dashboard, editor as editorRoute } from '@/routes';
import { uploadXml as uploadXmlRoute } from '@/routes/dashboard';
import { type BreadcrumbItem, type SharedData } from '@/types';

export const handleXmlFiles = async (files: File[]): Promise<void> => {
    if (!files.length) return;

    const csrfHeaders = buildCsrfHeaders();
    const token = csrfHeaders['X-CSRF-TOKEN'];

    if (!token) {
        throw new Error('CSRF token not found. Please reload the page (Ctrl+F5) and try again.');
    }

    const formData = new FormData();
    formData.append('file', files[0]);

    try {
        const response = await fetch(uploadXmlRoute.url(), {
            method: 'POST',
            body: formData,
            headers: csrfHeaders,
            credentials: 'same-origin',
        });

        if (!response.ok) {
            let message = 'Upload failed';

            // Handle 419 CSRF token mismatch specifically
            if (response.status === 419) {
                console.warn('CSRF token expired, reloading page...');
                // Automatically reload the page to get a fresh CSRF token
                window.location.reload();
                // Throw error to prevent further execution
                throw new Error('Session expired. Reloading page...');
            } else {
                try {
                    const errorData: { message?: string } = await response.json();
                    message = errorData.message ?? message;
                } catch (err) {
                    console.error('Failed to parse error response', err);
                }
            }
            throw new Error(message);
        }

        // Backend now returns a session key instead of all data
        const data: { sessionKey: string } = await response.json();

        // Navigate to editor with session key
        router.get(editorRoute({ query: { xmlSession: data.sessionKey } }).url);
    } catch (error) {
        console.error('XML upload failed', error);
        if (error instanceof Error) {
            throw new Error(`Upload failed: ${error.message}`);
        }
        throw new Error('Upload failed');
    }
};

type DashboardProps = {
    onXmlFiles?: (files: File[]) => Promise<void>;
};

function filterXmlFiles(files: File[]): File[] {
    return files.filter((file) => file.type === 'text/xml' || file.name.endsWith('.xml'));
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

type DashboardPageProps = SharedData & {
    resourceCount?: number;
    phpVersion?: string;
    laravelVersion?: string;
};

export default function Dashboard({ onXmlFiles = handleXmlFiles }: DashboardProps = {}) {
    const { auth, resourceCount, phpVersion = '8.4.12', laravelVersion = '12.28.1' } = usePage<DashboardPageProps>().props;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Easter Egg State
    const [isEasterEggActive, setIsEasterEggActive] = useState(false);
    const [unicornCount, setUnicornCount] = useState(0);
    const [unicorns, setUnicorns] = useState<Array<{ id: number; x: number; y: number; size: number; rotation: number }>>([]);
    const [showConfetti, setShowConfetti] = useState(false);
    const easterEggTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const hoverCountRef = useRef(0);
    const lastHoveredCardRef = useRef<'welcome' | 'environment' | null>(null);
    const unicornIdCounterRef = useRef(0);

    const datasetCount = typeof resourceCount === 'number' ? resourceCount : 0;

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

    async function uploadXml(files: File[]) {
        try {
            await onXmlFiles(files);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Upload failed');
        }
    }

    function handleDragOver(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setIsDragging(true);
    }

    function handleDragLeave(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        const related = event.relatedTarget as Node | null;
        if (!related || !event.currentTarget.contains(related)) {
            setIsDragging(false);
        }
    }

    function handleDrop(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setIsDragging(false);
        const files = Array.from(event.dataTransfer.files);
        const xmlFiles = filterXmlFiles(files);
        if (xmlFiles.length) {
            void uploadXml(xmlFiles);
        }
    }

    function handleFileSelect(event: React.ChangeEvent<HTMLInputElement>) {
        const files = event.target.files;
        if (files && files.length) {
            const xmlFiles = filterXmlFiles(Array.from(files));
            if (xmlFiles.length) {
                void uploadXml(xmlFiles);
            }
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {error && (
                    <Alert variant="destructive">
                        <AlertTitle>Upload error</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card onMouseEnter={() => handleCardHover('welcome')}>
                        <CardHeader>
                            <CardTitle>Hello {auth.user.name}!</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Nice to see you today! You still have x datasets to curate. Have fun, your ERNIE!
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Statistics</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <strong className="font-semibold text-foreground">{datasetCount}</strong> datasets from y data centers of z institutions
                        </CardContent>
                    </Card>
                    <Card onMouseEnter={() => handleCardHover('environment')}>
                        <CardHeader>
                            <CardTitle>Environment</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <table className="w-full">
                                <tbody>
                                    <tr>
                                        <td className="py-1">ERNIE Version</td>
                                        <td className="py-1 text-right">
                                            <Link href={changelogRoute().url} aria-label={`View changelog for version ${latestVersion}`}>
                                                <Badge className="w-16 bg-[#003da6] text-white">{latestVersion}</Badge>
                                            </Link>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="py-1">PHP Version</td>
                                        <td className="py-1 text-right">
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
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="py-1">Laravel Version</td>
                                        <td className="py-1 text-right">
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
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>
                <Card className="flex flex-col items-center justify-center">
                    <CardHeader className="items-center text-center">
                        <CardTitle>Dropzone for XML files</CardTitle>
                        <CardDescription>Here you can upload new XML files sent by ELMO for curation.</CardDescription>
                    </CardHeader>
                    <CardContent className="flex w-full justify-center">
                        <div
                            onDrop={handleDrop}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            className={`flex w-full flex-col items-center justify-center rounded-md border-2 border-dashed p-12 text-center ${
                                isDragging ? 'bg-accent' : 'bg-muted'
                            }`}
                        >
                            <p className="mb-4 text-sm text-muted-foreground">Drag &amp; drop XML files here</p>
                            <input ref={fileInputRef} type="file" accept=".xml" className="hidden" onChange={handleFileSelect} />
                            <Button type="button" onClick={() => fileInputRef.current?.click()}>
                                Upload
                            </Button>
                        </div>
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
