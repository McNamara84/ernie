import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, ClipboardCheck, FilePlus2, FlaskConical, FolderClock, type LucideIcon, Settings, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { GuidedTourAutostart } from '@/components/tours/guided-tour-autostart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { type DataCiteUploadResult, UnifiedDropzone } from '@/components/unified-dropzone';
import AppLayout from '@/layouts/app-layout';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { type GuidedTourAutostartPayload } from '@/lib/tours/definitions';
import { cn } from '@/lib/utils';
import { latestVersion } from '@/lib/version';
import { changelog as changelogRoute, dashboard, editor as editorRoute } from '@/routes';
import { uploadJson as uploadJsonRoute, uploadXml as uploadXmlRoute } from '@/routes/dashboard';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { UploadErrorResponse } from '@/types/upload';

type UploadSessionResponse = {
    resourceId?: number | string | null;
    sessionKey?: string | null;
};

/**
 * Shared helper for file uploads (XML, JSON, JSON-LD).
 * New uploads return the persisted draft target so the dashboard can stay in place.
 */
async function uploadSessionFile(file: File, route: { url: () => string }, sessionQueryKey: string): Promise<DataCiteUploadResult> {
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
                // Response body is not valid JSON (e.g. HTML error page) - use generic message
            }
            throw new Error(message);
        }

        const data: UploadSessionResponse = await response.json();
        const resourceId = data.resourceId !== undefined && data.resourceId !== null ? String(data.resourceId).trim() : '';
        const sessionKey = data.sessionKey ? String(data.sessionKey).trim() : '';

        if (resourceId !== '') {
            return {
                success: true,
                uploadKind: 'datacite',
                filename,
                resourceId,
                sessionKey: sessionKey || null,
                editorUrl: editorRoute({ query: { resourceId } }).url,
            };
        }

        if (sessionKey !== '') {
            return {
                success: true,
                uploadKind: 'datacite',
                filename,
                resourceId: null,
                sessionKey,
                editorUrl: editorRoute({ query: { [sessionQueryKey]: sessionKey } }).url,
            };
        }

        throw new Error('Upload completed but no editor target was returned for ' + filename + '.');
    } catch (error) {
        console.error(`${sessionQueryKey} upload failed`, error);
        if (error instanceof Error) {
            throw error;
        }
        throw new Error(`Failed to upload ${filename}`, { cause: error });
    }
}

export const handleXmlFiles = async (files: File[]): Promise<DataCiteUploadResult | undefined> => {
    if (!files.length) return undefined;
    return uploadSessionFile(files[0], uploadXmlRoute, 'xmlSession');
};

export const handleJsonFiles = async (files: File[]): Promise<DataCiteUploadResult | undefined> => {
    if (!files.length) return undefined;
    return uploadSessionFile(files[0], uploadJsonRoute, 'jsonSession');
};

type DashboardProps = {
    onXmlFiles?: (files: File[]) => Promise<DataCiteUploadResult | undefined>;
    onJsonFiles?: (files: File[]) => Promise<DataCiteUploadResult | undefined>;
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
    recentResources?: Array<{
        id: number;
        title: string;
        updated_at: string | null;
        status?: 'draft' | 'curation' | 'review' | 'published';
    }>;
    guidedTour?: GuidedTourAutostartPayload | null;
    phpVersion?: string;
    laravelVersion?: string;
};

type RecentResource = NonNullable<DashboardPageProps['recentResources']>[number];

type DashboardQuickAction = {
    title: string;
    description: string;
    icon: LucideIcon;
    href?: string;
    variant?: 'default' | 'outline' | 'secondary';
    onClick?: () => void;
};

