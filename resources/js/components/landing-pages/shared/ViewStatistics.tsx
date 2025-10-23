import { Clock, Eye } from 'lucide-react';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * Props for ViewStatistics component
 */
interface ViewStatisticsProps {
    viewCount: number;
    lastViewedAt: string | null;
    heading?: string;
    showLastViewed?: boolean;
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Format view count with thousands separators
 * @example formatViewCount(1234) => "1,234"
 */
function formatViewCount(count: number): string {
    return count.toLocaleString('en-US');
}

/**
 * Format relative time (e.g., "2 hours ago", "3 days ago")
 */
function formatRelativeTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffSeconds = Math.floor(diffMs / 1000);
    const diffMinutes = Math.floor(diffSeconds / 60);
    const diffHours = Math.floor(diffMinutes / 60);
    const diffDays = Math.floor(diffHours / 24);
    const diffMonths = Math.floor(diffDays / 30);
    const diffYears = Math.floor(diffDays / 365);

    if (diffSeconds < 60) {
        return diffSeconds === 1 ? '1 second ago' : `${diffSeconds} seconds ago`;
    } else if (diffMinutes < 60) {
        return diffMinutes === 1 ? '1 minute ago' : `${diffMinutes} minutes ago`;
    } else if (diffHours < 24) {
        return diffHours === 1 ? '1 hour ago' : `${diffHours} hours ago`;
    } else if (diffDays < 30) {
        return diffDays === 1 ? '1 day ago' : `${diffDays} days ago`;
    } else if (diffMonths < 12) {
        return diffMonths === 1 ? '1 month ago' : `${diffMonths} months ago`;
    } else {
        return diffYears === 1 ? '1 year ago' : `${diffYears} years ago`;
    }
}

/**
 * Format full timestamp for title attribute
 * @example "October 23, 2025, 2:30 PM"
 */
function formatFullTimestamp(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

// ============================================================================
// Component
// ============================================================================

/**
 * ViewStatistics Component
 *
 * Displays view count and last viewed date for landing pages.
 * - View counter with formatted numbers (1,234)
 * - Relative time display ("2 hours ago")
 * - Full timestamp on hover
 * - Eye icon for views
 * - Clock icon for last viewed
 * - Accessible with proper ARIA labels
 * - Supports dark mode
 *
 * @example
 * ```tsx
 * <ViewStatistics
 *   viewCount={1234}
 *   lastViewedAt="2025-10-23T14:30:00Z"
 *   heading="Page Views"
 *   showLastViewed={true}
 * />
 * ```
 */
export default function ViewStatistics({
    viewCount,
    lastViewedAt,
    heading = 'Statistics',
    showLastViewed = true,
}: ViewStatisticsProps) {
    return (
        <div className="space-y-4">
            {/* Heading */}
            <h2 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h2>

            {/* Statistics Grid */}
            <div className="grid gap-4 sm:grid-cols-2">
                {/* View Count Card */}
                <div className="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div className="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                        <Eye className="size-5" aria-hidden="true" />
                        <span className="text-sm font-medium">Total Views</span>
                    </div>
                    <p
                        className="text-3xl font-bold text-gray-900 dark:text-gray-100"
                        aria-label={`${viewCount} total views`}
                    >
                        {formatViewCount(viewCount)}
                    </p>
                </div>

                {/* Last Viewed Card */}
                {showLastViewed && lastViewedAt && (
                    <div className="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div className="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                            <Clock className="size-5" aria-hidden="true" />
                            <span className="text-sm font-medium">Last Viewed</span>
                        </div>
                        <p
                            className="text-lg font-semibold text-gray-900 dark:text-gray-100"
                            title={formatFullTimestamp(lastViewedAt)}
                            aria-label={`Last viewed ${formatRelativeTime(lastViewedAt)}`}
                        >
                            {formatRelativeTime(lastViewedAt)}
                        </p>
                    </div>
                )}

                {/* No Views Yet Card (when viewCount is 0 and no lastViewedAt) */}
                {showLastViewed && !lastViewedAt && viewCount === 0 && (
                    <div className="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div className="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                            <Clock className="size-5" aria-hidden="true" />
                            <span className="text-sm font-medium">Last Viewed</span>
                        </div>
                        <p className="text-lg font-semibold text-gray-500 dark:text-gray-400">
                            Never viewed
                        </p>
                    </div>
                )}
            </div>

            {/* Help Text */}
            {viewCount > 0 && (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    View statistics are tracked automatically when visitors access this landing page.
                </p>
            )}

            {viewCount === 0 && (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    This landing page has not been viewed yet. Share the URL to start tracking views.
                </p>
            )}
        </div>
    );
}
