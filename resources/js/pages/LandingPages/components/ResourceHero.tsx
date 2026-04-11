import { Check, Copy, FlaskConical } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';

import { useFadeInOnScroll } from '../hooks/useFadeInOnScroll';
import { getResourceTypeIcon } from './ResourceTypeIcons';
import { getStatusConfig } from './StatusConfig';

interface ResourceHeroProps {
    resourceType: string;
    status: string;
    mainTitle: string;
    subtitle?: string;
    citation: string;
    /** Use FlaskConical icon for IGSN instead of resource type icon */
    useIgsnIcon?: boolean;
}

export function ResourceHero({ resourceType, status, mainTitle, subtitle, citation, useIgsnIcon = false }: ResourceHeroProps) {
    const [copied, setCopied] = useState(false);
    const copyTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const { ref, isVisible } = useFadeInOnScroll();

    // Use FlaskConical for IGSN, otherwise use resource type icon
    const ResourceTypeIcon = useIgsnIcon ? FlaskConical : getResourceTypeIcon(resourceType);
    const statusConfig = getStatusConfig(status);
    const StatusIcon = statusConfig.icon;

    // Clear pending timeout on unmount
    useEffect(() => {
        return () => {
            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
        };
    }, []);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(citation);
            setCopied(true);
            toast.success('Citation copied to clipboard');
            // Clear any existing timeout before scheduling a new one
            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
            copyTimeoutRef.current = setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Failed to copy citation');
        }
    };

    return (
        <section
            ref={ref}
            aria-labelledby="heading-title"
            inert={!isVisible || undefined}
            className={`mx-8 my-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-all duration-200 ease-in-out hover:shadow-md dark:border-gray-700 dark:bg-gray-800 ${isVisible ? 'opacity-100' : 'opacity-0'}`}
        >
            {/* Top Row: Resource Type, Title, Status */}
            <div className="mb-6 flex items-start justify-between gap-4">
                {/* Left: Resource Type */}
                <div className="flex flex-col items-center gap-1.5">
                    <ResourceTypeIcon className="h-8 w-8 text-gray-700 dark:text-gray-300" strokeWidth={1.5} />
                    <span className="text-center text-xs text-gray-600 dark:text-gray-400">{resourceType}</span>
                </div>

                {/* Center: Title + Subtitle */}
                <div className="flex-1 space-y-1 text-center">
                    <h1 id="heading-title" className="text-xl leading-tight font-bold text-gray-900 dark:text-gray-100">{mainTitle}</h1>
                    {subtitle && <p className="text-base font-normal text-gray-600 italic dark:text-gray-400">{subtitle}</p>}
                </div>

                {/* Right: Status */}
                <div className="flex flex-col items-center gap-1.5">
                    <StatusIcon className={`h-8 w-8 ${statusConfig.color}`} strokeWidth={1.5} />
                    <span className={`text-center text-xs font-medium ${statusConfig.textColor}`}>{statusConfig.label}</span>
                </div>
            </div>

            {/* Bottom: Citation */}
            <div className="border-t border-gray-200 pt-4 dark:border-gray-700">
                {statusConfig.reviewLabel && (
                    <p className={`mb-2 text-sm font-semibold ${statusConfig.textColor}`}>{statusConfig.reviewLabel}</p>
                )}
                <div className="flex items-start gap-3">
                    <p className="flex-1 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{citation}</p>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={handleCopy}
                        className="min-h-11 min-w-11 shrink-0"
                        title={copied ? 'Copied!' : 'Copy citation'}
                        aria-label="Copy citation to clipboard"
                    >
                        {copied ? (
                            <Check className="h-4 w-4 text-green-600 dark:text-green-400" />
                        ) : (
                            <Copy className="h-4 w-4 text-gray-600 dark:text-gray-400" />
                        )}
                    </Button>
                </div>
                {/* Screen reader announcement for copy action */}
                <span className="sr-only" aria-live="polite" role="status">
                    {copied ? 'Citation copied to clipboard' : ''}
                </span>
            </div>
        </section>
    );
}