function QuickActionCard({ title, description, icon: Icon, href, variant = 'outline', onClick }: DashboardQuickAction) {
    const isPrimaryAction = variant === 'default';

    const content = (
        <div className="flex w-full min-w-0 items-start justify-between gap-3">
            <div className="flex min-w-0 items-start gap-3">
                <span
                    className={cn(
                        'mt-0.5 rounded-xl p-2',
                        isPrimaryAction ? 'bg-primary-foreground/12 text-primary-foreground' : 'bg-primary/10 text-primary',
                    )}
                >
                    <Icon className="h-4 w-4" />
                </span>
                <div className="min-w-0 space-y-1">
                    <p className={cn('text-sm leading-5 font-semibold break-words', isPrimaryAction ? 'text-primary-foreground' : 'text-foreground')}>
                        {title}
                    </p>
                    <p className={cn('text-xs leading-5 break-words', isPrimaryAction ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
                        {description}
                    </p>
                </div>
            </div>
            <ArrowRight
                className={cn(
                    'mt-1 h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5',
                    isPrimaryAction ? 'text-primary-foreground/85' : 'text-muted-foreground',
                )}
            />
        </div>
    );

    if (href) {
        return (
            <Button
                asChild
                variant={variant}
                className="group h-auto w-full justify-start rounded-2xl border px-4 py-4 text-left whitespace-normal shadow-sm"
            >
                <Link href={href}>{content}</Link>
            </Button>
        );
    }

    return (
        <Button
            variant={variant}
            className="group h-auto w-full justify-start rounded-2xl border px-4 py-4 text-left whitespace-normal shadow-sm"
            onClick={onClick}
        >
            {content}
        </Button>
    );
}

function OverviewMetric({ label, value, description }: { label: string; value: string | number; description: string }) {
    return (
        <div className="min-w-0 rounded-xl border bg-background/70 p-4">
            <p className="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight text-foreground">{value}</p>
            <p className="mt-1 text-xs leading-5 break-words text-muted-foreground">{description}</p>
        </div>
    );
}

function formatResourceStatus(status?: RecentResource['status']) {
    switch (status) {
        case 'draft':
            return 'Draft';
        case 'curation':
            return 'Curation';
        case 'review':
            return 'Review';
        case 'published':
            return 'Published';
        default:
            return null;
    }
}

function EnvironmentVersionRow({
    label,
    href,
    ariaLabel,
    badgeClassName,
    value,
}: {
    label: string;
    href: string;
    ariaLabel: string;
    badgeClassName: string;
    value: string;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background/70 px-3 py-2.5">
            <span className="text-sm text-muted-foreground">{label}</span>
            <a href={href} target="_blank" rel="noopener noreferrer" aria-label={ariaLabel} className="shrink-0">
                <Badge className={cn('min-w-[4.5rem] justify-center text-white', badgeClassName)}>{value}</Badge>
            </a>
        </div>
    );
}

export default function Dashboard({ onXmlFiles = handleXmlFiles, onJsonFiles = handleJsonFiles }: DashboardProps = {}) {
    const {
        auth,
        dataResourceCount,
        igsnCount,
        dataInstitutionCount,
        igsnInstitutionCount,
        draftCount,
        recentResources,
        pendingAssistanceTotalCount,
        guidedTour = null,
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

    const hasRecentResources = Boolean(recentResources?.length);
    const recentResourceHref = recentResources?.[0] ? editorRoute({ query: { resourceId: recentResources[0].id } }).url : '/resources';

    const quickActions = useMemo<DashboardQuickAction[]>(() => {
        const actions: DashboardQuickAction[] = [
            {
                title: 'Create resource',
                description: 'Start a new metadata record from scratch.',
                href: editorRoute().url,
                icon: FilePlus2,
                variant: 'default',
            },
            {
                title: hasRecentResources ? 'Resume latest resource' : 'Browse resources',
                description: hasRecentResources
                    ? `Jump back into the resource you edited most recently.`
                    : 'Open the resource list to review published and draft records.',
                href: recentResourceHref,
                icon: FolderClock,
            },
            {
                title: 'Open IGSNs',
                description: 'Review sample records and jump into IGSN curation.',
                href: '/igsns',
                icon: FlaskConical,
            },
        ];

        if (auth.user?.can_access_assistance) {
            actions.push({
                title: 'Review assistance',
                description: 'Inspect pending suggestions and resolve open assistance items.',
                href: '/assistance',
                icon: Sparkles,
            });
        }

        if (auth.user?.can_access_assessment) {
            actions.push({
                title: 'Open assessment',
                description: 'Check repository assessment progress and current findings.',
                href: '/assessment',
                icon: ClipboardCheck,
            });
        }

        if (auth.user?.can_access_editor_settings) {
            actions.push({
                title: 'Adjust settings',
                description: 'Manage editor defaults and shared curation configuration.',
                href: '/settings',
                icon: Settings,
                variant: 'secondary',
            });
        }

        return actions;
    }, [auth.user, hasRecentResources, recentResourceHref]);

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

                // Random rotation (-15deg to +15deg)
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
            <GuidedTourAutostart guidedTour={guidedTour} />
            <div data-testid="dashboard-page" className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-x-hidden rounded-xl p-4 lg:p-6">
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.95fr)]">
                    <div className="grid gap-4">
                        <Card
                            onMouseEnter={() => handleCardHover('welcome')}
                            data-tour="dashboard-welcome"
                            data-testid="dashboard-welcome-card"
                            className="overflow-hidden border-primary/10 bg-linear-to-br from-gfz-primary/5 via-background to-background"
                        >
                            <CardHeader className="gap-4 pb-2">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 space-y-3">
                                        <Badge variant="secondary" className="w-fit rounded-full px-3 py-1 text-xs tracking-[0.16em] uppercase">
                                            Today
                                        </Badge>
                                        <div className="space-y-1.5">
                                            <CardTitle className="text-2xl leading-tight break-words">Hello {auth.user.name}!</CardTitle>
                                            <CardDescription className="max-w-2xl text-sm leading-6">
                                                Start from the task you actually need right now: resume recent work, create a new record, or jump into
                                                import without hunting through the navigation.
                                            </CardDescription>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-4 rounded-2xl border bg-background/85 px-4 py-3 shadow-sm">
                                        <div className="min-w-0 text-right">
                                            <p className="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">Open drafts</p>
                                            <p className="mt-1 text-xs text-muted-foreground">Ready to resume</p>
                                        </div>
                                        <p className="text-3xl font-semibold tracking-tight text-foreground">{draftCount ?? 0}</p>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                                    {quickActions.map((action) => (
                                        <QuickActionCard key={action.title} {...action} />
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div className="space-y-1">
                                    <CardTitle className="text-lg">Continue where you left off</CardTitle>
                                    <CardDescription>
                                        Resources you recently edited stay close at hand so you can jump back into work immediately.
                                    </CardDescription>
                                </div>
                                {hasRecentResources && (
                                    <Button asChild variant="ghost" className="px-0 text-sm">
                                        <Link href={recentResourceHref}>Open latest resource</Link>
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent className="pt-0">
                                {recentResources && recentResources.length > 0 ? (
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {recentResources.slice(0, 4).map((resource) => {
                                            const statusLabel = formatResourceStatus(resource.status);

                                            return (
                                                <Link
                                                    key={resource.id}
                                                    href={editorRoute({ query: { resourceId: resource.id } }).url}
                                                    className="group rounded-xl border bg-card px-4 py-3 transition-colors hover:border-primary/40 hover:bg-accent/30"
                                                >
                                                    <div className="flex min-w-0 items-start justify-between gap-2">
                                                        <p className="line-clamp-2 leading-6 font-medium text-foreground transition-colors group-hover:text-primary">
                                                            {resource.title}
                                                        </p>
                                                        {statusLabel && (
                                                            <Badge variant="secondary" className="shrink-0 rounded-full text-[0.68rem]">
                                                                {statusLabel}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {resource.updated_at
                                                            ? `Updated ${new Date(resource.updated_at).toLocaleDateString()}`
                                                            : 'Resource available to resume'}
                                                    </p>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="rounded-xl border border-dashed bg-muted/30 p-2">
                                        <EmptyState
                                            icon={<FolderClock className="h-8 w-8" />}
                                            title="No recent resources"
                                            description="Create a metadata record or edit an existing resource to build your personal recent work list."
                                        />
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="grid gap-4" data-testid="dashboard-side-column">
                        <Card
                            id="dashboard-upload-panel"
                            data-testid="dashboard-upload-card"
                            className="border-primary/10"
                            data-tour="dashboard-upload"
                        >
                            <CardHeader className="items-center text-center">
                                <Badge variant="secondary" className="rounded-full px-3 py-1 text-xs tracking-[0.16em] uppercase">
                                    Import hub
                                </Badge>
                                <div className="space-y-2">
                                    <CardTitle>Upload Files</CardTitle>
                                    <CardDescription>
                                        Upload DataCite files or IGSN CSV files from one place, then review the result before choosing the next
                                        workspace.
                                    </CardDescription>
                                </div>
                            </CardHeader>
                            <CardContent className="flex w-full justify-center pt-0">
                                <UnifiedDropzone onXmlUpload={onXmlFiles} onJsonUpload={onJsonFiles} />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Operational overview</CardTitle>
                                <CardDescription>Fast health check for your curation workload and repository inventory.</CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <OverviewMetric
                                    label="Datasets"
                                    value={datasetCount}
                                    description={`${dataInstitutions} institutions with registered data resources`}
                                />
                                <OverviewMetric
                                    label="IGSNs"
                                    value={igsnCountDisplay}
                                    description={`${igsnInstitutions} institutions with sample records`}
                                />
                                <OverviewMetric
                                    label="Drafts"
                                    value={draftCount ?? 0}
                                    description="Records that still need review or publication work"
                                />
                                {auth.user?.can_access_assistance && (
                                    <OverviewMetric
                                        label="Assistance"
                                        value={pendingAssistanceTotalCount ?? 0}
                                        description="Pending suggestions currently waiting for review"
                                    />
                                )}
                            </CardContent>
                        </Card>

                        <Card onMouseEnter={() => handleCardHover('environment')}>
                            <CardHeader>
                                <CardTitle>Environment</CardTitle>
                                <CardDescription>Key runtime versions and release notes for the current stack.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm text-muted-foreground">
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background/70 px-3 py-2.5">
                                    <span className="text-sm text-muted-foreground">ERNIE Version</span>
                                    <Link href={changelogRoute().url} aria-label={`View changelog for version ${latestVersion}`} className="shrink-0">
                                        <Badge className="min-w-[4.5rem] justify-center bg-[#003da6] text-white">{latestVersion}</Badge>
                                    </Link>
                                </div>
                                <EnvironmentVersionRow
                                    label="PHP Version"
                                    href={`https://www.php.net/releases/${phpVersion.split('.').slice(0, 2).join('.')}/en.php`}
                                    ariaLabel={`View PHP ${phpVersion.split('.').slice(0, 2).join('.')} release notes`}
                                    badgeClassName="bg-[#777BB4] transition-colors hover:bg-[#666BA0]"
                                    value={phpVersion}
                                />
                                <EnvironmentVersionRow
                                    label="Laravel Version"
                                    href={`https://laravel.com/docs/${laravelVersion.split('.')[0]}.x/releases`}
                                    ariaLabel={`View Laravel ${laravelVersion.split('.')[0]}.x release notes`}
                                    badgeClassName="bg-[#FF2D20] transition-colors hover:bg-[#E6291C]"
                                    value={laravelVersion}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

            {/* Easter Egg: Unicorn overlay */}
            {isEasterEggActive &&
                unicorns.map((unicorn) => (
                    <img
                        key={unicorn.id}
                        src="/images/unicorn.png"
                        alt="Unicorn"
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
