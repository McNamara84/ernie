import { Check, Copy, Info, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { usePortalResourcePreview } from '@/hooks/use-portal-resource-preview';
import { cn } from '@/lib/utils';
import type { PortalBasePath, PortalCreator, PortalResource } from '@/types/portal';

interface PortalResultCardProps {
    resource: PortalResource;
    basePath: PortalBasePath;
}

/**
 * Format authors in citation style:
 * - 1 author: "Smith"
 * - 2 authors: "Smith & Jones"
 * - 3+ authors: "Smith et al."
 */
function formatAuthors(creators: PortalCreator[]): string {
    if (creators.length === 0) {
        return 'Unknown';
    }

    const formatName = (creator: PortalCreator): string => creator.name || 'Unknown';

    if (creators.length === 1) {
        return formatName(creators[0]);
    }

    if (creators.length === 2) {
        return `${formatName(creators[0])} & ${formatName(creators[1])}`;
    }

    return `${formatName(creators[0])} et al.`;
}

function getTypeBadgeVariant(isIgsn: boolean): 'default' | 'secondary' | 'outline' {
    return isIgsn ? 'secondary' : 'default';
}

/** A compact portal result with explicitly requested citation and abstract details. */
export function PortalResultCard({ resource, basePath }: PortalResultCardProps) {
    const [isPreviewOpen, setIsPreviewOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const copyTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const previewQuery = usePortalResourcePreview(resource.id, basePath, isPreviewOpen);
    const authors = formatAuthors(resource.creators);
    const landingPageUrl = resource.landingPageUrl;
    const hasLandingPage = landingPageUrl !== null;

    useEffect(
        () => () => {
            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
        },
        [],
    );

    const handleOpenChange = (open: boolean) => {
        setIsPreviewOpen(open);
        if (!open) {
            setCopied(false);
        }
    };

    const handleCopyCitation = async () => {
        const citation = previewQuery.data?.citation.text;
        if (!citation) return;

        try {
            await navigator.clipboard.writeText(citation);
            setCopied(true);
            toast.success('Citation copied to clipboard');

            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
            copyTimeoutRef.current = setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
            toast.error('Failed to copy citation');
        }
    };

    const rowContent = (
        <>
            <Badge variant={getTypeBadgeVariant(resource.isIgsn)} className="shrink-0 text-xs">
                {resource.isIgsn ? 'IGSN' : resource.resourceType}
            </Badge>

            {resource.doi && (
                <span className="hidden shrink-0 font-mono text-xs text-muted-foreground sm:block sm:max-w-[180px] sm:truncate">{resource.doi}</span>
            )}

            <div className="flex min-w-0 flex-1 items-center gap-3">
                <span
                    data-testid="portal-result-title"
                    className={cn('min-w-0 flex-1 truncate text-sm font-medium', hasLandingPage && 'group-hover:text-primary')}
                >
                    {resource.title}
                </span>

                <div data-testid="portal-result-meta" className="flex shrink-0 items-center gap-2">
                    <span className="hidden max-w-[220px] truncate text-sm text-muted-foreground md:block">{authors}</span>
                    {resource.year && <span className="shrink-0 text-sm text-muted-foreground">{resource.year}</span>}
                </div>
            </div>
        </>
    );

    return (
        <Popover open={isPreviewOpen} onOpenChange={handleOpenChange}>
            <div
                data-slot="portal-result-card"
                className={cn(
                    'group flex min-w-0 items-center gap-1 rounded-md border bg-card p-1.5 transition-all duration-200',
                    hasLandingPage && 'hover:border-primary hover:bg-accent/50',
                )}
            >
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        className="shrink-0 text-muted-foreground hover:text-foreground"
                        aria-label={`Show citation and abstract for ${resource.title}`}
                    >
                        <Info aria-hidden="true" />
                    </Button>
                </PopoverTrigger>

                {hasLandingPage ? (
                    <a
                        href={landingPageUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`View ${resource.title} (opens in new tab)`}
                        className="flex min-w-0 flex-1 items-center gap-3 self-stretch rounded-sm px-1.5 py-0.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-none"
                    >
                        {rowContent}
                    </a>
                ) : (
                    <div className="flex min-w-0 flex-1 items-center gap-3 px-1.5 py-0.5">{rowContent}</div>
                )}
            </div>

            <PopoverContent
                side="right"
                align="start"
                collisionPadding={16}
                className="max-h-[min(70vh,32rem)] w-[min(32rem,calc(100vw-2rem))] overflow-y-auto"
                data-testid="portal-result-preview"
            >
                <div className="space-y-4">
                    <div>
                        <p className="text-sm leading-snug font-semibold text-foreground">{resource.title}</p>
                        <p className="mt-1 text-xs text-muted-foreground">Citation and abstract</p>
                    </div>

                    {previewQuery.isPending || (previewQuery.isFetching && !previewQuery.data) ? (
                        <div className="space-y-4" aria-label="Loading citation and abstract">
                            <div className="space-y-2">
                                <Skeleton className="h-3 w-20" />
                                <Skeleton className="h-4 w-full" />
                                <Skeleton className="h-4 w-4/5" />
                            </div>
                            <div className="space-y-2">
                                <Skeleton className="h-3 w-16" />
                                <Skeleton className="h-4 w-full" />
                                <Skeleton className="h-4 w-full" />
                                <Skeleton className="h-4 w-2/3" />
                            </div>
                        </div>
                    ) : previewQuery.isError ? (
                        <div role="alert" className="space-y-3 rounded-md border border-destructive/30 bg-destructive/5 p-3">
                            <p className="text-sm text-destructive">Citation and abstract could not be loaded.</p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void previewQuery.refetch()}
                                disabled={previewQuery.isFetching}
                            >
                                <RefreshCw className={cn(previewQuery.isFetching && 'animate-spin')} aria-hidden="true" />
                                Retry
                            </Button>
                        </div>
                    ) : previewQuery.data ? (
                        <>
                            <section aria-labelledby={`portal-citation-${resource.id}`} className="space-y-1.5">
                                <div className="flex items-center justify-between gap-3">
                                    <p
                                        id={`portal-citation-${resource.id}`}
                                        className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Citation ({previewQuery.data.citation.label})
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        onClick={() => void handleCopyCitation()}
                                        title={copied ? 'Copied!' : 'Copy citation'}
                                        aria-label="Copy citation to clipboard"
                                    >
                                        {copied ? (
                                            <Check className="text-green-600 dark:text-green-400" aria-hidden="true" />
                                        ) : (
                                            <Copy aria-hidden="true" />
                                        )}
                                    </Button>
                                </div>
                                <p className="text-sm leading-relaxed text-foreground" data-testid="portal-preview-citation">
                                    {previewQuery.data.citation.text}
                                </p>
                            </section>

                            <section aria-labelledby={`portal-abstract-${resource.id}`} className="space-y-1.5">
                                <p
                                    id={`portal-abstract-${resource.id}`}
                                    className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Abstract
                                </p>
                                {previewQuery.data.abstract ? (
                                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">{previewQuery.data.abstract}</p>
                                ) : (
                                    <p className="text-sm text-muted-foreground italic">No abstract is available for this resource.</p>
                                )}
                            </section>
                        </>
                    ) : null}

                    <span className="sr-only" role="status" aria-live="polite">
                        {copied ? 'Citation copied to clipboard' : ''}
                    </span>
                </div>
            </PopoverContent>
        </Popover>
    );
}
