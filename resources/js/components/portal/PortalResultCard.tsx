import { Badge } from '@/components/ui/badge';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { cn } from '@/lib/utils';
import type { PortalCreator, PortalResource } from '@/types/portal';

interface PortalResultCardProps {
    resource: PortalResource;
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

    const formatName = (creator: PortalCreator): string => {
        return creator.name || 'Unknown';
    };

    if (creators.length === 1) {
        return formatName(creators[0]);
    }

    if (creators.length === 2) {
        return `${formatName(creators[0])} & ${formatName(creators[1])}`;
    }

    return `${formatName(creators[0])} et al.`;
}

function formatCreatorDisplayName(creator: PortalCreator): string {
    const name = creator.name || 'Unknown';

    if (creator.givenName && creator.givenName.trim() !== '') {
        return `${creator.givenName} ${name}`;
    }

    return name;
}

/**
 * Get badge variant based on resource type.
 */
function getTypeBadgeVariant(isIgsn: boolean): 'default' | 'secondary' | 'outline' {
    return isIgsn ? 'secondary' : 'default';
}

/**
 * Single-line resource row for portal results.
 */
export function PortalResultCard({ resource }: PortalResultCardProps) {
    const authors = formatAuthors(resource.creators);
    const hasLandingPage = resource.landingPageUrl !== null;
    const previewCreators = resource.creators.length > 0 ? resource.creators.map(formatCreatorDisplayName) : ['Unknown'];

    const rowContent = (
        <div
            className={cn(
                'flex min-w-0 items-center gap-3 rounded-md border bg-card px-3 py-2 transition-all duration-200',
                hasLandingPage && 'cursor-pointer hover:border-primary hover:bg-accent/50',
            )}
        >
            {/* Type Badge */}
            <Badge variant={getTypeBadgeVariant(resource.isIgsn)} className="shrink-0 text-xs">
                {resource.isIgsn ? 'IGSN' : resource.resourceType}
            </Badge>

            {/* DOI / IGSN identifier */}
            {resource.doi && (
                <span className="hidden shrink-0 font-mono text-xs text-muted-foreground sm:block sm:max-w-[180px] sm:truncate">
                    {resource.doi}
                </span>
            )}

            <div className="flex min-w-0 flex-1 items-center gap-3">
                {/* Title - takes remaining space and truncates first */}
                <span
                    data-testid="portal-result-title"
                    className={cn(
                        'min-w-0 flex-1 truncate text-sm font-medium',
                        hasLandingPage && 'group-hover:text-primary',
                    )}
                >
                    {resource.title}
                </span>

                <div data-testid="portal-result-meta" className="flex shrink-0 items-center gap-2">
                    {/* Authors */}
                    <span className="hidden max-w-[220px] truncate text-sm text-muted-foreground md:block">
                        {authors}
                    </span>

                    {/* Year */}
                    {resource.year && (
                        <span className="shrink-0 text-sm text-muted-foreground">{resource.year}</span>
                    )}
                </div>
            </div>
        </div>
    );

    if (hasLandingPage) {
        return (
            <HoverCard openDelay={0} closeDelay={0}>
                <HoverCardTrigger asChild>
                    <a
                        href={resource.landingPageUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`View ${resource.title} (opens in new tab)`}
                        className="group block rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        {rowContent}
                    </a>
                </HoverCardTrigger>
                <HoverCardContent
                    align="start"
                    className="w-[min(32rem,calc(100vw-2rem))] max-h-[min(70vh,32rem)] overflow-y-auto"
                >
                    <div className="space-y-3" data-testid="portal-result-preview">
                        <div className="space-y-1">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Title</p>
                            <p className="text-sm font-semibold leading-snug text-foreground">{resource.title}</p>
                        </div>

                        <div className="space-y-1">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Creators</p>
                            <p className="text-sm leading-snug text-foreground">{previewCreators.join(', ')}</p>
                        </div>

                        {resource.abstract && (
                            <div className="space-y-1">
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Abstract</p>
                                <p className="text-sm leading-relaxed text-muted-foreground">{resource.abstract}</p>
                            </div>
                        )}
                    </div>
                </HoverCardContent>
            </HoverCard>
        );
    }

    return rowContent;
}
