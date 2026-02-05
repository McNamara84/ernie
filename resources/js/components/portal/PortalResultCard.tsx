import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
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
 * Single resource card for portal results.
 */
export function PortalResultCard({ resource }: PortalResultCardProps) {
    const authors = formatAuthors(resource.creators);
    const hasLandingPage = resource.landingPageUrl !== null;

    const cardContent = (
        <Card
            className={cn(
                'transition-all duration-200',
                hasLandingPage && 'cursor-pointer hover:border-primary hover:shadow-md',
            )}
        >
            <CardContent className="p-4">
                <div className="flex flex-col gap-2">
                    {/* Type Badge */}
                    <div className="flex items-start justify-between gap-2">
                        <Badge variant={getTypeBadgeVariant(resource.isIgsn)} className="shrink-0">
                            {resource.resourceType}
                        </Badge>
                        {resource.doi && (
                            <span className="truncate text-xs text-muted-foreground">{resource.doi}</span>
                        )}
                    </div>

                    {/* Title */}
                    <h3
                        className={cn(
                            'line-clamp-2 text-base font-semibold leading-tight',
                            hasLandingPage && 'group-hover:text-primary',
                        )}
                    >
                        {resource.title}
                    </h3>

                    {/* Authors & Year */}
                    <p className="text-sm text-muted-foreground">
                        {authors}
                        {resource.year && (
                            <>
                                <span className="mx-1.5">•</span>
                                {resource.year}
                            </>
                        )}
                    </p>
                </div>
            </CardContent>
        </Card>
    );

    if (hasLandingPage) {
        return (
            <a
                href={resource.landingPageUrl!}
                className="group block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-lg"
            >
                {cardContent}
            </a>
        );
    }

    return cardContent;
}
