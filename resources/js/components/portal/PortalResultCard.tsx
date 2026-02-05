import { Badge } from '@/components/ui/badge';
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

    const rowContent = (
        <div
            className={cn(
                'flex items-center gap-3 rounded-md border bg-card px-3 py-2 transition-all duration-200',
                hasLandingPage && 'cursor-pointer hover:border-primary hover:bg-accent/50',
            )}
        >
            {/* Type Badge */}
            <Badge variant={getTypeBadgeVariant(resource.isIgsn)} className="shrink-0 text-xs">
                {resource.isIgsn ? 'IGSN' : 'DOI'}
            </Badge>

            {/* DOI / IGSN identifier */}
            {resource.doi && (
                <span className="hidden shrink-0 font-mono text-xs text-muted-foreground sm:block sm:max-w-[180px] sm:truncate">
                    {resource.doi}
                </span>
            )}

            {/* Title - takes remaining space */}
            <span
                className={cn(
                    'min-w-0 flex-1 truncate text-sm font-medium',
                    hasLandingPage && 'group-hover:text-primary',
                )}
            >
                {resource.title}
            </span>

            {/* Authors */}
            <span className="hidden shrink-0 text-sm text-muted-foreground md:block lg:max-w-[200px] lg:truncate">
                {authors}
            </span>

            {/* Year */}
            {resource.year && (
                <span className="shrink-0 text-sm text-muted-foreground">{resource.year}</span>
            )}
        </div>
    );

    if (hasLandingPage) {
        return (
            <a
                href={resource.landingPageUrl!}
                target="_blank"
                rel="noopener noreferrer"
                className="group block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-md"
            >
                {rowContent}
            </a>
        );
    }

    return rowContent;
}
